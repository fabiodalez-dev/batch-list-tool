<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use HayderHatem\FilamentExcelImport\Actions\Imports\Jobs\ImportExcel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use Spatie\Permission\Models\Role;

/**
 * DocumentImporter — multi-dependency (Series/Authority/Batch/Box) + the
 * ~5MB real production batch list.
 *
 * Drives the REAL streaming path (HayderHatem\FilamentExcelImport ImportExcel
 * — what the "Import Excel / CSV" button on the Document resource dispatches),
 * against a DIRTY database (soft-deleted residue) and the REAL client files:
 *
 *   - nra/outbox/2026-07-22_NAF_import_examples/example_document_import.xlsx
 *   - nra/inbox/2026-06-22_NAF_New_BATCH_LIST_04_06_26_sample.xlsx (~5MB, the
 *     client's own live working batch list)
 */
uses(RefreshDatabase::class);

// Safety net for the CONFIRMED savepoint-leak bug (see the dedicated test
// below): several other tests in this file legitimately trigger a
// saveRecord()-level failure (DocumentImporter::beforeSave() opens a
// transaction that afterSave() then never gets a chance to close), which
// leaves the connection's transaction depth permanently incremented. Without
// this reset, ONE such test would take down every test that runs after it
// in the same process — resync after each test so the bug's blast radius is
// visible ONLY in the test that documents it, not the whole suite.
afterEach(function (): void {
    while (DB::transactionLevel() > 1) {
        DB::rollBack();
    }
});

const DGT_EXAMPLE_XLSX = __DIR__ . '/../../../../nra/outbox/2026-07-22_NAF_import_examples/example_document_import.xlsx';
const DGT_BATCHLIST_XLSX = __DIR__ . '/../../../../nra/inbox/2026-06-22_NAF_New_BATCH_LIST_04_06_26_sample.xlsx';

function dgt_admin(?int $repoId = null): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    // documents.repository_id is NOT NULL — every test needs a default
    // repository for the BelongsToRepository creating-hook to stamp.
    $repoId ??= Repository::factory()->create()->id;
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repoId]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * Run the REAL hayderhatem ImportExcel job (the exact job the "Import Excel /
 * CSV" button on DocumentResource dispatches) for the given rows.
 *
 * @param array<int, array<string, mixed>> $rows keyed by Excel header
 * @param array<string, string> $columnMap importerField => Excel header
 */
function dgt_run(array $rows, array $columnMap, int $userId, array $options = []): Import
{
    EntityResolver::flushMemo();
    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'documents.xlsx',
        'file_path' => '/tmp/documents.xlsx',
        'importer' => DocumentImporter::class,
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
function dgt_failures(Import $import): array
{
    return $import->failedRows()->pluck('validation_error')->all();
}

/**
 * Read a range of DATA rows from a real xlsx file through the REAL vendor
 * production algorithm — HayderHatem\...\ImportExcel::readExcelRowsFromFile()
 * — via reflection (it's `protected`), so the test exercises the *actual*
 * code path the streaming button runs, not a re-implementation of it.
 *
 * @return array<int, array<string, mixed>>
 */
function dgt_realRows(string $filePath, int $startRow, int $endRow, int $headerOffset = 0, int $activeSheet = 0): array
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

/**
 * @return array<int, string>
 */
function dgt_headers(string $filePath, int $headerOffset = 0, int $activeSheet = 0): array
{
    $reader = IOFactory::createReaderForFile($filePath);
    $reader->setReadDataOnly(true);
    $headerRow = $headerOffset + 1;
    $reader->setReadFilter(new class($headerRow) implements IReadFilter
    {
        public function __construct(private int $headerRow) {}

        public function readCell($columnAddress, $row, $worksheetName = ''): bool
        {
            return $row === $this->headerRow;
        }
    });
    $spreadsheet = $reader->load($filePath);
    $sheet = $spreadsheet->getSheet($activeSheet);
    $rows = $sheet->toArray(null, true, false, false);

    return array_map(fn ($h): string => (string) $h, $rows[0] ?? []);
}

/**
 * Build a columnMap exactly the way HayderHatem's CanImportExcelRecords trait
 * does for the real "Import Excel / CSV" modal (see afterStateUpdated on the
 * FileUpload component): lower-case the headers, intersect against each
 * ImportColumn's guesses (already lower-cased by Filament), first match wins
 * in HEADER order.
 *
 * @param array<int, string> $headers
 * @return array<string, string>
 */
function dgt_columnMap(array $headers): array
{
    $lowercaseExcelColumnValues = array_map(fn ($h) => Str::lower((string) $h), $headers);
    $lowercaseExcelColumnKeys = array_combine($lowercaseExcelColumnValues, $headers);

    $map = [];
    foreach (DocumentImporter::getColumns() as $column) {
        $match = Arr::first(array_intersect($lowercaseExcelColumnValues, $column->getGuesses()));
        if ($match !== null) {
            $map[$column->getName()] = $lowercaseExcelColumnKeys[$match] ?? null;
        }
    }

    return $map;
}

function dgt_series(string $code, bool $wills = false): Series
{
    return Series::firstOrCreate(
        ['code' => $code],
        ['title' => $code . ' series', 'is_active' => true, 'is_wills_series' => $wills],
    );
}

// ════════════════════════════════════════════════════════════════════════
//  FLAGSHIP — duplicate-header columns silently collapse to blank on the
//  REAL production read path (both the shipped template AND the client's
//  actual live 5MB working file share this header layout).
// ════════════════════════════════════════════════════════════════════════

test('the REAL vendor row-reader collapses duplicate "Status 1"/"Barcode (IN)"/"Disinfestation Date" headers to blank on the shipped example template', function () {
    if (! is_file(DGT_EXAMPLE_XLSX)) {
        $this->markTestSkipped('client sample not present (nra/outbox is untracked)');
    }

    $headers = dgt_headers(DGT_EXAMPLE_XLSX);
    // Confirm the fixture really does carry the duplicated headers this test
    // is about (guards against a silent template edit invalidating the test).
    expect(array_count_values($headers)['Status 1'] ?? 0)->toBe(2)
        ->and(array_count_values($headers)['Barcode (IN)'] ?? 0)->toBe(2)
        ->and(array_count_values($headers)['Disinfestation Date'] ?? 0)->toBe(3);

    $rows = dgt_realRows(DGT_EXAMPLE_XLSX, 2, 2);
    expect($rows)->toHaveCount(1);

    // The FIRST physical occurrence of each duplicated header carries real
    // data ("AA18049" / "IN" / "2026-01-15" — confirmed by direct cell
    // inspection of the source file). Because PHP's associative row array can
    // only hold ONE value per header STRING, the LAST physical column with
    // the same header name always wins — and in this row it is blank. The
    // real data is unrecoverable: no columnMap choice can select "the first
    // occurrence" because the row array never kept it.
    expect($rows[0]['Barcode (IN)'])->toBe('')
        ->and($rows[0]['Status 1'])->toBe('')
        ->and($rows[0]['Disinfestation Date'])->toBe('');
});

test('the REAL vendor row-reader loses the same duplicate-header data on the client\'s own live ~5MB batch list', function () {
    if (! is_file(DGT_BATCHLIST_XLSX)) {
        $this->markTestSkipped('client sample not present (nra/inbox is untracked)');
    }

    $rows = dgt_realRows(DGT_BATCHLIST_XLSX, 2, 2);
    expect($rows)->toHaveCount(1);

    // Row 1 of the client's OWN live file: Barcode (IN) = "AA40822" and
    // Disinfestation Date = a real Excel serial date in the FIRST physical
    // occurrence of each header (confirmed by direct cell inspection), both
    // followed by blank duplicate columns later in the row. (This file's
    // trailing duplicate cells were never written at all, so PhpSpreadsheet
    // hands back `null` rather than `''` for them — either way the real
    // value from the first occurrence is gone.)
    expect($rows[0]['Barcode (IN)'])->toBeEmpty()
        ->and($rows[0]['Disinfestation Date'])->toBeEmpty();
});

test('the duplicate-header collapse reaches the saved Document: barcode_in/disinfestation_date are silently dropped end-to-end', function () {
    if (! is_file(DGT_EXAMPLE_XLSX)) {
        $this->markTestSkipped('client sample not present (nra/outbox is untracked)');
    }

    dgt_series('REG');
    $u = dgt_admin();
    $this->actingAs($u);

    $headers = dgt_headers(DGT_EXAMPLE_XLSX);
    $columnMap = dgt_columnMap($headers);
    // The importer's own guess lists resolve unambiguously to these headers
    // for the shipped template — confirms the columnMap under test matches
    // what the real "Import Excel / CSV" modal would auto-select.
    expect($columnMap)->toMatchArray([
        'barcode_in' => 'Barcode (IN)',
        'status_1' => 'Status 1',
        'disinfestation_date' => 'Disinfestation Date',
        'series' => 'Series',
    ]);
    // The auto-guessed map also picks up 'torre' => 'Torre' — a SEPARATE
    // confirmed bug (blank Torre cells reject the row outright, see the
    // dedicated test below) that would otherwise mask the finding under
    // test here. Drop it so this test isolates the duplicate-header defect.
    unset($columnMap['torre']);

    $rows = dgt_realRows(DGT_EXAMPLE_XLSX, 2, 2);
    $import = dgt_run($rows, $columnMap, $u->id);

    expect(dgt_failures($import))->toBe([]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'R642/001')->first();
    expect($doc)->not->toBeNull();

    // The source row visibly carries "AA18049" / "2026-01-15" in the FIRST
    // physical "Barcode (IN)" / "Disinfestation Date" column — but the saved
    // document ends up with neither, because the row never reached the
    // importer with that data (see the two tests above).
    expect($doc->barcode_in)->toBeNull()
        ->and($doc->disinfestation_date)->toBeNull();
});

// ════════════════════════════════════════════════════════════════════════
//  Series dependency
// ════════════════════════════════════════════════════════════════════════

test('series resolves via the "CODE: Title" legacy format', function () {
    dgt_series('REG');
    $u = dgt_admin();
    $this->actingAs($u);

    $import = dgt_run(
        [['Series' => 'REG: Registers Private Practice', 'Catalogue Identifier' => 'DOC-1']],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier'],
        $u->id,
    );

    expect(dgt_failures($import))->toBe([]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'DOC-1')->first();
    expect($doc)->not->toBeNull()
        ->and($doc->series_id)->toBe(Series::where('code', 'REG')->value('id'));
});

test('an unknown series code fails with a clear message, not generic_validation', function () {
    $u = dgt_admin();
    $this->actingAs($u);

    $import = dgt_run(
        [['Series' => 'NOPE', 'Catalogue Identifier' => 'DOC-2']],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier'],
        $u->id,
    );

    $failures = dgt_failures($import);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->not->toContain('generic_validation')
        ->and(strtolower($failures[0]))->toContain('not found');
});

// ════════════════════════════════════════════════════════════════════════
//  Authority / multi-creator dependency
// ════════════════════════════════════════════════════════════════════════

test('authority_identifier resolves a single R-code and attaches it as primary', function () {
    dgt_series('REG');
    Authority::create(['identifier' => 'R520', 'surname' => 'Farrugia', 'entity_type' => 'Notary']);
    $u = dgt_admin();
    $this->actingAs($u);

    $import = dgt_run(
        [['Series' => 'REG', 'Catalogue Identifier' => 'DOC-3', 'Identifier' => 'R520']],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'authority_identifier' => 'Identifier'],
        $u->id,
    );

    expect(dgt_failures($import))->toBe([]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'DOC-3')->first();
    expect($doc->authorities()->count())->toBe(1)
        ->and((bool) $doc->authorities()->first()->pivot->is_primary)->toBeTrue();
});

test('multiple ";"-delimited authority identifiers attach: first primary, rest co-creators (RFQ Appendix-2 §xi)', function () {
    dgt_series('REG');
    Authority::create(['identifier' => '520', 'surname' => 'Gatt', 'entity_type' => 'Notary']);
    Authority::create(['identifier' => '178', 'surname' => 'Cauchi', 'entity_type' => 'Notary']);
    $u = dgt_admin();
    $this->actingAs($u);

    $import = dgt_run(
        [['Series' => 'REG', 'Catalogue Identifier' => 'DOC-4', 'Identifier' => '520; 178']],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'authority_identifier' => 'Identifier'],
        $u->id,
    );

    expect(dgt_failures($import))->toBe([]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'DOC-4')->first();
    $pivots = $doc->authorities()->get()->keyBy('identifier');
    expect($pivots)->toHaveCount(2)
        ->and((bool) $pivots['520']->pivot->is_primary)->toBeTrue()
        ->and((bool) $pivots['178']->pivot->is_primary)->toBeFalse();
});

test('a stray empty piece in a ";"-delimited identifier cell is dropped silently, both real ids still attach', function () {
    dgt_series('REG');
    Authority::create(['identifier' => '520', 'surname' => 'Gatt', 'entity_type' => 'Notary']);
    Authority::create(['identifier' => '178', 'surname' => 'Cauchi', 'entity_type' => 'Notary']);
    $u = dgt_admin();
    $this->actingAs($u);

    $import = dgt_run(
        [['Series' => 'REG', 'Catalogue Identifier' => 'DOC-5', 'Identifier' => '520; ; 178']],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'authority_identifier' => 'Identifier'],
        $u->id,
    );

    expect(dgt_failures($import))->toBe([]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'DOC-5')->first();
    expect($doc->authorities()->count())->toBe(2);
});

test('creator_legacy_text free-text resolves by surname and logs the match method in extra', function () {
    dgt_series('REG');
    Authority::create(['identifier' => 'R900', 'surname' => 'Zammit', 'entity_type' => 'Notary']);
    $u = dgt_admin();
    $this->actingAs($u);

    $import = dgt_run(
        [['Series' => 'REG', 'Catalogue Identifier' => 'DOC-6', 'Creator' => 'Zammit']],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'creator_legacy_text' => 'Creator'],
        $u->id,
    );

    expect(dgt_failures($import))->toBe([]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'DOC-6')->first();
    expect($doc->authorities()->count())->toBe(1)
        ->and($doc->extra->creator_match_log)->toBe('matched:surname_exact');
});

test('F-009: an ambiguous creator surname is NOT auto-attached; candidates are recorded for manual review', function () {
    dgt_series('REG');
    Authority::create(['identifier' => 'R901', 'surname' => 'Borg', 'entity_type' => 'Notary']);
    Authority::create(['identifier' => 'R902', 'surname' => 'Borg', 'entity_type' => 'Notary']);
    $u = dgt_admin();
    $this->actingAs($u);

    $import = dgt_run(
        [['Series' => 'REG', 'Catalogue Identifier' => 'DOC-7', 'Creator' => 'Borg']],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'creator_legacy_text' => 'Creator'],
        $u->id,
    );

    // F-009 is a SOFT miss — the row still imports, just without a pivot.
    expect(dgt_failures($import))->toBe([]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'DOC-7')->first();
    expect($doc->authorities()->count())->toBe(0)
        ->and($doc->extra->creator_match_log)->toBe('ambiguous_2_candidates')
        ->and($doc->extra->ambiguous_candidates)->toHaveCount(2);
});

// ════════════════════════════════════════════════════════════════════════
//  Batch dependency (dedup-or-create) — Task 8 (B5)
// ════════════════════════════════════════════════════════════════════════

test('batch_number auto-creates the missing batch inside the resolved repository', function () {
    $repo = Repository::factory()->create(['code' => 'DGT1']);
    dgt_series('REG');
    $u = dgt_admin($repo->id);
    $this->actingAs($u);

    expect(Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 7)->exists())->toBeFalse();

    $import = dgt_run(
        [['Series' => 'REG', 'Catalogue Identifier' => 'DOC-8', 'RAS Batch 1' => '7']],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'batch_number' => 'RAS Batch 1'],
        $u->id,
    );

    expect(dgt_failures($import))->toBe([]);
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 7)->where('repository_id', $repo->id)->first();
    expect($batch)->not->toBeNull()
        ->and($batch->type)->toBe('MAIN_COLLECTION');
});

test('a forbidden batch number (34) is rejected with a clear message; no batch or document is created', function () {
    $repo = Repository::factory()->create(['code' => 'DGT2']);
    dgt_series('REG');
    $u = dgt_admin($repo->id);
    $this->actingAs($u);

    $import = dgt_run(
        [['Series' => 'REG', 'Catalogue Identifier' => 'DOC-9', 'RAS Batch 1' => '34']],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'batch_number' => 'RAS Batch 1'],
        $u->id,
    );

    $failures = dgt_failures($import);
    expect($failures)->toHaveCount(1)
        ->and(strtolower($failures[0]))->toContain('reserved');
    expect(Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 34)->exists())->toBeFalse()
        ->and(Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'DOC-9')->exists())->toBeFalse();
});

test('REGRESSION (bug #13): a soft-deleted batch sharing (batch_number, repository_id) is RESTORED on re-import, not collided', function () {
    $repo = Repository::factory()->create(['code' => 'DGT3']);
    dgt_series('REG');
    $u = dgt_admin($repo->id);
    $this->actingAs($u);

    // Real client values (Series REG + a RAS Batch number that appears in the
    // client's document file), isolated to the batch-resolution path. Dirty
    // state: that batch already exists in this repo but soft-deleted — the state
    // the client's DB was left in after prior cleanups.
    $batchNo = 19; // RAS Batch 1 of the client's example_document_import.xlsx first row
    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => $batchNo, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ])->delete();

    $import = dgt_run(
        [['Series' => 'REG', 'Catalogue Identifier' => 'R642/001', 'RAS Batch 1' => (string) $batchNo]],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'batch_number' => 'RAS Batch 1'],
        $u->id,
    );

    // The soft-deleted batch is REUSED (restored), not left colliding on the
    // (batch_number, repository_id) unique index — the row imports cleanly.
    expect(dgt_failures($import))->toBe([]);
    expect(Batch::withoutGlobalScope(RepositoryScope::class)->withTrashed()->where('batch_number', $batchNo)->where('repository_id', $repo->id)->count())
        ->toBe(1); // reused, not duplicated
    expect(Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', $batchNo)->where('repository_id', $repo->id)->exists())
        ->toBeTrue(); // now live again
});

// ════════════════════════════════════════════════════════════════════════
//  Box dependency — Task 8 (B5)
// ════════════════════════════════════════════════════════════════════════

test('current_box_number auto-creates the box inside the document\'s resolved batch', function () {
    $repo = Repository::factory()->create(['code' => 'DGT4']);
    dgt_series('REG');
    $u = dgt_admin($repo->id);
    $this->actingAs($u);

    $import = dgt_run(
        [['Series' => 'REG', 'Catalogue Identifier' => 'DOC-11', 'RAS Batch 1' => '3', 'RAS Box 1' => '55']],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'batch_number' => 'RAS Batch 1', 'current_box_number' => 'RAS Box 1'],
        $u->id,
    );

    expect(dgt_failures($import))->toBe([]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'DOC-11')->first();
    expect($doc->current_box_id)->not->toBeNull();
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->find($doc->current_box_id);
    expect($box->box_number)->toBe('55')
        ->and((int) $box->batch_id)->toBe((int) $doc->batch_id);
});

test('current_box_barcode resolves a SPECIFIC existing box and never creates one on a miss', function () {
    dgt_series('REG');
    $u = dgt_admin();
    $this->actingAs($u);

    $before = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->count();

    $import = dgt_run(
        [['Series' => 'REG', 'Catalogue Identifier' => 'DOC-12', 'Current box barcode' => 'NO-SUCH-BARCODE']],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'current_box_barcode' => 'Current box barcode'],
        $u->id,
    );

    expect(dgt_failures($import))->toBe([]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'DOC-12')->first();
    expect($doc->current_box_id)->toBeNull()
        ->and(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->count())->toBe($before);
});

test('B5: a box whose batch differs from the document\'s own batch fails the row cleanly, no half-saved document', function () {
    $repo = Repository::factory()->create(['code' => 'DGT5']);
    dgt_series('REG');
    $u = dgt_admin($repo->id);
    $this->actingAs($u);

    $batchA = Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => 20, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true]);
    $batchB = Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => 21, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true]);
    $box = Box::factory()->create(['batch_id' => $batchB->id, 'barcode' => 'BC-MISMATCH', 'box_number' => 'BX-1', 'box_type' => 'RAS']);

    $docsBefore = Document::withoutGlobalScope(RepositoryScope::class)->count();

    $import = dgt_run(
        [['Series' => 'REG', 'Catalogue Identifier' => 'DOC-13', 'RAS Batch 1' => '20', 'Current box barcode' => 'BC-MISMATCH']],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'batch_number' => 'RAS Batch 1', 'current_box_barcode' => 'Current box barcode'],
        $u->id,
    );

    $failures = dgt_failures($import);
    expect($failures)->toHaveCount(1)
        ->and(strtolower($failures[0]))->toContain('batch');
    expect(Document::withoutGlobalScope(RepositoryScope::class)->count())->toBe($docsBefore);
});

test('REGRESSION (bug #14): a soft-deleted box sharing (batch_id, box_number) is RESTORED on re-import, not duplicated', function () {
    $repo = Repository::factory()->create(['code' => 'DGT6']);
    dgt_series('REG');
    $u = dgt_admin($repo->id);
    $this->actingAs($u);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => 9, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true]);
    Box::factory()->create(['batch_id' => $batch->id, 'box_number' => 'DUPBOX', 'box_type' => 'RAS'])->delete();

    $import = dgt_run(
        [['Series' => 'REG', 'Catalogue Identifier' => 'DOC-14', 'RAS Batch 1' => '9', 'RAS Box 1' => 'DUPBOX']],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'batch_number' => 'RAS Batch 1', 'current_box_number' => 'RAS Box 1'],
        $u->id,
    );

    expect(dgt_failures($import))->toBe([]);

    // EntityResolver::resolveBox()'s (batch_id, box_number) branch now looks at
    // trashed rows and RESTORES the soft-deleted box. There is no unique index
    // on (batch_id, box_number), so without this it would silently INSERT a
    // SECOND live row for the same physical box. Exactly ONE live row must exist.
    $liveBoxes = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->where('batch_id', $batch->id)->where('box_number', 'DUPBOX')->whereNull('deleted_at')->get();
    expect($liveBoxes)->toHaveCount(1); // restored, not duplicated
});

// ════════════════════════════════════════════════════════════════════════
//  RFQ App.1 invariants
// ════════════════════════════════════════════════════════════════════════

test('RFQ App.1 #2: placing a document in Batch 50 without a wills series is rejected', function () {
    $repo = Repository::factory()->create(['code' => 'DGT7']);
    dgt_series('REG', wills: false);
    $u = dgt_admin($repo->id);
    $this->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => 50, 'repository_id' => $repo->id, 'type' => 'NOTARY_ACCESSION', 'is_active' => true]);

    $import = dgt_run(
        [['Series' => 'REG', 'Catalogue Identifier' => 'DOC-15', 'RAS Batch 1' => '50']],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'batch_number' => 'RAS Batch 1'],
        $u->id,
    );

    expect(dgt_failures($import))->toHaveCount(1);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'DOC-15')->exists())->toBeFalse();
});

test('RFQ App.1 #5: PERM_OUT status without a disinfestation_date fails the row with a clear message', function () {
    dgt_series('REG');
    $u = dgt_admin();
    $this->actingAs($u);

    $import = dgt_run(
        [['Series' => 'REG', 'Catalogue Identifier' => 'DOC-16', 'Status 1' => 'PERM_OUT']],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'status_1' => 'Status 1'],
        $u->id,
    );

    $failures = dgt_failures($import);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->not->toContain('generic_validation')
        ->and(strtolower($failures[0]))->toContain('disinfestation');
});

test('PERM_OUT status WITH a disinfestation_date succeeds and mirrors onto the current box (Task 8 B4)', function () {
    $repo = Repository::factory()->create(['code' => 'DGT8']);
    dgt_series('REG');
    $u = dgt_admin($repo->id);
    $this->actingAs($u);

    $import = dgt_run(
        [[
            'Series' => 'REG', 'Catalogue Identifier' => 'DOC-17',
            'RAS Batch 1' => '5', 'RAS Box 1' => '10',
            'Status 1' => 'PERM_OUT', 'Disinfestation Date' => '2026-01-10',
        ]],
        [
            'series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier',
            'batch_number' => 'RAS Batch 1', 'current_box_number' => 'RAS Box 1',
            'status_1' => 'Status 1', 'disinfestation_date' => 'Disinfestation Date',
        ],
        $u->id,
    );

    expect(dgt_failures($import))->toBe([]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'DOC-17')->first();
    expect($doc->current_box_id)->not->toBeNull();
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->find($doc->current_box_id);
    expect($box->barcode_status)->toBe('PERM_OUT');
});

// ════════════════════════════════════════════════════════════════════════
//  torre — NOT NULL rejection of the (overwhelmingly common) blank cell
// ════════════════════════════════════════════════════════════════════════

test('a blank "Torre" cell fails the row with "fill that column", even though the DB column defaults to false', function () {
    dgt_series('REG');
    $u = dgt_admin();
    $this->actingAs($u);

    $import = dgt_run(
        // A perfectly ordinary row: every real spreadsheet leaves the rare
        // legacy "Torre" flag blank for the vast majority of documents.
        [['Series' => 'REG', 'Catalogue Identifier' => 'DOC-TORRE', 'Torre' => '']],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'torre' => 'Torre'],
        $u->id,
    );

    $failures = dgt_failures($import);
    // Confirmed BUG: `torre` ImportColumn has no ->ignoreBlankState() and no
    // fillRecordUsing() override, so a blank cell casts to `null`
    // (ImportColumn::castStateItem() returns null for any blank() state
    // BEFORE the ->boolean() cast even runs) and gets written verbatim onto
    // the model — overriding the schema's `->default(false)` and hitting
    // the `torre` NOT NULL constraint on every single ordinary row.
    expect($failures)->toHaveCount(1)
        ->and(strtolower($failures[0]))->toContain('torre');
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'DOC-TORRE')->exists())->toBeFalse();
});

// ════════════════════════════════════════════════════════════════════════
//  Per-row savepoint LEAK — any saveRecord() failure corrupts the
//  connection's transaction depth for the REST OF THE PROCESS
// ════════════════════════════════════════════════════════════════════════

test('a row that fails inside saveRecord() leaks DocumentImporter\'s per-row savepoint, corrupting the connection for every subsequent operation', function () {
    dgt_series('REG');
    $u = dgt_admin();
    $this->actingAs($u);

    // Baseline: RefreshDatabase wraps this test in exactly ONE transaction.
    expect(DB::transactionLevel())->toBe(1);

    // Trigger ANY genuine saveRecord()-level failure (the "torre" NOT NULL
    // bug above is a convenient, real, reproducible one — the mechanism
    // below is independent of Torre specifically).
    $import = dgt_run(
        [['Series' => 'REG', 'Catalogue Identifier' => 'DOC-LEAK', 'Torre' => '']],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'torre' => 'Torre'],
        $u->id,
    );
    expect(dgt_failures($import))->toHaveCount(1);

    // Capture the corrupted level, then restore the connection immediately
    // (try/finally-style) so this test does not itself take down every
    // OTHER test in the suite that happens to run afterwards.
    $levelAfterFailure = DB::transactionLevel();
    while (DB::transactionLevel() > 1) {
        DB::rollBack();
    }

    // Confirmed BUG: DocumentImporter::beforeSave() unconditionally opens a
    // savepoint (`DB::beginTransaction()`) and sets $rowSavepointOpen=true.
    // The ONLY place that closes it is afterSave() — but Filament's own
    // hook chain (`beforeSave(); saveRecord(); afterSave();` in
    // Importer::__invoke()) skips afterSave() entirely whenever saveRecord()
    // itself throws. The "defensive" cleanup at the top of the NEXT
    // beforeSave() call does not fully compensate: after processing a
    // single failing row, the connection is left with a permanently
    // incremented transaction depth (proven directly via
    // DB::transactionLevel(), not inferred). In production this same
    // Importer instance keeps processing every remaining row of a 500-row
    // chunk (HayderHatem's ImportExcel::handle() loop), and the connection
    // is reused by the rest of that queue worker's lifetime — so ONE bad
    // row silently corrupts everything that runs after it.
    expect($levelAfterFailure)->toBeGreaterThan(1);
});

// ════════════════════════════════════════════════════════════════════════
//  Bug #22 — auto-generated identifier when blank
// ════════════════════════════════════════════════════════════════════════

test('Bug #22: a blank identifier + blank catalogue_identifier gets a deterministic AUTO id, and re-importing the identical row updates (not duplicates)', function () {
    $this->markTestSkipped('WAVE 2 — needs a design decision, not a mechanical fix. A fully-blank-identifier row has NO stable identity, so the only re-import dedup key is a content fingerprint (sha1 of the mapped row). But that fingerprint is identical for two GENUINELY DISTINCT rows whose mapped columns match — deduping on it silently MERGES them (proven: a real 25-row file collapsed to 23). Correctly distinguishing "same row re-imported" from "two identical-content rows in one file" requires a stable per-row source key (e.g. a source row number), which is a schema/RFQ decision. Leaving the safer current behaviour (no over-merge) until then.');
    $repo = Repository::factory()->create(['code' => 'DGT9']);
    dgt_series('REG');
    $u = dgt_admin($repo->id);
    $this->actingAs($u);

    $row = ['Series' => 'REG', 'Practice' => 'Test Practice', 'Note' => 'first pass'];
    $columnMap = ['series' => 'Series', 'practice' => 'Practice', 'notes' => 'Note'];

    dgt_run([$row], $columnMap, $u->id);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->count())->toBe(1);

    // Re-import the EXACT same row again.
    dgt_run([$row], $columnMap, $u->id);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->count())->toBe(1); // still 1, not 2
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->first();
    expect($doc->identifier)->not->toBeEmpty()
        ->and($doc->identifier)->not->toBe('AUTO-');
});

test('Bug #22: a blank identifier with a catalogue_identifier present falls back to it, and re-import matches the same document', function () {
    dgt_series('REG');
    $u = dgt_admin();
    $this->actingAs($u);

    $row = ['Series' => 'REG', 'Catalogue Identifier' => 'CAT-42'];
    $columnMap = ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier'];

    dgt_run([$row], $columnMap, $u->id);
    $first = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'CAT-42')->first();
    expect($first->identifier)->toBe('CAT-42');

    dgt_run([array_merge($row, ['Note' => 'updated on re-import'])], array_merge($columnMap, ['notes' => 'Note']), $u->id);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'CAT-42')->count())->toBe(1);
    $second = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'CAT-42')->first();
    expect($second->id)->toBe($first->id)
        ->and($second->notes)->toBe('updated on re-import');
});

// ════════════════════════════════════════════════════════════════════════
//  Excel numeric-cell artefacts
// ════════════════════════════════════════════════════════════════════════

test('F-005: volume_number normalises the Excel float artefact "2.0" to "2" but keeps composite refs verbatim', function () {
    dgt_series('REG');
    $u = dgt_admin();
    $this->actingAs($u);

    $import = dgt_run(
        [
            ['Series' => 'REG', 'Catalogue Identifier' => 'DOC-18A', 'Volume' => '2.0'],
            ['Series' => 'REG', 'Catalogue Identifier' => 'DOC-18B', 'Volume' => '180A/181'],
        ],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'volume_number' => 'Volume'],
        $u->id,
    );

    expect(dgt_failures($import))->toBe([]);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'DOC-18A')->value('volume_number'))->toBe('2')
        ->and(Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'DOC-18B')->value('volume_number'))->toBe('180A/181');
});

test('BUG-06: current_box_number normalises "1.0" to "1" but keeps an alphanumeric box ref ("180A") verbatim', function () {
    $repo = Repository::factory()->create(['code' => 'DGT10']);
    dgt_series('REG');
    $u = dgt_admin($repo->id);
    $this->actingAs($u);

    $import = dgt_run(
        [
            ['Series' => 'REG', 'Catalogue Identifier' => 'DOC-19A', 'RAS Batch 1' => '1', 'RAS Box 1' => '1.0'],
            ['Series' => 'REG', 'Catalogue Identifier' => 'DOC-19B', 'RAS Batch 1' => '1', 'RAS Box 1' => '180A'],
        ],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'batch_number' => 'RAS Batch 1', 'current_box_number' => 'RAS Box 1'],
        $u->id,
    );

    expect(dgt_failures($import))->toBe([]);
    $docA = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'DOC-19A')->first();
    $docB = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'DOC-19B')->first();
    expect($docA->ras_box_1)->toBe('1')
        ->and(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->find($docA->current_box_id)->box_number)->toBe('1')
        ->and($docB->ras_box_1)->toBe('180A')
        ->and(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->find($docB->current_box_id)->box_number)->toBe('180A');
});

test('a numeric cell in a string column (part_number arriving as an int) imports, not "must be a string"', function () {
    dgt_series('REG');
    $u = dgt_admin();
    $this->actingAs($u);

    $import = dgt_run(
        [['Series' => 'REG', 'Catalogue Identifier' => 'DOC-20', 'Part Number' => 3]], // genuine PHP int, as a numeric Excel cell arrives
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'part_number' => 'Part Number'],
        $u->id,
    );

    expect(dgt_failures($import))->toBe([]);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'DOC-20')->value('part_number'))->toBe('3');
});

// ════════════════════════════════════════════════════════════════════════
//  Performance / streaming — a real slice of the ~5MB live batch list
// ════════════════════════════════════════════════════════════════════════

test('streaming a real 25-row slice of the client\'s own ~5MB batch list end-to-end completes, isolates per-row failures, and creates documents', function () {
    if (! is_file(DGT_BATCHLIST_XLSX)) {
        $this->markTestSkipped('client sample not present (nra/inbox is untracked)');
    }

    $repo = Repository::factory()->create(['code' => 'NRA']);
    dgt_series('REG');
    dgt_series('RWL', wills: true);
    dgt_series('IDX');
    $u = dgt_admin($repo->id);
    $this->actingAs($u);

    $headers = dgt_headers(DGT_BATCHLIST_XLSX);
    $columnMap = dgt_columnMap($headers);
    expect($columnMap)->toHaveKey('series');
    // Excludes the separately-confirmed 'torre' NOT NULL bug (see the
    // dedicated test below) so THIS test isolates streaming/perf behaviour
    // over the real multi-dependency graph instead of failing every row on
    // an unrelated defect.
    unset($columnMap['torre']);

    $rows = dgt_realRows(DGT_BATCHLIST_XLSX, 2, 26);
    expect($rows)->toHaveCount(25);

    $import = dgt_run($rows, $columnMap, $u->id);

    // The whole 25-row slice is real production data behind a real FK graph
    // (Series REG/RWL/IDX, auto-created Batch 1) — it must import CLEANLY
    // end-to-end, and per-row failures (if any) must never take down the
    // whole batch (Filament's chunk `allowFailures()` contract).
    expect($import->processed_rows)->toBe(25)
        ->and($import->successful_rows)->toBeGreaterThan(0)
        ->and($import->successful_rows + count(dgt_failures($import)))->toBe(25)
        ->and(Document::withoutGlobalScope(RepositoryScope::class)->count())->toBe($import->successful_rows)
        ->and(Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 1)->where('repository_id', $repo->id)->exists())->toBeTrue();
});

// ════════════════════════════════════════════════════════════════════════
//  Per-row savepoint atomicity
// ════════════════════════════════════════════════════════════════════════

test('a B5 batch/box mismatch failure leaves the per-row savepoint clean for the NEXT row in the same chunk', function () {
    $repo = Repository::factory()->create(['code' => 'DGT11']);
    dgt_series('REG');
    $u = dgt_admin($repo->id);
    $this->actingAs($u);

    $batchA = Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => 30, 'repository_id' => $repo->id, 'type' => 'NOTARY_ACCESSION', 'is_active' => true]);
    $batchB = Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => 31, 'repository_id' => $repo->id, 'type' => 'NOTARY_ACCESSION', 'is_active' => true]);
    Box::factory()->create(['batch_id' => $batchB->id, 'barcode' => 'BC-ATOMIC', 'box_number' => 'BX-A', 'box_type' => 'RAS']);

    $import = dgt_run(
        [
            // Row 1: mismatched batch/box → must fail cleanly.
            ['Series' => 'REG', 'Catalogue Identifier' => 'DOC-21A', 'RAS Batch 1' => '30', 'Current box barcode' => 'BC-ATOMIC'],
            // Row 2: perfectly valid → must still succeed in the SAME chunk.
            ['Series' => 'REG', 'Catalogue Identifier' => 'DOC-21B', 'RAS Batch 1' => '30'],
        ],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'batch_number' => 'RAS Batch 1', 'current_box_barcode' => 'Current box barcode'],
        $u->id,
    );

    expect($import->successful_rows)->toBe(1)
        ->and(dgt_failures($import))->toHaveCount(1);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'DOC-21A')->exists())->toBeFalse()
        ->and(Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'DOC-21B')->exists())->toBeTrue();
});
