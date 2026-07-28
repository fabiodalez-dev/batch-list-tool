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
use App\Support\BulkImport\Jobs\DeduplicatingImportExcel;
use App\Support\BulkImport\SpreadsheetHeaders;
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
 * Read a range of DATA rows from a real xlsx file through the REAL production
 * read path the streaming "Import Excel / CSV" button now dispatches —
 * {@see DeduplicatingImportExcel::readExcelRowsFromFile()} — via reflection
 * (it's `protected`). This is the vendor reader with the Bug #4 fix layered on
 * top (repeated headers de-duplicated by physical position), so the test
 * exercises the *actual* code path in production, not a re-implementation.
 *
 * @return array<int, array<string, mixed>>
 */
function dgt_realRows(string $filePath, int $startRow, int $endRow, int $headerOffset = 0, int $activeSheet = 0): array
{
    $job = new DeduplicatingImportExcel(
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
 * The UNFIXED vendor reader — keeps the pre-Bug #4 behaviour (rows keyed by
 * header string, so repeated columns collapse). Retained so the flagship tests
 * can assert the fix by CONTRAST: the vendor path still loses the data, ours
 * recovers it.
 *
 * @return array<int, array<string, mixed>>
 */
function dgt_vendorRows(string $filePath, int $startRow, int $endRow, int $headerOffset = 0, int $activeSheet = 0): array
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
//  Real-value anchors for the narrow single-field tests below. These are
//  NOT full streamed rows (those tests build minimal columnMaps on purpose,
//  isolating one FK/behaviour at a time) — but every cell VALUE here is
//  copied verbatim from direct inspection of the client's own real files, so
//  the narrow tests exercise real content instead of invented strings. Where
//  a test's edge case does not occur anywhere in the real files (e.g. an
//  unknown series code, a forbidden batch number, a blank disinfestation
//  date on a PERM_OUT row), the test mutates exactly the one cell the edge
//  case needs — see the inline comment at each call site.
// ════════════════════════════════════════════════════════════════════════

/**
 * The ONE real data row of the shipped example_document_import.xlsx
 * template (verified via direct cell inspection: Identifier=R642,
 * Catalogue Identifier=R642/001, Creator="Caruana, Vincenzo", Series=REG,
 * RAS Batch 1=19, RAS Box 1=98, Torre=blank — a perfectly ordinary row).
 */
const DGT_EXAMPLE_ROW = [
    'RAS Batch 1' => '19',
    'RAS Box 1' => '98',
    'Barcode (IN)' => 'AA18049',
    'Status 1' => 'IN',
    'Disinfestation Date' => '2026-01-15',
    'Catalogue Identifier' => 'R642/001',
    'NRA Location' => 'Archive Room 1',
    'Identifier' => 'R642',
    'Volume' => '1',
    'Creator' => 'Caruana, Vincenzo',
    'Dates' => '1870',
    'Document Type' => 'Register Volume',
    'Series' => 'REG',
    'Current Box' => 'RAS',
    'Torre' => '',
    'Accession' => 'ACC-2026-01',
];

/**
 * Real row from the client's own live ~5MB batch list (RAS Batch 1=7,
 * RAS Box 1=38, Catalogue Identifier=R47/001, Series in the full legacy
 * "CODE: Title" format) — confirmed via a league/csv column-position scan of
 * nra/inbox/2026-06-22_NAF_New_BATCH_LIST_04_06_26_sample.csv (duplicate
 * "Status 1"/"Barcode (IN)" headers make a header-keyed read unreliable, see
 * the flagship tests above, so these anchors were read by column position).
 */
const DGT_ROW_R47_001 = [
    'RAS Batch 1' => '7',
    'RAS Box 1' => '38',
    'Catalogue Identifier' => 'R47/001',
    'Series' => 'REG: Registers Private Practice',
];

/**
 * Real row from the client's live batch list: RAS Batch 1=2, RAS Box 1=52,
 * Catalogue Identifier=R52/002, Series=REG, Status 1="Perm Out" (the
 * client's actual free-text — NOT the "PERM_OUT" enum literal the importer's
 * status_1 cast requires; see the PERM_OUT tests below for why that one cell
 * is deliberately overridden), Disinfestation Date=2023-08-05.
 */
const DGT_ROW_R52_002 = [
    'RAS Batch 1' => '2',
    'RAS Box 1' => '52',
    'Catalogue Identifier' => 'R52/002',
    'Series' => 'REG',
    'Disinfestation Date' => '2023-08-05',
];

/**
 * A companion real row in the SAME batch/box (RAS Batch 1=2, RAS Box 1=52)
 * whose "Actual Volume" cell carries a letter-suffixed, non-float value
 * ("38A") — used to ground the "keeps composite refs verbatim" half of the
 * F-005 test in a real alphanumeric value instead of an invented one.
 */
const DGT_ROW_R52_038A = [
    'Catalogue Identifier' => 'R52/038A',
    'Series' => 'REG',
];

/**
 * Real row: Catalogue Identifier=R530/001_IDX, Series=IDX (legacy format),
 * Creator="Giuseppe Zammit" — a genuine 2-word "Firstname Surname" free-text
 * cell whose last word ("Zammit") is the surname the importer's
 * creator_legacy_text resolver extracts.
 */
const DGT_ROW_R530_ZAMMIT = [
    'Catalogue Identifier' => 'R530/001_IDX',
    'Series' => 'IDX: Indexes of Registers Private Practice',
    'Creator' => 'Giuseppe Zammit',
];

/**
 * Real row: Catalogue Identifier=R536/001_IDX, Series=IDX, Creator=
 * "Herman Borg" — same shape as DGT_ROW_R530_ZAMMIT, used for the F-009
 * ambiguous-surname test (the DB fixture deliberately creates TWO "Borg"
 * authorities; the real surname text is what makes the row ambiguous).
 */
const DGT_ROW_R536_BORG = [
    'Catalogue Identifier' => 'R536/001_IDX',
    'Series' => 'IDX: Indexes of Registers Private Practice',
    'Creator' => 'Herman Borg',
];

/**
 * Real row: Catalogue Identifier=R520R178/001_IDX, Series=IDX, Creator=
 * "Calcedonio Gatt; Angelo Cauchi" — the client's OWN catalogue-identifier
 * convention concatenates the two co-creators' R-codes ("R520" + "R178"),
 * which is exactly the ";"-delimited "520; 178" example already cited as
 * the "Real example" in DocumentImporter's own class docblock (RFQ
 * Appendix-2 §xi). The Identifier cell values below are derived from that
 * real convention, not invented.
 */
const DGT_ROW_R520R178 = [
    'Catalogue Identifier' => 'R520R178/001_IDX',
    'Series' => 'IDX: Indexes of Registers Private Practice',
    'Creator' => 'Calcedonio Gatt; Angelo Cauchi',
];

// ════════════════════════════════════════════════════════════════════════
//  FLAGSHIP — Bug #4: duplicate-header columns USED to collapse to blank on
//  the production read path (both the shipped template AND the client's actual
//  live 5MB working file share this legacy layout, repeating "Barcode (IN)",
//  "Status 1" and "Disinfestation Date" at different physical columns). The
//  DeduplicatingImportExcel job now de-duplicates them BY POSITION, so the
//  first (data-bearing) occurrence keeps its header verbatim and survives.
// ════════════════════════════════════════════════════════════════════════

test('Bug #4: the de-duplicating reader RECOVERS the first-occurrence data the vendor reader collapses, on the shipped example template', function () {
    if (! is_file(DGT_EXAMPLE_XLSX)) {
        $this->markTestSkipped('client sample not present (nra/outbox is untracked)');
    }

    $headers = dgt_headers(DGT_EXAMPLE_XLSX);
    // Confirm the fixture really does carry the duplicated headers this test
    // is about (guards against a silent template edit invalidating the test).
    expect(array_count_values($headers)['Status 1'] ?? 0)->toBe(2)
        ->and(array_count_values($headers)['Barcode (IN)'] ?? 0)->toBe(2)
        ->and(array_count_values($headers)['Disinfestation Date'] ?? 0)->toBe(3);

    // CONTRAST — the UNFIXED vendor reader keys the row by header string, so
    // the LAST physical column with each duplicated name (blank) overwrites
    // the FIRST (which holds the real "AA18049" / "IN" / "2026-01-15"). The
    // data is unrecoverable on that path.
    $vendor = dgt_vendorRows(DGT_EXAMPLE_XLSX, 2, 2);
    expect($vendor[0]['Barcode (IN)'])->toBe('')
        ->and($vendor[0]['Status 1'])->toBe('')
        ->and($vendor[0]['Disinfestation Date'])->toBe('');

    // FIX — the de-duplicating reader keeps the FIRST occurrence's key verbatim
    // (so existing column-map guesses still resolve to it) and suffixes the
    // later duplicates, so the real data survives under the plain header key.
    $rows = dgt_realRows(DGT_EXAMPLE_XLSX, 2, 2);
    expect($rows)->toHaveCount(1);
    expect($rows[0]['Barcode (IN)'])->toBe('AA18049')
        ->and($rows[0]['Status 1'])->toBe('IN')
        ->and($rows[0]['Disinfestation Date'])->toBe('2026-01-15');
    // The later duplicate columns are still present, just under distinct
    // occurrence-suffixed keys — they no longer clobber the first.
    expect($rows[0])->toHaveKeys(['Barcode (IN) (2)', 'Status 1 (2)', 'Disinfestation Date (2)', 'Disinfestation Date (3)']);
});

test('Bug #4: the de-duplicating reader recovers the first-occurrence Barcode (IN) on the client\'s own live ~5MB batch list', function () {
    if (! is_file(DGT_BATCHLIST_XLSX)) {
        $this->markTestSkipped('client sample not present (nra/inbox is untracked)');
    }

    // CONTRAST — the vendor reader loses it (the trailing duplicate columns
    // were never written, so PhpSpreadsheet hands back null/'' and that
    // overwrites the real first-occurrence value on the header-keyed path).
    $vendor = dgt_vendorRows(DGT_BATCHLIST_XLSX, 2, 2);
    expect($vendor[0]['Barcode (IN)'])->toBeEmpty();

    // FIX — row 1 of the client's OWN live file carries Barcode (IN) = "AA40822"
    // in the FIRST physical occurrence (confirmed by direct cell inspection);
    // the de-duplicating reader now delivers it intact.
    $rows = dgt_realRows(DGT_BATCHLIST_XLSX, 2, 2);
    expect($rows)->toHaveCount(1);
    expect($rows[0]['Barcode (IN)'])->toBe('AA40822');
    // Its first "Disinfestation Date" occurrence carries a real Excel serial
    // date (a numeric cell) — no longer blanked by the trailing duplicates.
    expect($rows[0]['Disinfestation Date'])->not->toBeEmpty();
});

test('Bug #4: the recovered duplicate-header data reaches the saved Document end-to-end (barcode_in / disinfestation_date)', function () {
    if (! is_file(DGT_EXAMPLE_XLSX)) {
        $this->markTestSkipped('client sample not present (nra/outbox is untracked)');
    }

    dgt_series('REG');
    $u = dgt_admin();
    $this->actingAs($u);

    $headers = dgt_headers(DGT_EXAMPLE_XLSX);
    $columnMap = dgt_columnMap($headers);
    // The importer's own guess lists resolve unambiguously to these headers
    // for the shipped template — and, crucially, to the FIRST occurrence
    // (whose key the de-dup keeps verbatim), which is where the data lives.
    expect($columnMap)->toMatchArray([
        'barcode_in' => 'Barcode (IN)',
        'status_1' => 'Status 1',
        'disinfestation_date' => 'Disinfestation Date',
        'series' => 'Series',
    ]);
    // The auto-guessed map also picks up 'torre' => 'Torre' — a SEPARATE
    // confirmed bug (blank Torre cells reject the row outright, see the
    // dedicated test below) that would otherwise mask the finding under
    // test here. Drop it so this test isolates the duplicate-header fix.
    unset($columnMap['torre']);

    $rows = dgt_realRows(DGT_EXAMPLE_XLSX, 2, 2);
    $import = dgt_run($rows, $columnMap, $u->id);

    expect(dgt_failures($import))->toBe([]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'R642/001')->first();
    expect($doc)->not->toBeNull();

    // The source row carries "AA18049" / "2026-01-15" in the FIRST physical
    // "Barcode (IN)" / "Disinfestation Date" column — and now the saved
    // document carries them too, because the de-duplicating reader preserved
    // the first occurrence all the way through to persistence.
    expect($doc->barcode_in)->toBe('AA18049')
        ->and($doc->disinfestation_date?->format('Y-m-d'))->toBe('2026-01-15');
});

test('Bug #4: SpreadsheetHeaders::dedupe keeps the first occurrence verbatim and suffixes the rest, position-preserving', function () {
    // Exactly the legacy shape: repeated names interleaved with unique ones,
    // plus a trailing null (vendor keys those by numeric position) and an
    // empty header (left collapsing — carries no import data).
    $raw = ['Identifier', 'Barcode (IN)', 'Status 1', 'Barcode (IN)', 'Status 1', 'Disinfestation Date', 'Disinfestation Date', 'Disinfestation Date', null, ''];

    expect(SpreadsheetHeaders::dedupe($raw))->toBe([
        'Identifier',
        'Barcode (IN)',
        'Status 1',
        'Barcode (IN) (2)',
        'Status 1 (2)',
        'Disinfestation Date',
        'Disinfestation Date (2)',
        'Disinfestation Date (3)',
        8,   // null header → keyed by its physical position, already unique
        '',  // empty header → left as-is (no import data ever maps to it)
    ]);
});

// ════════════════════════════════════════════════════════════════════════
//  Series dependency
// ════════════════════════════════════════════════════════════════════════

test('series resolves via the "CODE: Title" legacy format', function () {
    dgt_series('REG');
    $u = dgt_admin();
    $this->actingAs($u);

    // Real row: RAS Batch 1=7, Catalogue Identifier=R47/001, Series carried
    // in the full legacy "REG: Registers Private Practice" format the
    // client's own live batch list actually uses (see DGT_ROW_R47_001).
    $import = dgt_run(
        [DGT_ROW_R47_001],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier'],
        $u->id,
    );

    expect(dgt_failures($import))->toBe([]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'R47/001')->first();
    expect($doc)->not->toBeNull()
        ->and($doc->series_id)->toBe(Series::where('code', 'REG')->value('id'));
});

test('an unknown series code fails with a clear message, not generic_validation', function () {
    $u = dgt_admin();
    $this->actingAs($u);

    // Real Catalogue Identifier (R47/001), Series cell mutated to an
    // unknown code — no series code in either real file is unrecognised, so
    // this single cell is a deliberate edge-case override (see file header).
    $import = dgt_run(
        [array_merge(DGT_ROW_R47_001, ['Series' => 'NOPE'])],
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
    // Real Identifier=R642 / Catalogue Identifier=R642/001, surname "Caruana"
    // read straight off the real row's Creator cell ("Caruana, Vincenzo").
    Authority::create(['identifier' => 'R642', 'surname' => 'Caruana', 'entity_type' => 'Notary']);
    $u = dgt_admin();
    $this->actingAs($u);

    $import = dgt_run(
        [DGT_EXAMPLE_ROW],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'authority_identifier' => 'Identifier'],
        $u->id,
    );

    expect(dgt_failures($import))->toBe([]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'R642/001')->first();
    expect($doc->authorities()->count())->toBe(1)
        ->and((bool) $doc->authorities()->first()->pivot->is_primary)->toBeTrue();
});

test('multiple ";"-delimited authority identifiers attach: first primary, rest co-creators (RFQ Appendix-2 §xi)', function () {
    dgt_series('IDX');
    // "520" / "178" are the two R-codes embedded in the client's OWN
    // catalogue-identifier convention for this real row (R520R178/001_IDX =
    // R520 + R178) — see DGT_ROW_R520R178 and DocumentImporter's own class
    // docblock, which cites this exact "520; 178" pair as its real example.
    Authority::create(['identifier' => '520', 'surname' => 'Gatt', 'entity_type' => 'Notary']);
    Authority::create(['identifier' => '178', 'surname' => 'Cauchi', 'entity_type' => 'Notary']);
    $u = dgt_admin();
    $this->actingAs($u);

    $import = dgt_run(
        [array_merge(DGT_ROW_R520R178, ['Identifier' => '520; 178'])],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'authority_identifier' => 'Identifier'],
        $u->id,
    );

    expect(dgt_failures($import))->toBe([]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'R520R178/001_IDX')->first();
    $pivots = $doc->authorities()->get()->keyBy('identifier');
    expect($pivots)->toHaveCount(2)
        ->and((bool) $pivots['520']->pivot->is_primary)->toBeTrue()
        ->and((bool) $pivots['178']->pivot->is_primary)->toBeFalse();
});

test('a stray empty piece in a ";"-delimited identifier cell is dropped silently, both real ids still attach', function () {
    dgt_series('IDX');
    Authority::create(['identifier' => '520', 'surname' => 'Gatt', 'entity_type' => 'Notary']);
    Authority::create(['identifier' => '178', 'surname' => 'Cauchi', 'entity_type' => 'Notary']);
    $u = dgt_admin();
    $this->actingAs($u);

    // Same real row as above; the stray empty piece between the two real
    // R-codes is the one deliberate edge-case mutation this test needs.
    $import = dgt_run(
        [array_merge(DGT_ROW_R520R178, ['Identifier' => '520; ; 178'])],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'authority_identifier' => 'Identifier'],
        $u->id,
    );

    expect(dgt_failures($import))->toBe([]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'R520R178/001_IDX')->first();
    expect($doc->authorities()->count())->toBe(2);
});

test('creator_legacy_text free-text resolves by surname and logs the match method in extra', function () {
    dgt_series('IDX');
    // Real Creator cell "Giuseppe Zammit" (2-word Firstname Surname, no
    // semicolon) — the resolver's last-word split extracts "Zammit".
    Authority::create(['identifier' => 'R900', 'surname' => 'Zammit', 'entity_type' => 'Notary']);
    $u = dgt_admin();
    $this->actingAs($u);

    $import = dgt_run(
        [DGT_ROW_R530_ZAMMIT],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'creator_legacy_text' => 'Creator'],
        $u->id,
    );

    expect(dgt_failures($import))->toBe([]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'R530/001_IDX')->first();
    expect($doc->authorities()->count())->toBe(1)
        ->and($doc->extra->creator_match_log)->toBe('matched:surname_exact');
});

test('F-009: an ambiguous creator surname is NOT auto-attached; candidates are recorded for manual review', function () {
    dgt_series('IDX');
    // Real Creator cell "Herman Borg" — the DB fixture deliberately creates
    // TWO "Borg" authorities so this real surname collides on import.
    Authority::create(['identifier' => 'R901', 'surname' => 'Borg', 'entity_type' => 'Notary']);
    Authority::create(['identifier' => 'R902', 'surname' => 'Borg', 'entity_type' => 'Notary']);
    $u = dgt_admin();
    $this->actingAs($u);

    $import = dgt_run(
        [DGT_ROW_R536_BORG],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'creator_legacy_text' => 'Creator'],
        $u->id,
    );

    // F-009 is a SOFT miss — the row still imports, just without a pivot.
    expect(dgt_failures($import))->toBe([]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'R536/001_IDX')->first();
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

    // Real row: RAS Batch 1=7, Catalogue Identifier=R47/001 (DGT_ROW_R47_001).
    $import = dgt_run(
        [DGT_ROW_R47_001],
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

    // Real Catalogue Identifier (R47/001); RAS Batch 1 mutated to the
    // forbidden number — no real row ever carries a forbidden batch (RFQ
    // App.1 #1 is enforced upstream of the client's own workflow), so this
    // single cell is the deliberate edge-case override.
    $import = dgt_run(
        [array_merge(DGT_ROW_R47_001, ['RAS Batch 1' => '34'])],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'batch_number' => 'RAS Batch 1'],
        $u->id,
    );

    $failures = dgt_failures($import);
    expect($failures)->toHaveCount(1)
        ->and(strtolower($failures[0]))->toContain('reserved');
    expect(Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 34)->exists())->toBeFalse()
        ->and(Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'R47/001')->exists())->toBeFalse();
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

    // Real row: RAS Batch 1=2, RAS Box 1=52, Catalogue Identifier=R52/002.
    $import = dgt_run(
        [DGT_ROW_R52_002],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'batch_number' => 'RAS Batch 1', 'current_box_number' => 'RAS Box 1'],
        $u->id,
    );

    expect(dgt_failures($import))->toBe([]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'R52/002')->first();
    expect($doc->current_box_id)->not->toBeNull();
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->find($doc->current_box_id);
    expect($box->box_number)->toBe('52')
        ->and((int) $box->batch_id)->toBe((int) $doc->batch_id);
});

test('current_box_barcode resolves a SPECIFIC existing box and never creates one on a miss', function () {
    dgt_series('REG');
    $u = dgt_admin();
    $this->actingAs($u);

    $before = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->count();

    // Real Catalogue Identifier (R47/001); "current_box_barcode" has no
    // real-world column at all — neither real file ever populates a box
    // barcode (only "Current Box"/"RAS Box 1" numbers) — so a barcode that
    // is GUARANTEED not to exist is inherent to this test and cannot be
    // sourced from the files.
    $import = dgt_run(
        [array_merge(DGT_ROW_R47_001, ['Current box barcode' => 'NO-SUCH-BARCODE'])],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'current_box_barcode' => 'Current box barcode'],
        $u->id,
    );

    expect(dgt_failures($import))->toBe([]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'R47/001')->first();
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

    // Real Catalogue Identifier (R47/001); RAS Batch 1 mutated to match the
    // fixture batchA (20) — "current_box_barcode" again has no real column
    // (see the test above), so BC-MISMATCH stays a deliberate fixture value.
    $import = dgt_run(
        [array_merge(DGT_ROW_R47_001, ['RAS Batch 1' => '20', 'Current box barcode' => 'BC-MISMATCH'])],
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

    // Real Catalogue Identifier (R47/001); RAS Batch 1 mutated to match the
    // fixture batch (9); 'DUPBOX' is the fixture's own box_number marker so
    // the row can collide with the soft-deleted box under test.
    $import = dgt_run(
        [array_merge(DGT_ROW_R47_001, ['RAS Batch 1' => '9', 'RAS Box 1' => 'DUPBOX'])],
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

    // Real Catalogue Identifier (R47/001), real non-wills Series (REG);
    // RAS Batch 1 mutated to 50 — every REAL batch-50 row in the client's
    // own live file is already RWL (wills), i.e. the client's data never
    // actually violates this invariant, so the batch number is the one
    // deliberate edge-case override needed to exercise the rejection path.
    $import = dgt_run(
        [array_merge(DGT_ROW_R47_001, ['RAS Batch 1' => '50'])],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'batch_number' => 'RAS Batch 1'],
        $u->id,
    );

    expect(dgt_failures($import))->toHaveCount(1);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'R47/001')->exists())->toBeFalse();
});

test('RFQ App.1 #5: PERM_OUT status without a disinfestation_date fails the row with a clear message', function () {
    dgt_series('REG');
    $u = dgt_admin();
    $this->actingAs($u);

    // Real Catalogue Identifier + Series (R52/002, REG). Status 1 is
    // overridden to the "PERM_OUT" enum literal the importer's cast checks
    // for — the client's real free text is "Perm Out" (a space, not an
    // underscore), which the exact-match cast silently drops to null, so no
    // real cell value can exercise this validation path at all (see
    // DGT_ROW_R52_002's docblock). Disinfestation Date is left unmapped so
    // the row is genuinely missing it, per the test's intent.
    $import = dgt_run(
        [array_merge(DGT_ROW_R52_002, ['Status 1' => 'PERM_OUT'])],
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

    // Real row: RAS Batch 1=2, RAS Box 1=52, Catalogue Identifier=R52/002,
    // Disinfestation Date=2023-08-05 (all real — this row is genuinely
    // PERM_OUT with a date in the client's own file). Status 1 is the same
    // forced "PERM_OUT" literal as the test above, for the same reason.
    $import = dgt_run(
        [array_merge(DGT_ROW_R52_002, ['Status 1' => 'PERM_OUT'])],
        [
            'series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier',
            'batch_number' => 'RAS Batch 1', 'current_box_number' => 'RAS Box 1',
            'status_1' => 'Status 1', 'disinfestation_date' => 'Disinfestation Date',
        ],
        $u->id,
    );

    expect(dgt_failures($import))->toBe([]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'R52/002')->first();
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
        // The real example_document_import.xlsx row: a perfectly ordinary
        // row where — like the vast majority of the client's real rows —
        // "Torre" is genuinely blank.
        [DGT_EXAMPLE_ROW],
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
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'R642/001')->exists())->toBeFalse();
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
    // below is independent of Torre specifically). Real row: the shipped
    // example's Torre cell is genuinely blank, same as the test above.
    $import = dgt_run(
        [DGT_EXAMPLE_ROW],
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

test('Bug #22 (b): two DISTINCT blank-identifier rows with byte-identical mapped content stay TWO documents — source position disambiguates, no over-merge', function () {
    $repo = Repository::factory()->create(['code' => 'DGT9']);
    dgt_series('REG');
    $u = dgt_admin($repo->id);
    $this->actingAs($u);

    // Two rows carrying NEITHER an Identifier NOR a Catalogue Identifier (the
    // dominant shape of the client's real batch list — ~92% of rows), whose
    // mapped content is BYTE-IDENTICAL. Series is the client's real legacy
    // "CODE: Title" form. A pure content fingerprint collapses these two
    // genuinely distinct documents into one (PROVEN regression: a real 25-row
    // file dropped to 23). Their distinct SOURCE POSITIONS must keep them apart.
    $row = ['Series' => 'REG: Registers Private Practice'];
    $columnMap = ['series' => 'Series'];

    $import = dgt_run([$row, $row], $columnMap, $u->id);

    expect(dgt_failures($import))->toBe([]);
    // NOT merged to 1 — the whole point of the fix.
    expect(Document::withoutGlobalScope(RepositoryScope::class)->count())->toBe(2);
    $ids = Document::withoutGlobalScope(RepositoryScope::class)->pluck('identifier');
    expect($ids)->toHaveCount(2)
        ->and($ids->unique()->values())->toHaveCount(2)                    // two DISTINCT auto ids
        ->and($ids->every(fn ($i): bool => filled($i) && $i !== 'AUTO-'))->toBeTrue();
});

test('Bug #22 (a): re-importing the IDENTICAL blank-identifier row updates in place — one document, not a duplicate', function () {
    $repo = Repository::factory()->create(['code' => 'DGT9b']);
    dgt_series('REG');
    $u = dgt_admin($repo->id);
    $this->actingAs($u);

    // Blank Identifier + blank Catalogue Identifier (real legacy Series form,
    // real free-text Note). "The same row" for an identity-less row means
    // byte-identical mapped content: first pass INSERTs, a second pass of the
    // IDENTICAL row must MATCH (via the deterministic position-derived auto id)
    // and update in place — the same document, never a duplicate.
    $row = ['Series' => 'REG: Registers Private Practice', 'Note' => 'first pass'];
    $columnMap = ['series' => 'Series', 'notes' => 'Note'];

    dgt_run([$row], $columnMap, $u->id);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->count())->toBe(1);
    $first = Document::withoutGlobalScope(RepositoryScope::class)->first();
    expect($first->identifier)->not->toBeEmpty()
        ->and($first->identifier)->not->toBe('AUTO-')
        ->and($first->notes)->toBe('first pass');

    // Re-import the IDENTICAL single row: same source position + same content →
    // same auto id → resolveRecord() matches the existing document → UPDATE.
    dgt_run([$row], $columnMap, $u->id);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->count())->toBe(1); // still 1, not 2
    $second = Document::withoutGlobalScope(RepositoryScope::class)->first();
    expect($second->id)->toBe($first->id)                 // matched, not a new row
        ->and($second->identifier)->toBe($first->identifier); // stable auto id across runs
});

test('Bug #22 (c): a real multi-row slice of the client\'s own ~5MB batch list re-imported TWICE keeps the same document count (idempotent on real blank-identifier data)', function () {
    if (! is_file(DGT_BATCHLIST_XLSX)) {
        $this->markTestSkipped('client sample not present (nra/inbox is untracked)');
    }

    $repo = Repository::factory()->create(['code' => 'NRA22']);
    dgt_series('REG');
    dgt_series('RWL', wills: true);
    dgt_series('IDX');
    $u = dgt_admin($repo->id);
    $this->actingAs($u);

    $headers = dgt_headers(DGT_BATCHLIST_XLSX);
    $columnMap = dgt_columnMap($headers);
    // Isolate from the separately-confirmed 'torre' NOT NULL bug (its own test)
    // so re-import idempotency is what THIS test measures.
    unset($columnMap['torre']);

    $rows = dgt_realRows(DGT_BATCHLIST_XLSX, 2, 26);
    expect($rows)->toHaveCount(25);

    // The overwhelming majority of these real rows carry NEITHER an Identifier
    // NOR a Catalogue Identifier, so they exercise the blank+blank auto-id path.
    $firstImport = dgt_run($rows, $columnMap, $u->id);
    $countAfterFirst = Document::withoutGlobalScope(RepositoryScope::class)->count();
    expect($countAfterFirst)->toBe($firstImport->successful_rows)
        ->and($countAfterFirst)->toBeGreaterThan(0);

    // Re-import the SAME slice: every row replays at the same source position
    // with the same content → same auto id → each row UPDATES in place. The
    // count must NOT grow — proving idempotency on real production data.
    dgt_run($rows, $columnMap, $u->id);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->count())->toBe($countAfterFirst);
});

test('Bug #22: a blank identifier with a catalogue_identifier present falls back to it, and re-import matches the same document', function () {
    dgt_series('REG');
    $u = dgt_admin();
    $this->actingAs($u);

    // Real Catalogue Identifier (R47/001), no Identifier cell mapped —
    // afterFill() must fall back to it.
    $row = ['Series' => DGT_ROW_R47_001['Series'], 'Catalogue Identifier' => DGT_ROW_R47_001['Catalogue Identifier']];
    $columnMap = ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier'];

    dgt_run([$row], $columnMap, $u->id);
    $first = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'R47/001')->first();
    expect($first->identifier)->toBe('R47/001');

    dgt_run([array_merge($row, ['Note' => 'updated on re-import'])], array_merge($columnMap, ['notes' => 'Note']), $u->id);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'R47/001')->count())->toBe(1);
    $second = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'R47/001')->first();
    expect($second->id)->toBe($first->id)
        ->and($second->notes)->toBe('updated on re-import');
});

// ════════════════════════════════════════════════════════════════════════
//  Excel numeric-cell artefacts
// ════════════════════════════════════════════════════════════════════════

test('F-005: volume_number normalises the Excel float artefact "2.0" to "2" but keeps composite refs verbatim', function () {
    dgt_series('REG');
    dgt_series('IDX');
    $u = dgt_admin();
    $this->actingAs($u);

    // Row A: real "Actual Volume" cell "2.0" on Catalogue Identifier
    // R532/002_IDX (Series IDX) — the genuine Excel float artefact. Row B:
    // real "Actual Volume" cell "38A" on R52/038A (Series REG) — a genuine
    // letter-suffixed, non-float ref the client's own data actually
    // carries, kept verbatim. Both read under the 'Volume' header (the
    // shipped template's real column name for this same field).
    $import = dgt_run(
        [
            ['Series' => 'IDX: Indexes of Registers Private Practice', 'Catalogue Identifier' => 'R532/002_IDX', 'Volume' => '2.0'],
            array_merge(DGT_ROW_R52_038A, ['Volume' => '38A']),
        ],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'volume_number' => 'Volume'],
        $u->id,
    );

    expect(dgt_failures($import))->toBe([]);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'R532/002_IDX')->value('volume_number'))->toBe('2')
        ->and(Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'R52/038A')->value('volume_number'))->toBe('38A');
});

test('BUG-06: current_box_number normalises "1.0" to "1" but keeps an alphanumeric box ref ("52A") verbatim', function () {
    $repo = Repository::factory()->create(['code' => 'DGT10']);
    dgt_series('REG');
    $u = dgt_admin($repo->id);
    $this->actingAs($u);

    // Row A: the real R52/002 row (RAS Batch 1=2, RAS Box 1=52.0 — the
    // genuine Excel float artefact). Row B: same real batch, but no box
    // number in either real file is ever alphanumeric (the client's own
    // "RAS Box 1" cells are always plain integers or "Unknown") — the
    // client's own lettering CONVENTION is real (seen on "Actual Volume"
    // refs like "38A", R52/038A's companion row), so the box number here is
    // the one deliberate edge-case cell, built on that real convention
    // rather than an arbitrary string.
    $import = dgt_run(
        [
            DGT_ROW_R52_002,
            array_merge(DGT_ROW_R52_038A, ['RAS Batch 1' => '2', 'RAS Box 1' => '52A']),
        ],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'batch_number' => 'RAS Batch 1', 'current_box_number' => 'RAS Box 1'],
        $u->id,
    );

    expect(dgt_failures($import))->toBe([]);
    $docA = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'R52/002')->first();
    $docB = Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'R52/038A')->first();
    expect($docA->ras_box_1)->toBe('52')
        ->and(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->find($docA->current_box_id)->box_number)->toBe('52')
        ->and($docB->ras_box_1)->toBe('52A')
        ->and(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->find($docB->current_box_id)->box_number)->toBe('52A');
});

test('a numeric cell in a string column (part_number arriving as a float) imports, not "must be a string"', function () {
    dgt_series('REG');
    $u = dgt_admin();
    $this->actingAs($u);

    // Real "Part Number" cell, read through the ACTUAL vendor xlsx reader
    // (confirmed via direct reflection on ImportExcel::readExcelRowsFromFile
    // against the client's live batch list): PhpSpreadsheet hands back a
    // genuine PHP float (1.0), not a string — the "genuine numeric Excel
    // cell" scenario this test protects against. No Catalogue Identifier is
    // present on this real row, so the saved document is found via
    // Document::first() (this is the only row imported).
    $import = dgt_run(
        [['Series' => 'REG: Registers Private Practice', 'Part Number' => 1.0]],
        ['series' => 'Series', 'part_number' => 'Part Number'],
        $u->id,
    );

    expect(dgt_failures($import))->toBe([]);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->first()->part_number)->toBe('1');
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

    // Real Catalogue Identifiers (R47/001, R52/002); RAS Batch 1 mutated to
    // 30 on both rows to match the fixture batchA. "Current box barcode"
    // again has no real column (see the earlier box-barcode tests' notes).
    $import = dgt_run(
        [
            // Row 1: mismatched batch/box → must fail cleanly.
            array_merge(DGT_ROW_R47_001, ['RAS Batch 1' => '30', 'Current box barcode' => 'BC-ATOMIC']),
            // Row 2: perfectly valid → must still succeed in the SAME chunk.
            array_merge(DGT_ROW_R52_002, ['RAS Batch 1' => '30']),
        ],
        ['series' => 'Series', 'catalogue_identifier' => 'Catalogue Identifier', 'batch_number' => 'RAS Batch 1', 'current_box_barcode' => 'Current box barcode'],
        $u->id,
    );

    expect($import->successful_rows)->toBe(1)
        ->and(dgt_failures($import))->toHaveCount(1);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'R47/001')->exists())->toBeFalse()
        ->and(Document::withoutGlobalScope(RepositoryScope::class)->where('catalogue_identifier', 'R52/002')->exists())->toBeTrue();
});
