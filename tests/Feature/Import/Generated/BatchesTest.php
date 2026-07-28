<?php

declare(strict_types=1);

use App\Filament\Imports\BatchImporter;
use App\Models\Batch;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use HayderHatem\FilamentExcelImport\Actions\Imports\Jobs\ImportExcel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;

/**
 * Batches: forbidden 34/36, uniqueness withTrashed (importer: BatchImporter).
 *
 * Drives the REAL streaming path (HayderHatem ImportExcel — what the client's
 * "Import Excel/CSV" button dispatches) against real production CSVs and a
 * real RFQ sample xlsx, over a DIRTY (seeded) database. See
 * tests/Feature/Import/DirtyDatabaseImportTest.php for the established
 * ddi_run() pattern this file follows.
 */
uses(RefreshDatabase::class);

const BT_CSV_50 = __DIR__ . '/../../../../nra/inbox/prod-uploads/20260728_081540_1d303669.csv';
const BT_CSV_52 = __DIR__ . '/../../../../nra/inbox/prod-uploads/20260728_081423_efbbaeef.csv';
const BT_XLSX_SAMPLE = __DIR__ . '/../../../../nra/rfq/RFQ-2026-06_Samples/Batch_List_Sample.xlsx';

function bt_admin(?int $repoId = null): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repoId]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * Load a real prod CSV into rows keyed by the literal header text, exactly
 * as the client's file arrives (headers here already match importer field
 * names 1:1: batch_number, description, type, is_active, repository_code).
 *
 * @return array<int, array<string, string>>
 */
function bt_load_csv(string $path): array
{
    if (! file_exists($path)) {
        return [];
    }

    $fh = fopen($path, 'r');
    $headers = array_map(static fn ($h): string => trim((string) $h), fgetcsv($fh));
    $rows = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) === 1 && $row[0] === null) {
            continue;
        }
        $rows[] = array_combine($headers, $row);
    }
    fclose($fh);

    return $rows;
}

const BT_COLUMN_MAP = [
    'batch_number' => 'batch_number',
    'description' => 'description',
    'type' => 'type',
    'is_active' => 'is_active',
    'repository_code' => 'repository_code',
];

/**
 * Run the real streaming ImportExcel job for the given rows.
 *
 * @param array<int, array<string, string>> $rows
 * @param array<string, string> $columnMap
 * @param array<string, mixed> $options
 */
function bt_run(array $rows, array $columnMap, int $userId, array $options = []): Import
{
    EntityResolver::flushMemo();
    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'batches.csv',
        'file_path' => '/tmp/batches.csv',
        'importer' => BatchImporter::class,
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

/** @return array<int, string> */
function bt_failures(Import $import): array
{
    return $import->failedRows()->pluck('validation_error')->all();
}

/** @return array<int, array<string, ?string>> raw failed-row data, keyed by importer field */
function bt_failed_data(Import $import): array
{
    return $import->failedRows()->pluck('data')->all();
}

beforeEach(function (): void {
    if (! file_exists(BT_CSV_50) || ! file_exists(BT_CSV_52) || ! file_exists(BT_XLSX_SAMPLE)) {
        $this->markTestSkipped('Real NAF sample files are not present in this environment.');
    }
});

// ─── file1 (50-row real prod CSV): forbidden numbers + successful count ───────

test('file1: the real 50-row prod CSV imports with exactly 48 successes and 2 forbidden failures', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = bt_admin($repo->id);
    $this->actingAs($u);

    $rows = bt_load_csv(BT_CSV_50);
    expect($rows)->toHaveCount(50);

    $import = bt_run($rows, BT_COLUMN_MAP, $u->id);

    expect($import->successful_rows)->toBe(48)
        ->and($import->getFailedRowsCount())->toBe(2);
});

test('file1: batch 34 is rejected with a clear forbidden-number reason, never masked', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = bt_admin($repo->id);
    $this->actingAs($u);

    $import = bt_run(bt_load_csv(BT_CSV_50), BT_COLUMN_MAP, $u->id);

    $failedData = bt_failed_data($import);
    $failures = bt_failures($import);

    $idx = collect($failedData)->search(fn ($d) => ($d['batch_number'] ?? null) === '34');
    expect($idx)->not->toBeFalse();
    expect($failures[$idx])
        ->toContain('34')
        ->not->toContain('generic_validation')
        ->not->toContain('SQLSTATE');

    expect(Batch::withoutGlobalScope(RepositoryScope::class)->withTrashed()->where('batch_number', 34)->exists())->toBeFalse();
});

test('file1: batch 36 is rejected with a clear forbidden-number reason, never masked', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = bt_admin($repo->id);
    $this->actingAs($u);

    $import = bt_run(bt_load_csv(BT_CSV_50), BT_COLUMN_MAP, $u->id);

    $failedData = bt_failed_data($import);
    $failures = bt_failures($import);

    $idx = collect($failedData)->search(fn ($d) => ($d['batch_number'] ?? null) === '36');
    expect($idx)->not->toBeFalse();
    expect($failures[$idx])
        ->toContain('36')
        ->not->toContain('generic_validation')
        ->not->toContain('SQLSTATE');

    expect(Batch::withoutGlobalScope(RepositoryScope::class)->withTrashed()->where('batch_number', 36)->exists())->toBeFalse();
});

test('file1: batch 33 (RESERVED_MAV_BATCH) imports successfully — it is valid, not forbidden', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = bt_admin($repo->id);
    $this->actingAs($u);

    $import = bt_run(bt_load_csv(BT_CSV_50), BT_COLUMN_MAP, $u->id);

    expect(bt_failures($import))
        ->each(fn ($f) => $f->not->toContain('Batch number 33'));

    $b33 = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 33)->first();
    expect($b33)->not->toBeNull()
        ->and($b33->isReservedMav())->toBeTrue()
        ->and($b33->isForbidden())->toBeFalse()
        ->and($b33->description)->toBe('St Chris collection - via MAV');
});

test('file1: type column trailing space "NOTARY_ACCESSION " is trimmed/cast correctly for batch 30', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = bt_admin($repo->id);
    $this->actingAs($u);

    bt_run(bt_load_csv(BT_CSV_50), BT_COLUMN_MAP, $u->id);

    $b30 = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 30)->first();
    expect($b30)->not->toBeNull()
        ->and($b30->type)->toBe('NOTARY_ACCESSION');
});

test('file1: batches 1-29 default to MAIN_COLLECTION type per the real CSV values', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = bt_admin($repo->id);
    $this->actingAs($u);

    bt_run(bt_load_csv(BT_CSV_50), BT_COLUMN_MAP, $u->id);

    $b1 = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 1)->first();
    expect($b1)->not->toBeNull()
        ->and($b1->type)->toBe('MAIN_COLLECTION');
});

test('file1: duplicate batch_number 43 appearing twice in the same file does not create two rows', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = bt_admin($repo->id);
    $this->actingAs($u);

    $rows = bt_load_csv(BT_CSV_50);
    $occurrences = collect($rows)->filter(fn ($r) => $r['batch_number'] === '43')->count();
    expect($occurrences)->toBe(2); // confirm the real file really is dirty here

    $import = bt_run($rows, BT_COLUMN_MAP, $u->id);

    $all43 = Batch::withoutGlobalScope(RepositoryScope::class)->withTrashed()->where('batch_number', 43)->get();
    expect($all43)->toHaveCount(1);
    $mentions43 = collect(bt_failures($import))->contains(fn ($f) => str_contains($f, '43'));
    expect($mentions43)->toBeFalse();
});

test('file1: blank is_active column defaults freshly-created batches to active=true', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = bt_admin($repo->id);
    $this->actingAs($u);

    bt_run(bt_load_csv(BT_CSV_50), BT_COLUMN_MAP, $u->id);

    $b1 = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 1)->first();
    expect($b1->is_active)->toBeTrue();
});

test('file1: repository_code NRA resolves and stamps repository_id on every imported batch', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $other = Repository::factory()->create(['code' => 'OTHER']);
    $u = bt_admin($other->id); // user default is a DIFFERENT repo than the CSV's repository_code
    $this->actingAs($u);

    bt_run(bt_load_csv(BT_CSV_50), BT_COLUMN_MAP, $u->id);

    // 48 rows succeed but batch_number 43 appears twice in the file (resolves
    // to the same record on the second occurrence), so only 47 distinct
    // Batch rows actually exist afterwards.
    $imported = Batch::withoutGlobalScope(RepositoryScope::class)->whereNotIn('batch_number', [34, 36])->get();
    expect($imported)->toHaveCount(47);
    foreach ($imported as $batch) {
        expect($batch->repository_id)->toBe($repo->id);
    }
});

// ─── file2 (52-row real prod CSV, includes "Unknown"/"NULL" batch numbers) ────

test('file2: non-numeric batch_number "Unknown" fails with a clear, non-masked message', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = bt_admin($repo->id);
    $this->actingAs($u);

    $rows = bt_load_csv(BT_CSV_52);
    $unknownRow = collect($rows)->first(fn ($r) => $r['batch_number'] === 'Unknown');
    expect($unknownRow)->not->toBeNull(); // confirm the real file really has this value

    $import = bt_run($rows, BT_COLUMN_MAP, $u->id);

    $failedData = bt_failed_data($import);
    $failures = bt_failures($import);
    $idx = collect($failedData)->search(fn ($d) => ($d['batch_number'] ?? null) === '0' || ($d['batch_number'] ?? null) === 'Unknown');
    expect($idx)->not->toBeFalse();
    expect($failures[$idx])
        ->not->toContain('generic_validation')
        ->not->toContain('SQLSTATE');
});

test('file2: non-numeric batch_number "NULL" (literal string) fails with a clear, non-masked message', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = bt_admin($repo->id);
    $this->actingAs($u);

    $rows = bt_load_csv(BT_CSV_52);
    $nullRow = collect($rows)->first(fn ($r) => $r['batch_number'] === 'NULL');
    expect($nullRow)->not->toBeNull(); // confirm the real file really has this value

    $import = bt_run($rows, BT_COLUMN_MAP, $u->id);

    expect($import->getFailedRowsCount())->toBeGreaterThanOrEqual(3); // 34, 36, Unknown, NULL
    foreach (bt_failures($import) as $f) {
        expect($f)->not->toContain('generic_validation')->not->toContain('SQLSTATE');
    }
});

test('file2: the 52-row file => 48 succeed, 4 fail (34, 36, Unknown, NULL)', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = bt_admin($repo->id);
    $this->actingAs($u);

    $rows = bt_load_csv(BT_CSV_52);
    expect($rows)->toHaveCount(52);

    $import = bt_run($rows, BT_COLUMN_MAP, $u->id);

    expect($import->successful_rows)->toBe(48)
        ->and($import->getFailedRowsCount())->toBe(4);
});

// ─── Uniqueness withTrashed (soft-deleted collision) ───────────────────────────

test('withTrashed: a soft-deleted batch 15 in the SAME repo is restored, not duplicated, on re-import', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = bt_admin($repo->id);
    $this->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 15, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION',
        'description' => 'stale', 'is_active' => true,
    ])->delete();

    $rows = bt_load_csv(BT_CSV_50);
    $row15 = collect($rows)->first(fn ($r) => $r['batch_number'] === '15');

    $import = bt_run([$row15], BT_COLUMN_MAP, $u->id);

    expect(bt_failures($import))->toBe([]);
    $all = Batch::withoutGlobalScope(RepositoryScope::class)->withTrashed()->where('batch_number', 15)->where('repository_id', $repo->id)->get();
    expect($all)->toHaveCount(1)
        ->and($all->first()->trashed())->toBeFalse()
        ->and($all->first()->description)->toBe($row15['description']);
});

test('withTrashed: a soft-deleted batch 15 in a DIFFERENT repo is NOT reused (no cross-tenant steal) — a fresh batch 15 is created for the target repo', function () {
    $repoA = Repository::factory()->create(['code' => 'RPA']);
    $repoNra = Repository::factory()->create(['code' => 'NRA']);
    $u = bt_admin($repoNra->id);
    $this->actingAs($u);

    $trashed = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 15, 'repository_id' => $repoA->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);
    $trashed->delete();

    $rows = bt_load_csv(BT_CSV_50);
    $row15 = collect($rows)->first(fn ($r) => $r['batch_number'] === '15'); // repository_code column says NRA

    $import = bt_run([$row15], BT_COLUMN_MAP, $u->id);

    expect(bt_failures($import))->toBe([]);

    $repoAbatch = Batch::withoutGlobalScope(RepositoryScope::class)->withTrashed()->where('batch_number', 15)->where('repository_id', $repoA->id)->first();
    expect($repoAbatch)->not->toBeNull()->and($repoAbatch->trashed())->toBeTrue(); // untouched, still trashed

    $nraBatch = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 15)->where('repository_id', $repoNra->id)->first();
    expect($nraBatch)->not->toBeNull()->and($nraBatch->id)->not->toBe($repoAbatch->id);
});

// ─── skip_duplicates option ────────────────────────────────────────────────────

test('skip_duplicates=true skips an already-live batch with a clear reason instead of silently overwriting it', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = bt_admin($repo->id);
    $this->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 5, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION',
        'description' => 'ORIGINAL', 'is_active' => true,
    ]);

    $rows = bt_load_csv(BT_CSV_50);
    $row5 = collect($rows)->first(fn ($r) => $r['batch_number'] === '5');

    $import = bt_run([$row5], BT_COLUMN_MAP, $u->id, ['skip_duplicates' => true]);

    expect($import->getFailedRowsCount())->toBe(1);
    expect(bt_failures($import)[0])->toContain('already exists');

    $b5 = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 5)->first();
    expect($b5->description)->toBe('ORIGINAL'); // untouched
});

test('skip_duplicates=false (default) updates an already-live batch description in place, no duplicate row', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = bt_admin($repo->id);
    $this->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 5, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION',
        'description' => 'ORIGINAL', 'is_active' => true,
    ]);

    $rows = bt_load_csv(BT_CSV_50);
    $row5 = collect($rows)->first(fn ($r) => $r['batch_number'] === '5');

    $import = bt_run([$row5], BT_COLUMN_MAP, $u->id);

    expect(bt_failures($import))->toBe([]);
    $all = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 5)->get();
    expect($all)->toHaveCount(1)
        ->and($all->first()->description)->toBe($row5['description']);
});

// ─── CONFIRMED product-defect probe: is_active silently reset on re-import ────

test('BUG PROBE: re-importing a manually-deactivated batch must NOT silently reactivate it', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = bt_admin($repo->id);
    $this->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 5, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION',
        'description' => 'ORIGINAL', 'is_active' => false, // operator manually deactivated it
    ]);

    $rows = bt_load_csv(BT_CSV_50);
    $row5 = collect($rows)->first(fn ($r) => $r['batch_number'] === '5');
    expect($row5['is_active'])->toBe(''); // confirm the real file's is_active cell really is blank

    bt_run([$row5], BT_COLUMN_MAP, $u->id);

    $b5 = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 5)->first();
    expect($b5->is_active)->toBeFalse(); // the operator's manual deactivation must survive a re-import
});

// ─── repository_id resolution edge cases ───────────────────────────────────────

test('missing repository_code AND no user default_repository_id fails cleanly (not a raw SQLSTATE dump)', function () {
    $u = bt_admin(null); // no default repository
    $this->actingAs($u);

    $rows = bt_load_csv(BT_CSV_50);
    $row1 = collect($rows)->first(fn ($r) => $r['batch_number'] === '1');
    unset($row1['repository_code']);
    $columnMap = BT_COLUMN_MAP;
    unset($columnMap['repository_code']);

    $import = bt_run([$row1], $columnMap, $u->id);

    expect($import->getFailedRowsCount())->toBe(1);
    expect(bt_failures($import)[0])
        ->not->toContain('SQLSTATE')
        ->and(mb_strlen(bt_failures($import)[0]))->toBeLessThan(200);
});

// ─── validation edge cases on batch_number ─────────────────────────────────────

test('negative batch_number "-5" fails validation cleanly, not masked as generic_validation', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = bt_admin($repo->id);
    $this->actingAs($u);

    $import = bt_run(
        [['batch_number' => '-5', 'description' => 'x', 'type' => 'MAIN_COLLECTION', 'is_active' => '', 'repository_code' => 'NRA']],
        BT_COLUMN_MAP,
        $u->id,
    );

    expect($import->getFailedRowsCount())->toBe(1);
    expect(bt_failures($import)[0])->not->toContain('generic_validation')->not->toContain('SQLSTATE');
});

test('zero batch_number "0" fails validation cleanly (min:1), not masked as generic_validation', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = bt_admin($repo->id);
    $this->actingAs($u);

    $import = bt_run(
        [['batch_number' => '0', 'description' => 'x', 'type' => 'MAIN_COLLECTION', 'is_active' => '', 'repository_code' => 'NRA']],
        BT_COLUMN_MAP,
        $u->id,
    );

    expect($import->getFailedRowsCount())->toBe(1);
    expect(bt_failures($import)[0])->not->toContain('generic_validation')->not->toContain('SQLSTATE');
});

// ─── Idempotent re-import (the client's real workflow) ─────────────────────────

test('re-running the whole 50-row file twice is idempotent: 48 successes both times, no duplicate rows', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = bt_admin($repo->id);
    $this->actingAs($u);

    $rows = bt_load_csv(BT_CSV_50);

    $import1 = bt_run($rows, BT_COLUMN_MAP, $u->id);
    expect($import1->successful_rows)->toBe(48);

    $countAfterFirst = Batch::withoutGlobalScope(RepositoryScope::class)->count();

    $import2 = bt_run($rows, BT_COLUMN_MAP, $u->id);
    expect($import2->successful_rows)->toBe(48);

    $countAfterSecond = Batch::withoutGlobalScope(RepositoryScope::class)->count();
    expect($countAfterSecond)->toBe($countAfterFirst);
});

// ─── Real xlsx sample (RFQ-2026-06_Samples/Batch_List_Sample.xlsx) ─────────────

/** @return array<int, string> distinct non-blank values of the given column across the real sample sheet */
function bt_distinct_xlsx_column(string $column): array
{
    $reader = IOFactory::createReaderForFile(BT_XLSX_SAMPLE);
    $reader->setReadDataOnly(true);
    $sheet = $reader->load(BT_XLSX_SAMPLE)->getActiveSheet();
    $highestRow = $sheet->getHighestDataRow();

    $values = [];
    for ($r = 2; $r <= $highestRow; $r++) {
        $v = $sheet->getCell("{$column}{$r}")->getValue();
        if ($v !== null && $v !== '') {
            $values[(string) $v] = true;
        }
    }

    return array_keys($values);
}

test('xlsx sample: the real distinct "RAS Batch 1" values import cleanly through BatchImporter', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = bt_admin($repo->id);
    $this->actingAs($u);

    $values = bt_distinct_xlsx_column('A'); // header "RAS Batch 1"
    expect($values)->toEqualCanonicalizing(['1', '28', '35', '50']); // confirm real file content

    $rows = array_map(fn (string $v) => ['RAS Batch 1' => $v, 'repository_code' => 'NRA'], $values);
    $columnMap = ['batch_number' => 'RAS Batch 1', 'repository_code' => 'repository_code'];

    $import = bt_run($rows, $columnMap, $u->id);

    expect(bt_failures($import))->toBe([]);
    $batch50 = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 50)->first();
    expect($batch50)->not->toBeNull()->and($batch50->isWillsOnly())->toBeTrue();
});

test('xlsx sample: the real distinct "RAS Batch 2" values import cleanly with no failures', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = bt_admin($repo->id);
    $this->actingAs($u);

    $values = bt_distinct_xlsx_column('C'); // header "RAS Batch 2"
    expect($values)->toEqualCanonicalizing(['3', '50']); // confirm real file content

    $rows = array_map(fn (string $v) => ['RAS Batch 2' => $v, 'repository_code' => 'NRA'], $values);
    $columnMap = ['batch_number' => 'RAS Batch 2', 'repository_code' => 'repository_code'];

    $import = bt_run($rows, $columnMap, $u->id);

    expect(bt_failures($import))->toBe([])
        ->and(Batch::withoutGlobalScope(RepositoryScope::class)->count())->toBe(2);
});
