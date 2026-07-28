<?php

declare(strict_types=1);

use App\Filament\Imports\AccessionRowImporter;
use App\Models\Accession;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\Practice;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use HayderHatem\FilamentExcelImport\Actions\Imports\Jobs\ImportExcel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * Accessions bottom-up auto-create chain (AccessionImporter / AccessionRowImporter).
 *
 * One row => one Document; the importer auto-creates the whole ancestor chain
 * (Authority -> Accession -> Batch (N:N) -> Box) from the SAME row. These
 * tests drive the exact hayderhatem ImportExcel streaming job the client's
 * "Import Excel" button dispatches (not the standard Filament ImportCsv),
 * against real row data pulled from the client's own sample sheets:
 *
 *   - nra/inbox/2026-06-06_NAF_sam_abela_accession.xlsx ("Batch list format")
 *   - nra/outbox/2026-07-22_NAF_import_examples/example_accession_import.xlsx
 *
 * FOCUS: chain integrity, row gaps, Series-must-pre-exist dependency, and
 * re-run idempotency against a DIRTY (not clean-seeded) database — the same
 * angle that drove tests/Feature/Import/DirtyDatabaseImportTest.php for the
 * other importers.
 */
uses(RefreshDatabase::class);

function axc_admin(?int $repoId = null): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repoId]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * Run the real hayderhatem ImportExcel streaming job for AccessionRowImporter
 * — the exact path the client's "Import Excel" button dispatches.
 *
 * @param array<int, array<string, mixed>> $rows keyed by Excel header
 * @param array<string, string> $columnMap importerField => Excel header
 * @param array<string, mixed> $options
 */
function axc_run(array $rows, array $columnMap, int $userId, array $options = []): Import
{
    EntityResolver::flushMemo();
    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'accession.xlsx',
        'file_path' => '/tmp/accession.xlsx',
        'importer' => AccessionRowImporter::class,
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
function axc_failures(Import $import): array
{
    return $import->failedRows()->pluck('validation_error')->all();
}

/** Seed the two prerequisite Series codes used by every real sample row. */
function axc_seedSeries(): void
{
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true]);
    Series::firstOrCreate(['code' => 'RWL'], ['title' => 'Registers of Wills', 'is_wills_series' => true, 'is_active' => true]);
}

const AXC_EX_XLSX = __DIR__ . '/../../../../nra/outbox/2026-07-22_NAF_import_examples/example_accession_import.xlsx';
const AXC_BLF_XLSX = __DIR__ . '/../../../../nra/inbox/2026-06-06_NAF_sam_abela_accession.xlsx';

/**
 * Read a range of DATA rows from a real client xlsx file through the REAL
 * vendor production algorithm — HayderHatem\...\ImportExcel::readExcelRowsFromFile()
 * — via reflection (it's `protected`), so these fixtures come from the
 * *actual* code path the streaming "Import Excel" button runs, not a
 * hand-typed re-implementation of the file's content that could silently
 * drift from what the client's file really contains.
 *
 * @return array<int, array<string, mixed>>
 */
function axc_realRows(string $filePath, int $startRow, int $endRow, int $headerOffset = 0, int $activeSheet = 0): array
{
    $job = new ImportExcel(
        importId: 0,
        rows: null,
        startRow: null,
        endRow: null,
        columnMap: [],
        options: ['headerOffset' => $headerOffset, 'activeSheet' => $activeSheet],
    );
    $method = new ReflectionMethod($job, 'readExcelRowsFromFile');
    $method->setAccessible(true);

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $method->invoke($job, $filePath, $startRow, $endRow);

    return $rows;
}

// ─── Column map for nra/outbox/.../example_accession_import.xlsx ("Data" sheet) ──
// Headers match the importer's own column LABELS verbatim.
const AXC_EX_MAP = [
    'authority_identifier' => 'Authority Identifier',
    'authority_name' => 'Authority Name',
    'authority_surname' => 'Authority Surname',
    'accession_number' => 'Accession Number',
    'accession_title' => 'Accession Title',
    'accession_type' => 'Accession Type',
    'repository' => 'Repository',
    'batch_number' => 'Batch Number',
    'box_number' => 'Box No',
    'box_barcode' => 'Box Barcode',
    'box_barcode_status' => 'Box Status',
    'current_box_type' => 'Current Box Type',
    'document_identifier' => 'Document Identifier',
    'document_type' => 'Document Type',
    'series' => 'Series',
    'volume_number' => 'Volume No',
    'part_number' => 'Part Number',
    'practice' => 'Practice',
    'dates' => 'Dates',
    'deeds' => 'Deeds',
    'number_of_acts' => 'No of Acts',
    'pages_folios' => 'Pages/Folios',
    'notes' => 'Note',
];

// Two real rows read live from the "Data" sheet (excel row 2 & row 3, i.e.
// the first two data rows after the header) through the real vendor reader.
function axc_exRow1(array $overrides = []): array
{
    return array_merge(axc_realRows(AXC_EX_XLSX, 2, 2, 0, 0)[0], $overrides);
}

function axc_exRow2(array $overrides = []): array
{
    return array_merge(axc_realRows(AXC_EX_XLSX, 3, 3, 0, 0)[0], $overrides);
}

// ─── Column map for the "Batch list format" sheet of the sam_abela accession xlsx ──
const AXC_BLF_MAP = [
    'batch_number' => 'Batch No',
    'box_number' => 'Box No',
    'box_barcode' => 'Barcode',
    'box_barcode_status' => 'Status',
    'authority_identifier' => 'Identifier',
    'volume_number' => 'Volume',
    'part_number' => 'Part number',
    'practice' => 'Practice',
    'authority_name' => 'Name',
    'authority_surname' => 'Surname',
    'dates' => 'Dates',
    'deeds' => 'Deeds',
    'document_type' => 'Document Type',
    'series' => 'Series',
    'current_box_type' => 'Current Box',
    'notes' => 'Note',
    'accession_date' => 'Accession Date',
    'accession_number' => 'Accession No',
    'accession_title' => 'Accession Title',
    'accession_type' => 'Type',
    'repository' => 'Repository',
];

// Real rows read live from nra/inbox/2026-06-06_NAF_sam_abela_accession.xlsx,
// sheet index 2 "Batch list format" (activeSheet=2), through the real vendor
// reader. Excel row numbers below = data-row-index + 1 (header is row 1).
function axc_blfRow1(): array
{
    // Excel row 2 — Batch 46, Box 1, R642 Vincenzo Caruana.
    return axc_realRows(AXC_BLF_XLSX, 2, 2, 0, 2)[0];
}

function axc_blfRow2Box2(): array
{
    // Excel row 6 — Batch 46, Box 2, same accession/authority, different barcode.
    return axc_realRows(AXC_BLF_XLSX, 6, 6, 0, 2)[0];
}

function axc_blfRow643Box20(): array
{
    // Excel row 87 — Batch 46, Box 20, a DIFFERENT real authority (R643 Paolo Vassallo).
    return axc_realRows(AXC_BLF_XLSX, 87, 87, 0, 2)[0];
}

function axc_blfWillsRow(array $overrides = []): array
{
    // Excel row 201, the LAST data row of the file — Batch 50 (WILLS_BATCH),
    // Box 34, R640 Francesco Catania.
    return array_merge(axc_realRows(AXC_BLF_XLSX, 201, 201, 0, 2)[0], $overrides);
}

// ─────────────────────────────────────────────────────────────────────────
// GROUP A — bottom-up cascade creates the full chain from one row
// ─────────────────────────────────────────────────────────────────────────

test('a single row creates the full Authority -> Accession -> Batch -> Box -> Document chain', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();

    $import = axc_run([axc_exRow1()], AXC_EX_MAP, $u->id);

    expect(axc_failures($import))->toBe([]);

    $authority = Authority::withoutGlobalScopes()->where('identifier', '642')->first();
    expect($authority)->not->toBeNull()
        ->and($authority->surname)->toBe('Caruana');

    $accession = Accession::withoutGlobalScope(RepositoryScope::class)
        ->where('accession_number', 'ACC-2026-01')->first();
    expect($accession)->not->toBeNull()
        ->and($accession->code)->toBe('Caruana accession')
        ->and($accession->repository_id)->toBe($repo->id);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 46)->first();
    expect($batch)->not->toBeNull()
        ->and($batch->repository_id)->toBe($repo->id)
        ->and($batch->type)->toBe('NOTARY_ACCESSION'); // batch_number >= 30 default, confirmed by 'NA' accession_type

    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', 'AC54609')->first();
    expect($box)->not->toBeNull()
        ->and($box->box_number)->toBe('1')
        ->and($box->batch_id)->toBe($batch->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'R642/001')->first();
    expect($doc)->not->toBeNull()
        ->and($doc->series_id)->toBe(Series::where('code', 'REG')->value('id'))
        ->and($doc->accession_id)->toBe($accession->id)
        ->and($doc->batch_id)->toBe($batch->id)
        ->and($doc->current_box_id)->toBe($box->id)
        ->and($doc->custody_status)->toBe('in_box')
        ->and($doc->current_box_type)->toBe('RAS Box')
        ->and($doc->dates_year_start)->toBe(1870)
        ->and($doc->volume_number)->toBe('1')
        ->and($doc->catalogue_identifier)->toBeNull();

    // Authority pivot attached as primary.
    expect($doc->authorities()->where('authorities.id', $authority->id)->wherePivot('is_primary', true)->exists())->toBeTrue();
});

test('a second row for the same accession/batch/box reuses the ancestors instead of duplicating them', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();

    $import = axc_run([axc_exRow1(), axc_exRow2()], AXC_EX_MAP, $u->id);

    expect(axc_failures($import))->toBe([]);
    expect(Authority::withoutGlobalScopes()->count())->toBe(1)
        ->and(Accession::withoutGlobalScope(RepositoryScope::class)->count())->toBe(1)
        ->and(Batch::withoutGlobalScope(RepositoryScope::class)->count())->toBe(1)
        ->and(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->count())->toBe(1)
        ->and(Document::withoutGlobalScope(RepositoryScope::class)->count())->toBe(2);
});

test('batch description is derived from the first-linked accession title', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();

    axc_run([axc_exRow1()], AXC_EX_MAP, $u->id);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 46)->first();
    expect($batch->description)->toBe('Caruana accession');
});

test('rows in different boxes of the same batch create separate boxes but share the batch and accession', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();

    $import = axc_run([axc_blfRow1(), axc_blfRow2Box2()], AXC_BLF_MAP, $u->id);

    expect(axc_failures($import))->toBe([]);
    expect(Batch::withoutGlobalScope(RepositoryScope::class)->count())->toBe(1)
        ->and(Accession::withoutGlobalScope(RepositoryScope::class)->count())->toBe(1)
        ->and(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->count())->toBe(2);
    expect(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', 'AC54609')->exists())->toBeTrue()
        ->and(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', 'AC54605')->exists())->toBeTrue();
});

test('distinct authority identifiers on different rows create distinct authorities with correct primaries', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();

    $import = axc_run([axc_blfRow1(), axc_blfRow643Box20()], AXC_BLF_MAP, $u->id);

    expect(axc_failures($import))->toBe([]);
    expect(Authority::withoutGlobalScopes()->count())->toBe(2);

    $a642 = Authority::withoutGlobalScopes()->where('identifier', '642')->first();
    $a643 = Authority::withoutGlobalScopes()->where('identifier', '643')->first();
    expect($a642->surname)->toBe('Caruana')
        ->and($a643->surname)->toBe('Vassallo');

    $docBox1 = Document::withoutGlobalScope(RepositoryScope::class)
        ->whereHas('currentBox', fn ($q) => $q->withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', 'AC54609'))
        ->first();
    $docBox20 = Document::withoutGlobalScope(RepositoryScope::class)
        ->whereHas('currentBox', fn ($q) => $q->withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', 'AC54760'))
        ->first();

    expect($docBox1->authorities()->where('authorities.id', $a642->id)->exists())->toBeTrue()
        ->and($docBox20->authorities()->where('authorities.id', $a643->id)->exists())->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────────
// GROUP B — DECISION 4: auto-generated document identifier sequencing
// ─────────────────────────────────────────────────────────────────────────

test('no Document Identifier column mapped auto-generates a sequential identifier within the box', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();
    AccessionRowImporter::resetBoxRowSeq();

    $map = AXC_EX_MAP;
    unset($map['document_identifier']); // simulate the column not being mapped at all

    $import = axc_run([axc_exRow1(), axc_exRow2()], $map, $u->id);

    expect(axc_failures($import))->toBe([]);
    $docs = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('accession_id', Accession::withoutGlobalScope(RepositoryScope::class)->value('id'))
        ->orderBy('id')->pluck('identifier')->all();

    expect($docs)->toBe(['ACC-2026-01-1-1', 'ACC-2026-01-1-2']);
});

test('the auto-generated sequence is namespaced per import run (does not continue across separate imports)', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();
    // AccessionRowImporter::$boxRowSeq is a PHP static — it survives across
    // Pest tests within the same process (RefreshDatabase only resets the DB).
    // The importer ships a dedicated test-only reset for exactly this reason.
    AccessionRowImporter::resetBoxRowSeq();

    $map = AXC_EX_MAP;
    unset($map['document_identifier']);

    axc_run([axc_exRow1()], $map, $u->id);        // import #1 -> seq 1
    axc_run([axc_exRow2()], $map, $u->id);        // import #2, same accession/box -> seq should restart at 1

    $idents = Document::withoutGlobalScope(RepositoryScope::class)->orderBy('id')->pluck('identifier')->all();
    expect($idents)->toBe(['ACC-2026-01-1-1', 'ACC-2026-01-1-1']);
});

// ─────────────────────────────────────────────────────────────────────────
// GROUP C — row-level validation / row gaps
// ─────────────────────────────────────────────────────────────────────────

test('a row with no Accession Number but a valid Batch/Box still imports (accession_id left null)', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();

    $import = axc_run([axc_exRow1(['Accession Number' => ''])], AXC_EX_MAP, $u->id);

    expect(axc_failures($import))->toBe([]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'R642/001')->first();
    expect($doc)->not->toBeNull()
        ->and($doc->accession_id)->toBeNull()
        ->and($doc->batch_id)->not->toBeNull();
});

test('an unknown Series code fails the row with a clear message', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    // Deliberately do NOT seed Series — 'REG' does not exist.

    $import = axc_run([axc_exRow1()], AXC_EX_MAP, $u->id);

    $failures = axc_failures($import);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toContain("Series 'REG' not found");
    // Chain integrity: nothing should have been committed for a row that fails.
    expect(Document::withoutGlobalScope(RepositoryScope::class)->count())->toBe(0);
});

test('a forbidden batch number (34) is rejected without creating any ancestor', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();

    $import = axc_run([axc_exRow1(['Batch Number' => '34'])], AXC_EX_MAP, $u->id);

    $failures = axc_failures($import);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toContain('reserved');
    // The whole cascade (accession too) must roll back on a mid-cascade throw.
    expect(Accession::withoutGlobalScope(RepositoryScope::class)->count())->toBe(0)
        ->and(Batch::withoutGlobalScope(RepositoryScope::class)->count())->toBe(0);
});

test('an Accession Type not in the Batch Types lookup fails the row', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();

    $import = axc_run([axc_exRow1(['Accession Type' => 'BOGUS'])], AXC_EX_MAP, $u->id);

    $failures = axc_failures($import);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toContain("Accession Type 'BOGUS' is not in the Accession Types lookup");
});

test('an invalid Current Box Type fails the row', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();

    $import = axc_run([axc_exRow1(['Current Box Type' => 'Wooden Crate'])], AXC_EX_MAP, $u->id);

    $failures = axc_failures($import);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toContain('Current Box Type');
});

test('a Practice that does not exist fails the row (FB1-GAP-1)', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();

    $import = axc_run([axc_exRow1(['Practice' => 'Nonexistent Practice'])], AXC_EX_MAP, $u->id);

    $failures = axc_failures($import);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toContain("Practice 'Nonexistent Practice' does not exist");
});

test('an existing Practice resolves cleanly and the row imports', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();
    Practice::withoutGlobalScopes()->create(['identifier' => 'PR1', 'name' => 'Sam Abela Practice', 'repository_id' => $repo->id]);

    $import = axc_run([axc_exRow1(['Practice' => 'PR1'])], AXC_EX_MAP, $u->id);

    expect(axc_failures($import))->toBe([]);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'R642/001')->value('practice'))->toBe('PR1');
});

test('Batch 50 rejects a document whose series is not a wills series (RFQ App.1 #2)', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries(); // REG is NOT a wills series

    $import = axc_run([axc_blfWillsRow(['Series' => 'REG'])], AXC_BLF_MAP, $u->id);

    $failures = axc_failures($import);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toContain('reserved for wills documents');
});

test('Batch 50 accepts a genuine wills series row (real wills data from the sample sheet)', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries(); // RWL IS a wills series

    $import = axc_run([axc_blfWillsRow()], AXC_BLF_MAP, $u->id);

    expect(axc_failures($import))->toBe([]);
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 50)->first();
    expect($batch)->not->toBeNull();
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('batch_id', $batch->id)->first();
    expect($doc)->not->toBeNull()
        ->and($doc->series_id)->toBe(Series::where('code', 'RWL')->value('id'));
});

test('Authority Identifier is required when a name is given without it (names alone are ambiguous)', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();

    $import = axc_run([axc_exRow1(['Authority Identifier' => ''])], AXC_EX_MAP, $u->id);

    $failures = axc_failures($import);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toContain('Authority Identifier is required');
});

test('a row with neither Authority Identifier nor name imports but is flagged missing_data (Q8)', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();

    $import = axc_run(
        [axc_exRow1(['Authority Identifier' => '', 'Authority Name' => '', 'Authority Surname' => ''])],
        AXC_EX_MAP,
        $u->id,
    );

    expect(axc_failures($import))->toBe([]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'R642/001')->first();
    expect($doc)->not->toBeNull();
    expect(DocumentFlag::where('document_id', $doc->id)->where('type', 'missing_data')->exists())->toBeTrue();
});

test('an authority name that mismatches an existing record fails the row', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();
    // Pre-existing R642 is genuinely Vincenzo Caruana (real data).
    Authority::withoutGlobalScopes()->create(['identifier' => '642', 'given_names' => 'Vincenzo', 'surname' => 'Caruana', 'entity_type' => 'Notary']);

    $import = axc_run([axc_exRow1(['Authority Name' => 'SomeoneElse'])], AXC_EX_MAP, $u->id);

    $failures = axc_failures($import);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toContain("does not match record 'Vincenzo'");
    // No duplicate authority created on a mismatch.
    expect(Authority::withoutGlobalScopes()->where('identifier', '642')->count())->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────
// GROUP D — re-run idempotency against a DIRTY database (the project's
// documented production-incident angle: soft-deleted residue, stateful DB)
// ─────────────────────────────────────────────────────────────────────────

test('re-importing the identical row twice updates the same Document in place, no duplicate', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();

    axc_run([axc_exRow1()], AXC_EX_MAP, $u->id);
    $import2 = axc_run([axc_exRow1(['Note' => 'Re-imported, corrected note'])], AXC_EX_MAP, $u->id);

    expect(axc_failures($import2))->toBe([]);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'R642/001')->count())->toBe(1)
        ->and(Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'R642/001')->value('notes'))->toBe('Re-imported, corrected note');
});

test('a soft-deleted Document with the same identifier is restored on re-import, not duplicated', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();

    axc_run([axc_exRow1()], AXC_EX_MAP, $u->id);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'R642/001')->firstOrFail();
    $doc->delete();
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', 'R642/001')->count())->toBe(0);

    $import2 = axc_run([axc_exRow1()], AXC_EX_MAP, $u->id);

    expect(axc_failures($import2))->toBe([]);
    $all = Document::withoutGlobalScope(RepositoryScope::class)->withTrashed()->where('identifier', 'R642/001')->get();
    expect($all)->toHaveCount(1)
        ->and($all->first()->trashed())->toBeFalse();
});

test('a soft-deleted parent Accession with the same accession_number is reused, not duplicated, on re-import', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();

    // Dirty state: the accession already exists in this repo but is soft-deleted
    // (e.g. an operator deleted it, or a prior cleanup run removed it).
    Accession::withoutGlobalScope(RepositoryScope::class)->create([
        'code' => 'Caruana accession',
        'accession_number' => 'ACC-2026-01',
        'repository_id' => $repo->id,
    ])->delete();
    expect(Accession::withoutGlobalScope(RepositoryScope::class)->count())->toBe(0)
        ->and(Accession::withoutGlobalScope(RepositoryScope::class)->withTrashed()->count())->toBe(1);

    $import = axc_run([axc_exRow1()], AXC_EX_MAP, $u->id);

    expect(axc_failures($import))->toBe([]);
    // Chain integrity: re-importing must NOT create a second, duplicate
    // Accession row for the same accession_number in the same repository.
    $all = Accession::withoutGlobalScope(RepositoryScope::class)->withTrashed()->where('accession_number', 'ACC-2026-01')->get();
    expect($all)->toHaveCount(1)
        ->and($all->first()->trashed())->toBeFalse();
});

test('a soft-deleted Batch with the same batch_number is reused, not left crashing the row, on re-import', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();

    // Dirty state: batch_number 46 already exists in this repo but is soft-deleted.
    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 46, 'repository_id' => $repo->id, 'type' => 'NOTARY_ACCESSION', 'is_active' => true,
    ])->delete();
    expect(Batch::withoutGlobalScope(RepositoryScope::class)->count())->toBe(0)
        ->and(Batch::withoutGlobalScope(RepositoryScope::class)->withTrashed()->count())->toBe(1);

    $import = axc_run([axc_exRow1()], AXC_EX_MAP, $u->id);

    $failures = axc_failures($import);
    expect($failures)->toBe([]); // no row should fail for a dirty-but-legitimate re-import
    $all = Batch::withoutGlobalScope(RepositoryScope::class)->withTrashed()->where('batch_number', 46)->get();
    expect($all)->toHaveCount(1)
        ->and($all->first()->trashed())->toBeFalse();
});

test('a soft-deleted Box with the same barcode is reused, not left crashing the row, on re-import', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 46, 'repository_id' => $repo->id, 'type' => 'NOTARY_ACCESSION', 'is_active' => true,
    ]);
    // Dirty state: a box with this exact (globally-unique) barcode already
    // exists but is soft-deleted.
    Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create([
        'batch_id' => $batch->id, 'box_number' => '1', 'barcode' => 'AC54609', 'box_type' => 'RAS',
    ])->delete();
    expect(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', 'AC54609')->count())->toBe(0);

    $import = axc_run([axc_exRow1()], AXC_EX_MAP, $u->id);

    $failures = axc_failures($import);
    expect($failures)->toBe([]); // no row should fail for a dirty-but-legitimate re-import
    $all = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->withTrashed()->where('barcode', 'AC54609')->get();
    expect($all)->toHaveCount(1)
        ->and($all->first()->trashed())->toBeFalse();
});

test('re-importing a case/whitespace variant of an existing accession_number reuses the same accession', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();

    axc_run([axc_exRow1()], AXC_EX_MAP, $u->id);
    expect(Accession::withoutGlobalScope(RepositoryScope::class)->count())->toBe(1);

    // Same accession_number, different document identifier — should attach
    // to the SAME accession, not create a second one.
    $import2 = axc_run([axc_exRow2()], AXC_EX_MAP, $u->id);

    expect(axc_failures($import2))->toBe([]);
    expect(Accession::withoutGlobalScope(RepositoryScope::class)->count())->toBe(1)
        ->and(Document::withoutGlobalScope(RepositoryScope::class)->count())->toBe(2);
});

// ─────────────────────────────────────────────────────────────────────────
// GROUP E — box/barcode integrity checks
// ─────────────────────────────────────────────────────────────────────────

test('a barcode that belongs to a different box number fails the row (barcode/box mismatch)', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();

    axc_run([axc_exRow1()], AXC_EX_MAP, $u->id); // creates Box 1 / barcode AC54609

    // Same barcode, but this row claims a DIFFERENT box number.
    $import2 = axc_run([axc_exRow1(['Box No' => '2', 'Document Identifier' => 'R642/999'])], AXC_EX_MAP, $u->id);

    $failures = axc_failures($import2);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toContain("belongs to Box '1'");
});

test('a box_number whose existing barcode differs from the row barcode fails the row', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();

    axc_run([axc_exRow1()], AXC_EX_MAP, $u->id); // Box 1 gets barcode AC54609

    // Same batch/box number, but a DIFFERENT barcode.
    $import2 = axc_run([axc_exRow1(['Box Barcode' => 'AC99999', 'Document Identifier' => 'R642/998'])], AXC_EX_MAP, $u->id);

    $failures = axc_failures($import2);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toContain('already has barcode');
});

// ─────────────────────────────────────────────────────────────────────────
// GROUP F — numeric Excel cell coercion (streaming path arrives as int/float)
// ─────────────────────────────────────────────────────────────────────────

test('numeric Batch Number / Box No / Box Barcode cells (real Excel numeric artefacts) import cleanly', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = axc_admin($repo->id);
    $this->actingAs($u);
    axc_seedSeries();

    // PhpSpreadsheet hands numeric cells as real PHP int/float, exactly as the
    // "Barcodes" sheet of the sam_abela file does for its R number / batch /
    // box columns (see dumped sample: 640, 46, 75 as bare ints).
    $row = axc_exRow1([
        'Batch Number' => 46,
        'Box No' => 1,
        'Box Barcode' => 54609,
        'Authority Identifier' => 642,
    ]);

    $import = axc_run([$row], AXC_EX_MAP, $u->id);

    expect(axc_failures($import))->toBe([]);
    expect(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', '54609')->exists())->toBeTrue()
        ->and(Authority::withoutGlobalScopes()->where('identifier', '642')->exists())->toBeTrue();
});
