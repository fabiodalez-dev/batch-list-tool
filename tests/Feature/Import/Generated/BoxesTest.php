<?php

declare(strict_types=1);

use App\Filament\Imports\BoxImporter;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxSealNumberHistory;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use HayderHatem\FilamentExcelImport\Actions\Imports\Jobs\ImportExcel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;

/**
 * Boxes importer (BoxImporter) — real client template + dirty-DB streaming
 * tests, driven through the ACTUAL hayderhatem ImportExcel job (the
 * "Import Excel/CSV" button the client clicks), exactly like
 * DirtyDatabaseImportTest.php.
 *
 * Focus: barcode conditional (required only for RAS), the batch_number FK
 * lookup, barcode_status values, destroyed/PERM_OUT handling and numeric
 * box_number coercion.
 */
uses(RefreshDatabase::class);

const BXT_SAMPLE = __DIR__ . '/../../../../nra/outbox/2026-07-22_NAF_import_examples/example_box_import.xlsx';

function bxt_admin(?int $repoId = null): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repoId]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * Run the real streaming ImportExcel job for the given rows.
 *
 * @param array<int, array<string, mixed>> $rows keyed by Excel header
 * @param array<string, string> $columnMap importerField => Excel header
 * @param array<string, mixed> $options
 */
function bxt_run(array $rows, array $columnMap, int $userId, array $options = []): Import
{
    EntityResolver::flushMemo();
    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'boxes.xlsx',
        'file_path' => '/tmp/boxes.xlsx',
        'importer' => BoxImporter::class,
        'processed_rows' => 0,
        'total_rows' => count($rows),
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);

    $job = new ImportExcel(
        importId: $import->getKey(),
        rows: base64_encode(serialize($rows)),
        startRow: null,
        endRow: null,
        columnMap: $columnMap,
        options: $options,
    );
    $job->handle();

    return $import->refresh();
}

/** @return array<int, string> the human validation_error messages of the failed rows */
function bxt_failures(Import $import): array
{
    return $import->failedRows()->pluck('validation_error')->all();
}

/**
 * Read the real client-facing template ("Data" sheet) into header-keyed rows,
 * exactly as the wizard/streaming path receives them from PhpSpreadsheet.
 *
 * @return array{0: list<string>, 1: list<array<string, mixed>>}
 */
function bxt_readSample(): array
{
    $reader = IOFactory::createReaderForFile(BXT_SAMPLE);
    $reader->setReadDataOnly(true);
    $sheet = $reader->load(BXT_SAMPLE)->getSheetByName('Data');
    $raw = array_values(array_filter(
        $sheet->toArray(null, true, false, false),
        fn (array $r): bool => array_filter($r, fn ($c) => $c !== null && $c !== '') !== [],
    ));

    $headers = array_map(fn ($h): string => (string) $h, $raw[0]);
    $rows = [];
    foreach (array_slice($raw, 1) as $r) {
        $row = [];
        foreach ($headers as $i => $h) {
            $row[$h] = $r[$i] ?? null;
        }
        $rows[] = $row;
    }

    return [$headers, $rows];
}

// ═══════════════════════════════════════════════════════════════════════
// A — the real client template: box_type, box_number, batch_number,
//     parent_box_number, barcode, barcode_status, disinfestation_date,
//     is_legacy, notes, Seal Number, Location
// ═══════════════════════════════════════════════════════════════════════

test('the real template exists with the expected headers (sanity)', function () {
    expect(is_file(BXT_SAMPLE))->toBeTrue();

    [$headers] = bxt_readSample();

    expect($headers)->toContain('box_type')
        ->and($headers)->toContain('box_number')
        ->and($headers)->toContain('batch_number')
        ->and($headers)->toContain('parent_box_number')
        ->and($headers)->toContain('barcode');
});

test('the real template column "parent_box_number" auto-guesses onto the parent_barcode importer field', function () {
    // Mirrors the wizard's own guessing algorithm (RealSampleImportTest::rsi_columnMap).
    [$headers] = bxt_readSample();
    $normalise = fn (string $s): string => strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $s) ?? '', '_'));

    $map = [];
    foreach (BoxImporter::getColumns() as $column) {
        $candidates = array_map($normalise, array_filter([
            $column->getName(),
            $column->getLabel(),
            ...$column->getGuesses(),
        ]));
        foreach ($headers as $header) {
            if ($header !== '' && in_array($normalise($header), $candidates, true)) {
                $map[$column->getName()] = $header;
                break;
            }
        }
    }

    // "parent_box_number" now lands on parent_barcode so the app's own template
    // round-trips (feedback #8) instead of silently dropping the parent link.
    expect($map)->toHaveKey('parent_barcode')
        ->and($map['parent_barcode'])->toBe('parent_box_number');
});

test('the real template parent_box_number ("1") resolves the parent RAS box by its box number and imports the child', function () {
    $repo = Repository::factory()->create(['code' => 'BXA']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 46, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);
    Location::withoutGlobalScope(RepositoryScope::class)->create([
        'name' => 'Archive Room 1', 'code' => 'AR1', 'type' => 'room', 'is_active' => true, 'repository_id' => $repo->id, 'parent_id' => null,
    ]);

    [, $rows] = bxt_readSample();
    // Row 0 is the RAS parent (box_number "1", barcode "AC54609").
    // Row 1 is the NRA child, whose "parent_box_number" cell is "1" — the
    // RAS row's box_number, exactly as the client template demonstrates.
    expect($rows[0]['box_number'])->toBe('1')
        ->and($rows[1]['parent_box_number'])->toBe('1');

    // Map "Location" to the valid location code so the child (which requires a
    // location) does not fail on that separate column.
    $rows[1]['Location'] = 'AR1';

    $columnMap = [
        'box_type' => 'box_type',
        'box_number' => 'box_number',
        'batch_number' => 'batch_number',
        'barcode' => 'barcode',
        'barcode_status' => 'barcode_status',
        'parent_barcode' => 'parent_box_number', // the client's own column, holding the parent's box number
        'location' => 'Location',
    ];

    $import = bxt_run($rows, $columnMap, $u->id);

    // Both rows import: the child resolves its parent by RAS box number "1".
    expect(bxt_failures($import))->toBe([]);

    $parent = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', 'AC54609')->first();
    $child = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('box_number', 'NRA1')->first();
    expect($parent)->not->toBeNull()
        ->and($child)->not->toBeNull()
        ->and($child->parent_box_id)->toBe($parent->id);
});

test('an AMBIGUOUS parent box number (same RAS number in two batches) fails with a clear message instead of guessing', function () {
    $repo = Repository::factory()->create(['code' => 'BXAMB']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    // Two batches in the same repo, each with a RAS box numbered "1".
    foreach ([46, 47] as $n) {
        $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
            'batch_number' => $n, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
        ]);
        Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create([
            'box_type' => 'RAS', 'box_number' => '1', 'batch_id' => $batch->id, 'barcode' => "BC-{$n}",
        ]);
    }
    Location::withoutGlobalScope(RepositoryScope::class)->create([
        'name' => 'Room', 'code' => 'AR1', 'type' => 'room', 'is_active' => true, 'repository_id' => $repo->id, 'parent_id' => null,
    ]);

    // An NRA child whose parent_box_number "1" now matches TWO RAS boxes.
    $rows = [[
        'box_type' => 'NRA', 'box_number' => 'NRA9', 'batch_number' => '46',
        'barcode' => '', 'barcode_status' => '', 'parent_box_number' => '1', 'Location' => 'AR1',
    ]];
    $columnMap = [
        'box_type' => 'box_type', 'box_number' => 'box_number', 'batch_number' => 'batch_number',
        'barcode' => 'barcode', 'barcode_status' => 'barcode_status',
        'parent_barcode' => 'parent_box_number', 'location' => 'Location',
    ];

    $import = bxt_run($rows, $columnMap, $u->id);

    $failures = bxt_failures($import);
    expect($failures)->toHaveCount(1)
        ->and(strtolower($failures[0]))->toContain('ambiguous');
    expect(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('box_number', 'NRA9')->exists())->toBeFalse();
});

test('control: mapping the ACTUAL barcode as the parent reference resolves and imports the real template rows', function () {
    $repo = Repository::factory()->create(['code' => 'BXB']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 46, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);
    Location::withoutGlobalScope(RepositoryScope::class)->create([
        'name' => 'Archive Room 1', 'code' => 'AR1', 'type' => 'room', 'is_active' => true, 'repository_id' => $repo->id, 'parent_id' => null,
    ]);

    [, $rows] = bxt_readSample();
    // Rewrite row 2's parent reference to the RAS row's real barcode — the
    // only value the importer can actually resolve a parent from.
    $rows[1]['parent_box_number'] = 'AC54609';
    $rows[1]['Location'] = 'AR1';

    $columnMap = [
        'box_type' => 'box_type',
        'box_number' => 'box_number',
        'batch_number' => 'batch_number',
        'barcode' => 'barcode',
        'barcode_status' => 'barcode_status',
        'parent_barcode' => 'parent_box_number',
        'location' => 'Location',
    ];

    $import = bxt_run($rows, $columnMap, $u->id);

    expect(bxt_failures($import))->toBe([]);
    $child = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('box_number', 'NRA1')->first();
    $parent = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', 'AC54609')->first();
    expect($child)->not->toBeNull()
        ->and($parent)->not->toBeNull()
        ->and($child->parent_box_id)->toBe($parent->id);
});

// ═══════════════════════════════════════════════════════════════════════
// B — batch_number FK: must pre-exist. BUG: an unresolved batch_number
//     silently leaves batch_id NULL instead of failing the row.
//
// All rows below are the real template's RAS row (box_number "1", barcode
// "AC54609", batch_number "46"), with only the one cell the edge case
// needs mutated.
// ═══════════════════════════════════════════════════════════════════════

test('BUG: a batch_number with no matching Batch silently inserts the box with batch_id=NULL (no failure reported)', function () {
    $repo = Repository::factory()->create(['code' => 'BXC']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);
    // Deliberately do NOT create batch 999 — this is the dirty/real-world
    // scenario of a typo'd or not-yet-created batch number.

    [, $rows] = bxt_readSample();
    $row = $rows[0];
    $row['batch_number'] = '999';

    $import = bxt_run(
        [$row],
        ['box_number' => 'box_number', 'box_type' => 'box_type', 'batch_number' => 'batch_number', 'barcode' => 'barcode'],
        $u->id,
    );

    // BoxImporter::getStaticColumns() batch_number closure comment claims:
    // "Box requires the batch FK to satisfy NOT NULL on insert, so leave the
    // column empty and let the resulting SQL constraint failure surface" —
    // but boxes.batch_id is NULLABLE at the DB level (create_boxes_table
    // migration: ->nullable()->constrained()->nullOnDelete()), so no SQL
    // constraint ever fires and the row silently succeeds with no batch link.
    expect(bxt_failures($import))->toBe([]);
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', 'AC54609')->first();
    expect($box)->not->toBeNull()
        ->and($box->batch_id)->toBeNull();
});

test('BUG: a FORBIDDEN batch_number (34/36) also silently inserts the box with batch_id=NULL', function () {
    $repo = Repository::factory()->create(['code' => 'BXD']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    expect(Batch::FORBIDDEN_NUMBERS)->toContain(34);

    [, $rows] = bxt_readSample();
    $row = $rows[0];
    $row['batch_number'] = '34';

    $import = bxt_run(
        [$row],
        ['box_number' => 'box_number', 'box_type' => 'box_type', 'batch_number' => 'batch_number', 'barcode' => 'barcode'],
        $u->id,
    );

    expect(bxt_failures($import))->toBe([]);
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', 'AC54609')->first();
    expect($box)->not->toBeNull()
        ->and($box->batch_id)->toBeNull();
});

test('control: an existing batch_number resolves to the correct batch_id', function () {
    $repo = Repository::factory()->create(['code' => 'BXE']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 46, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);

    [, $rows] = bxt_readSample();

    $import = bxt_run(
        [$rows[0]],
        ['box_number' => 'box_number', 'box_type' => 'box_type', 'batch_number' => 'batch_number', 'barcode' => 'barcode'],
        $u->id,
    );

    expect(bxt_failures($import))->toBe([]);
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', 'AC54609')->first();
    expect($box->batch_id)->toBe($batch->id);
});

test('a batch_number belonging to a DIFFERENT repository does not link (tenant scoping) and also silently nulls batch_id', function () {
    $repoA = Repository::factory()->create(['code' => 'BXF1']);
    $repoB = Repository::factory()->create(['code' => 'BXF2']);
    $u = bxt_admin($repoA->id);
    $this->actingAs($u);

    // Batch 46 exists, but in repository B, not the importing user's repo A.
    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 46, 'repository_id' => $repoB->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);

    [, $rows] = bxt_readSample();

    $import = bxt_run(
        [$rows[0]],
        ['box_number' => 'box_number', 'box_type' => 'box_type', 'batch_number' => 'batch_number', 'barcode' => 'barcode'],
        $u->id,
    );

    expect(bxt_failures($import))->toBe([]);
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', 'AC54609')->first();
    expect($box)->not->toBeNull()
        ->and($box->batch_id)->toBeNull();
});

test('batch_number arriving as a float from Excel (46.0) resolves the same as the integer', function () {
    $repo = Repository::factory()->create(['code' => 'BXG']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 46, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);

    [, $rows] = bxt_readSample();
    $row = $rows[0];
    $row['batch_number'] = 46.0; // Excel numeric cell, not the string "46"

    $import = bxt_run(
        [$row],
        ['box_number' => 'box_number', 'box_type' => 'box_type', 'batch_number' => 'batch_number', 'barcode' => 'barcode'],
        $u->id,
    );

    expect(bxt_failures($import))->toBe([]);
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', 'AC54609')->first();
    expect($box->batch_id)->toBe($batch->id);
});

// ═══════════════════════════════════════════════════════════════════════
// C — barcode conditional: required only for RAS, not IN_SITU/NRA/MAV/STVC
// ═══════════════════════════════════════════════════════════════════════

test('a RAS box without a barcode now imports (barcode optional — client feedback 2026-08-01)', function () {
    $repo = Repository::factory()->create(['code' => 'BXH']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 46, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);

    [, $rows] = bxt_readSample();
    $row = $rows[0]; // real RAS row, with the one barcode cell blanked out
    $row['barcode'] = '';

    // Barcode is no longer required for RAS: some legacy boxes lost the barcode
    // trail. The row imports with a null barcode.
    $import = bxt_run(
        [$row],
        ['box_number' => 'box_number', 'box_type' => 'box_type', 'batch_number' => 'batch_number', 'barcode' => 'barcode'],
        $u->id,
    );

    expect(bxt_failures($import))->toBe([]);
    expect(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('box_number', '1')->whereNull('barcode')->exists())->toBeTrue();
});

test('a RAS box WITH a barcode imports successfully', function () {
    $repo = Repository::factory()->create(['code' => 'BXI']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 46, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);

    [, $rows] = bxt_readSample();

    $import = bxt_run(
        [$rows[0]],
        ['box_number' => 'box_number', 'box_type' => 'box_type', 'batch_number' => 'batch_number', 'barcode' => 'barcode'],
        $u->id,
    );

    expect(bxt_failures($import))->toBe([]);
    expect(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', 'AC54609')->exists())->toBeTrue();
});

test('an NRA box without a barcode imports successfully when it has a parent + location', function () {
    $repo = Repository::factory()->create(['code' => 'BXJ']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 1, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);
    $parent = Box::factory()->create(['batch_id' => $batch->id, 'barcode' => 'BC-PARENT-1', 'box_number' => 'RAS-1', 'box_type' => 'RAS']);
    Location::withoutGlobalScope(RepositoryScope::class)->create([
        'name' => 'Room X', 'code' => 'RX', 'type' => 'room', 'is_active' => true, 'repository_id' => $repo->id, 'parent_id' => null,
    ]);

    [, $rows] = bxt_readSample();
    // Row 1 is the real template's NRA row (box_number "NRA1", no barcode).
    // Its real "parent_box_number" cell holds "1" (the RAS row's box_number,
    // not a barcode — see the BUG test above), so it is rewritten here to
    // the only reference the importer can actually resolve: the parent's
    // barcode. "Location" is likewise rewritten from the sample's free-text
    // "Archive Room 1" to a real location code.
    $row = $rows[1];
    $row['batch_number'] = (string) $batch->batch_number;
    $row['parent_box_number'] = 'BC-PARENT-1';
    $row['Location'] = 'RX';

    $import = bxt_run(
        [$row],
        ['box_number' => 'box_number', 'box_type' => 'box_type', 'batch_number' => 'batch_number', 'parent_barcode' => 'parent_box_number', 'location' => 'Location'],
        $u->id,
    );

    expect(bxt_failures($import))->toBe([]);
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('box_number', 'NRA1')->first();
    expect($box)->not->toBeNull()
        ->and($box->barcode)->toBeNull()
        ->and($box->parent_box_id)->toBe($parent->id);
});

test('an IN_SITU box without a barcode imports successfully when it has a parent + location', function () {
    $repo = Repository::factory()->create(['code' => 'BXK']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 1, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);
    $parent = Box::factory()->create(['batch_id' => $batch->id, 'barcode' => 'BC-PARENT-2', 'box_number' => 'RAS-2', 'box_type' => 'RAS']);
    Location::withoutGlobalScope(RepositoryScope::class)->create([
        'name' => 'Room Y', 'code' => 'RY', 'type' => 'room', 'is_active' => true, 'repository_id' => $repo->id, 'parent_id' => null,
    ]);

    // The real template has no IN_SITU row, so start from its NRA row and
    // mutate only the box_type cell to exercise this box type — plus the
    // same parent/location rewrite as the NRA test above.
    [, $rows] = bxt_readSample();
    $row = $rows[1];
    $row['box_type'] = 'IN_SITU';
    $row['batch_number'] = (string) $batch->batch_number;
    $row['parent_box_number'] = 'BC-PARENT-2';
    $row['Location'] = 'RY';

    $import = bxt_run(
        [$row],
        ['box_number' => 'box_number', 'box_type' => 'box_type', 'batch_number' => 'batch_number', 'parent_barcode' => 'parent_box_number', 'location' => 'Location'],
        $u->id,
    );

    expect(bxt_failures($import))->toBe([]);
    expect(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('box_number', 'NRA1')->where('barcode', null)->exists())->toBeTrue();
});

test('a MAV box (legacy) without a barcode and without a parent imports successfully', function () {
    $repo = Repository::factory()->create(['code' => 'BXL']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 1, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);

    // The real template has no MAV row, so start from its NRA row (already
    // barcode-less and parent-less once parent_barcode/barcode are simply
    // not mapped) and mutate only the box_type + batch_number cells.
    [, $rows] = bxt_readSample();
    $row = $rows[1];
    $row['box_type'] = 'MAV';
    $row['batch_number'] = (string) $batch->batch_number;

    $import = bxt_run(
        [$row],
        ['box_number' => 'box_number', 'box_type' => 'box_type', 'batch_number' => 'batch_number'],
        $u->id,
    );

    expect(bxt_failures($import))->toBe([]);
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('box_number', 'NRA1')->first();
    expect($box)->not->toBeNull()
        ->and($box->is_legacy)->toBeTrue()
        ->and($box->barcode)->toBeNull();
});

test('an STVC box (legacy) without a barcode and without a parent imports successfully', function () {
    $repo = Repository::factory()->create(['code' => 'BXM']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 1, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);

    // The real template has no STVC row either — same NRA-row base, only
    // box_type + batch_number mutated.
    [, $rows] = bxt_readSample();
    $row = $rows[1];
    $row['box_type'] = 'STVC';
    $row['batch_number'] = (string) $batch->batch_number;

    $import = bxt_run(
        [$row],
        ['box_number' => 'box_number', 'box_type' => 'box_type', 'batch_number' => 'batch_number'],
        $u->id,
    );

    expect(bxt_failures($import))->toBe([]);
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('box_number', 'NRA1')->first();
    expect($box)->not->toBeNull()
        ->and($box->is_legacy)->toBeTrue();
});

// ═══════════════════════════════════════════════════════════════════════
// D — barcode_status values. BUG: an unrecognised value is silently
//     coerced to NULL instead of being rejected.
// ═══════════════════════════════════════════════════════════════════════

test('barcode_status "IN" imports and is stored verbatim', function () {
    $repo = Repository::factory()->create(['code' => 'BXN']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 46, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);

    // The real RAS row's barcode_status cell is already "IN" — no mutation
    // needed.
    [, $rows] = bxt_readSample();

    $import = bxt_run(
        [$rows[0]],
        ['box_number' => 'box_number', 'box_type' => 'box_type', 'batch_number' => 'batch_number', 'barcode' => 'barcode', 'barcode_status' => 'barcode_status'],
        $u->id,
    );

    expect(bxt_failures($import))->toBe([]);
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', 'AC54609')->first();
    expect($box->barcode_status)->toBe('IN');
});

test('an unrecognised barcode_status value fails with a clear invalid-value error (not the old misleading "required missing")', function () {
    $repo = Repository::factory()->create(['code' => 'BXO']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 46, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);

    // A realistic operator typo/free-text value that is NOT one of IN/OUT/PERM_OUT.
    [, $rows] = bxt_readSample();
    $row = $rows[0];
    $row['barcode_status'] = 'OUT FOR REPAIR';

    $import = bxt_run(
        [$row],
        ['box_number' => 'box_number', 'box_type' => 'box_type', 'batch_number' => 'batch_number', 'barcode' => 'barcode', 'barcode_status' => 'barcode_status'],
        $u->id,
    );

    // The cast now KEEPS a non-empty, non-matching value (only a truly blank
    // cell is nulled → defaulted to IN), so the ->rules(['nullable','in:...'])
    // rejects "OUT FOR REPAIR" with a clear invalid-value message instead of the
    // old misleading "required value missing" (the row was previously nulled and
    // failed at the DB NOT NULL constraint).
    $failures = bxt_failures($import);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->not->toContain('generic_validation')
        ->and(strtolower($failures[0]))->not->toContain('required value is missing')
        ->and(strtolower($failures[0]))->toContain('barcode status');
    expect(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', 'AC54609')->exists())->toBeFalse();
});

test('barcode_status PERM_OUT without a disinfestation_date now imports (RFQ #5 loosened — client feedback 2026-08-01)', function () {
    $repo = Repository::factory()->create(['code' => 'BXP']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 46, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);

    // The real RAS row's disinfestation_date cell is already blank — only
    // barcode_status needs mutating to PERM_OUT.
    [, $rows] = bxt_readSample();
    $row = $rows[0];
    $row['barcode_status'] = 'PERM_OUT';

    $import = bxt_run(
        [$row],
        ['box_number' => 'box_number', 'box_type' => 'box_type', 'batch_number' => 'batch_number', 'barcode' => 'barcode', 'barcode_status' => 'barcode_status'],
        $u->id,
    );

    expect(bxt_failures($import))->toBe([]);
    expect(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->where('barcode', 'AC54609')->where('barcode_status', 'PERM_OUT')->whereNull('disinfestation_date')->exists())->toBeTrue();
});

test('barcode_status PERM_OUT with no Location now imports (location no longer required — client feedback 2026-08-01)', function () {
    $repo = Repository::factory()->create(['code' => 'BXQ']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 46, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);

    [, $rows] = bxt_readSample();
    $row = $rows[0];
    $row['barcode_status'] = 'PERM_OUT';
    // Location cell is left blank, as in the real row.

    $import = bxt_run(
        [$row],
        ['box_number' => 'box_number', 'box_type' => 'box_type', 'batch_number' => 'batch_number', 'barcode' => 'barcode', 'barcode_status' => 'barcode_status'],
        $u->id,
    );

    expect(bxt_failures($import))->toBe([]);
    expect(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->where('barcode', 'AC54609')->where('barcode_status', 'PERM_OUT')->whereNull('location_id')->exists())->toBeTrue();
});

test('barcode_status PERM_OUT with disinfestation_date AND Location imports successfully', function () {
    $repo = Repository::factory()->create(['code' => 'BXR']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 46, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);
    Location::withoutGlobalScope(RepositoryScope::class)->create([
        'name' => 'NRA Vault', 'code' => 'NRAV', 'type' => 'room', 'is_active' => true, 'repository_id' => $repo->id, 'parent_id' => null,
    ]);

    [, $rows] = bxt_readSample();
    $row = $rows[0];
    $row['barcode_status'] = 'PERM_OUT';
    $row['disinfestation_date'] = '2026-01-15';
    $row['Location'] = 'NRAV';

    $import = bxt_run(
        [$row],
        ['box_number' => 'box_number', 'box_type' => 'box_type', 'batch_number' => 'batch_number', 'barcode' => 'barcode', 'barcode_status' => 'barcode_status', 'disinfestation_date' => 'disinfestation_date', 'location' => 'Location'],
        $u->id,
    );

    expect(bxt_failures($import))->toBe([]);
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', 'AC54609')->first();
    expect($box)->not->toBeNull()
        ->and($box->barcode_status)->toBe('PERM_OUT')
        ->and($box->disinfestation_date?->format('Y-m-d'))->toBe('2026-01-15')
        ->and($box->location_id)->not->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════════
// E — numeric box_number / batch_number coercion
// ═══════════════════════════════════════════════════════════════════════

test('a purely numeric box_number cell (Excel int) imports as its string form', function () {
    $repo = Repository::factory()->create(['code' => 'BXS']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 46, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);

    [, $rows] = bxt_readSample();
    $row = $rows[0];
    $row['box_number'] = 100; // Excel numeric cell, not the string "1"

    $import = bxt_run(
        [$row],
        ['box_number' => 'box_number', 'box_type' => 'box_type', 'batch_number' => 'batch_number', 'barcode' => 'barcode'],
        $u->id,
    );

    expect(bxt_failures($import))->toBe([]);
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', 'AC54609')->first();
    expect($box->box_number)->toBe('100');
});

test('duplicate box_number values across different batches do not collide (only barcode is unique)', function () {
    $repo = Repository::factory()->create(['code' => 'BXT']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 46, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);
    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 47, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);

    // Two copies of the real RAS row (box_number "1" in both, matching the
    // template verbatim), each sent to a different batch/barcode — the one
    // pair of cells this edge case needs mutated on the second copy.
    [, $rows] = bxt_readSample();
    $rowA = $rows[0];
    $rowB = $rows[0];
    $rowB['batch_number'] = '47';
    $rowB['barcode'] = 'BC-DUP-B';

    $import = bxt_run(
        [$rowA, $rowB],
        ['box_number' => 'box_number', 'box_type' => 'box_type', 'batch_number' => 'batch_number', 'barcode' => 'barcode'],
        $u->id,
    );

    expect(bxt_failures($import))->toBe([]);
    expect(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('box_number', '1')->count())->toBe(2);
});

// ═══════════════════════════════════════════════════════════════════════
// F — seal_number / Location columns (F05 feedback columns)
// ═══════════════════════════════════════════════════════════════════════

test('seal_number imports and records a seal-number history entry', function () {
    $repo = Repository::factory()->create(['code' => 'BXU']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 46, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);

    // The real RAS row's "Seal Number" cell is already "S-1001" — no
    // mutation needed.
    [, $rows] = bxt_readSample();

    $import = bxt_run(
        [$rows[0]],
        ['box_number' => 'box_number', 'box_type' => 'box_type', 'batch_number' => 'batch_number', 'barcode' => 'barcode', 'seal_number' => 'Seal Number'],
        $u->id,
    );

    expect(bxt_failures($import))->toBe([]);
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', 'AC54609')->first();
    expect($box->seal_number)->toBe('S-1001');
    expect(BoxSealNumberHistory::where('box_id', $box->id)->where('new_value', 'S-1001')->exists())->toBeTrue();
});

test('an unknown Location code fails validation with a clear "unknown location" reason', function () {
    $repo = Repository::factory()->create(['code' => 'BXV']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 1, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);

    // The real template's own NRA row's "Location" cell is the free-text
    // "Archive Room 1" — a genuinely unresolvable value (not a location
    // `code`), exactly the scenario the "BUG: mapping parent_box_number"
    // test above documents. Only batch_number is mutated (Importer requires
    // it to be mapped for new records); Location is used verbatim.
    [, $rows] = bxt_readSample();
    $row = $rows[1];
    $row['batch_number'] = (string) $batch->batch_number;

    $import = bxt_run(
        [$row],
        ['box_number' => 'box_number', 'box_type' => 'box_type', 'batch_number' => 'batch_number', 'barcode' => 'barcode', 'location' => 'Location'],
        $u->id,
    );

    $failures = bxt_failures($import);
    expect($failures)->toHaveCount(1)
        ->and(strtolower($failures[0]))->toContain('unknown location');
    expect(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('box_number', 'NRA1')->exists())->toBeFalse();
});

// ═══════════════════════════════════════════════════════════════════════
// G — destroyed_at / destroyed_reason: not exposed via import at all
// ═══════════════════════════════════════════════════════════════════════

test('the importer has no destroyed_at/destroyed_reason column: such a header maps to nothing and has no effect', function () {
    $repo = Repository::factory()->create(['code' => 'BXW']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 46, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);

    $names = array_map(fn ($c) => $c->getName(), BoxImporter::getColumns());
    expect($names)->not->toContain('destroyed_at')
        ->and($names)->not->toContain('destroyed_reason');

    // No columnMap entry exists for destroyed_at, so it is simply impossible
    // to map — the row imports as a normal, non-destroyed box.
    [, $rows] = bxt_readSample();

    $import = bxt_run(
        [$rows[0]],
        ['box_number' => 'box_number', 'box_type' => 'box_type', 'batch_number' => 'batch_number', 'barcode' => 'barcode'],
        $u->id,
    );

    expect(bxt_failures($import))->toBe([]);
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', 'AC54609')->first();
    expect($box->destroyed_at)->toBeNull();
});

// ═══════════════════════════════════════════════════════════════════════
// H — dirty DB: soft-deleted box residue mixed with the real template
// ═══════════════════════════════════════════════════════════════════════

test('re-importing the real template RAS row over a soft-deleted box with the same barcode restores it (dirty DB)', function () {
    $repo = Repository::factory()->create(['code' => 'BXX']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 46, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);
    Box::factory()->create(['batch_id' => $batch->id, 'barcode' => 'AC54609', 'box_number' => '1', 'box_type' => 'RAS'])->delete();

    [, $rows] = bxt_readSample();
    $ras = $rows[0];
    expect($ras['barcode'])->toBe('AC54609');

    $import = bxt_run(
        [$ras],
        ['box_number' => 'box_number', 'box_type' => 'box_type', 'batch_number' => 'batch_number', 'barcode' => 'barcode', 'barcode_status' => 'barcode_status'],
        $u->id,
    );

    expect(bxt_failures($import))->toBe([]);
    $all = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->withTrashed()->where('barcode', 'AC54609')->get();
    expect($all)->toHaveCount(1)
        ->and($all->first()->trashed())->toBeFalse();
});

test('a numeric barcode of a NON-RAS box does not hijack the parent link — the RAS box NUMBER wins', function () {
    $repo = Repository::factory()->create(['code' => 'BXCOL']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => '46', 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);
    Location::withoutGlobalScope(RepositoryScope::class)->create([
        'name' => 'Room', 'code' => 'AR1', 'type' => 'room', 'is_active' => true, 'repository_id' => $repo->id, 'parent_id' => null,
    ]);

    // The real parent: a RAS box numbered "1" (barcode "RASBC").
    $ras = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create([
        'box_type' => 'RAS', 'box_number' => '1', 'batch_id' => $batch->id, 'barcode' => 'RASBC',
    ]);
    // A DECOY non-RAS box whose BARCODE is the numeric string "1". MAV is a
    // legacy type that needs no parent, so it can exist standalone as the decoy.
    Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create([
        'box_type' => 'MAV', 'box_number' => '99', 'batch_id' => $batch->id, 'barcode' => '1', 'is_legacy' => true,
    ]);

    // Child references its parent by number "1": resolveBox("1") finds the decoy
    // (barcode "1") but it is NOT RAS, so we fall through to the RAS-number
    // resolution and link the real RAS box.
    $rows = [[
        'box_type' => 'NRA', 'box_number' => 'CHILD', 'batch_number' => '46',
        'barcode' => '', 'barcode_status' => 'IN', 'parent_box_number' => '1', 'Location' => 'AR1',
    ]];
    $columnMap = [
        'box_type' => 'box_type', 'box_number' => 'box_number', 'batch_number' => 'batch_number',
        'barcode' => 'barcode', 'barcode_status' => 'barcode_status',
        'parent_barcode' => 'parent_box_number', 'location' => 'Location',
    ];

    $import = bxt_run($rows, $columnMap, $u->id);

    expect(bxt_failures($import))->toBe([]);
    $child = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('box_number', 'CHILD')->first();
    expect($child)->not->toBeNull()
        ->and($child->parent_box_id)->toBe($ras->id); // the RAS box, not the decoy
});

test('a SOFT-DELETED RAS box is never linked as a parent by its box number', function () {
    $repo = Repository::factory()->create(['code' => 'BXSD']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => '46', 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);
    Location::withoutGlobalScope(RepositoryScope::class)->create([
        'name' => 'Room', 'code' => 'AR1', 'type' => 'room', 'is_active' => true, 'repository_id' => $repo->id, 'parent_id' => null,
    ]);

    // The only RAS box "1" is soft-deleted → must not be resolvable as a parent.
    $ras = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create([
        'box_type' => 'RAS', 'box_number' => '1', 'batch_id' => $batch->id, 'barcode' => 'RASBC',
    ]);
    $ras->delete();

    $rows = [[
        'box_type' => 'NRA', 'box_number' => 'CHILD', 'batch_number' => '46',
        'barcode' => '', 'barcode_status' => 'IN', 'parent_box_number' => '1', 'Location' => 'AR1',
    ]];
    $columnMap = [
        'box_type' => 'box_type', 'box_number' => 'box_number', 'batch_number' => 'batch_number',
        'barcode' => 'barcode', 'barcode_status' => 'barcode_status',
        'parent_barcode' => 'parent_box_number', 'location' => 'Location',
    ];

    $import = bxt_run($rows, $columnMap, $u->id);

    // No live RAS parent → the NRA child fails the "must have a parent" rule.
    expect(bxt_failures($import))->toHaveCount(1)
        ->and(strtolower(bxt_failures($import)[0]))->toContain('parent ras box');
    expect(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('box_number', 'CHILD')->exists())->toBeFalse();
});

test('a box whose is_legacy column is MAPPED but BLANK imports (defaults to false), not a NOT NULL failure', function () {
    $repo = Repository::factory()->create(['code' => 'BXLEG']);
    $u = bxt_admin($repo->id);
    $this->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => '46', 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);

    // is_legacy is present in the column map (as the wizard auto-guesses it) but
    // the cell is empty — the ->boolean() cast makes it null, which must default
    // to false rather than fail the NOT NULL column.
    $rows = [[
        'box_type' => 'RAS', 'box_number' => '700', 'batch_number' => '46',
        'barcode' => 'LEG-700', 'barcode_status' => 'IN', 'is_legacy' => '',
    ]];
    $columnMap = [
        'box_type' => 'box_type', 'box_number' => 'box_number', 'batch_number' => 'batch_number',
        'barcode' => 'barcode', 'barcode_status' => 'barcode_status', 'is_legacy' => 'is_legacy',
    ];

    $import = bxt_run($rows, $columnMap, $u->id);

    expect(bxt_failures($import))->toBe([]);
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('box_number', '700')->first();
    expect($box)->not->toBeNull()
        ->and($box->is_legacy)->toBeFalse();
});
