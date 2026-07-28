<?php

declare(strict_types=1);

use App\Filament\Imports\AuthorityImporter;
use App\Filament\Imports\BatchImporter;
use App\Filament\Imports\LocationImporter;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;
use HayderHatem\FilamentExcelImport\Actions\Imports\Jobs\ImportExcel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * ERROR-SURFACING — LogsImportRows (shared by all 8 streaming importers).
 *
 * The streaming importer (hayderhatem/filament-excel-import) masks any row
 * error whose message is >200 chars OR contains "SQLSTATE" behind the opaque
 * `filament-excel-import::import.errors.generic_validation` string
 * (vendor/hayderhatem/filament-excel-import/.../ImportExcel.php:479-481).
 * LogsImportRows::humaniseImportError() exists specifically to turn the raw
 * DB exception into something short + clear BEFORE the vendor ever sees it,
 * so the operator gets the real reason instead of the generic one, and the
 * full raw exception is preserved in storage/logs/import-*.log for us.
 *
 * This file drives that un-masking logic two ways:
 *   - SECTION A: direct unit calls to humaniseImportError() with exception
 *     messages shaped exactly like real MySQL 8 / SQLite error text for the
 *     REAL columns of authorities / locations / batches (schema read from
 *     database/migrations/2026_05_25_170002_create_authorities_table.php,
 *     2026_05_26_120000_create_locations_table.php,
 *     2026_05_25_170003_create_batches_table.php +
 *     2026_05_28_140000_make_batch_number_unique_per_repository.php).
 *   - SECTION B: the REAL streaming ImportExcel job against a dirty DB,
 *     driven by the client's actual production files:
 *       nra/inbox/prod-uploads/20260728_075355_0be0517f.csv   (Authority)
 *       nra/inbox/prod-uploads/20260728_075809_36792a2a.csv   (Location)
 *       nra/inbox/prod-uploads/20260728_081540_1d303669.csv   (Batch)
 *     with corrupted variants derived from those real rows to trigger each
 *     genuine DB rejection reachable through the product's own resolveRecord/
 *     afterFill logic (not synthetic clean-table happy paths).
 */
uses(RefreshDatabase::class);

const ESF_AUTHORITY_CSV = __DIR__ . '/../../../../nra/inbox/prod-uploads/20260728_075355_0be0517f.csv';
const ESF_LOCATION_CSV = __DIR__ . '/../../../../nra/inbox/prod-uploads/20260728_075809_36792a2a.csv';
const ESF_BATCH_CSV = __DIR__ . '/../../../../nra/inbox/prod-uploads/20260728_081540_1d303669.csv';

const ESF_AUTHORITY_MAP = [
    'identifier' => 'Identifier',
    'alternative_identifier' => 'Alternative Identifier',
    'entity_type' => 'Type of Entity',
    'practice_dates_active' => 'Private Practice Dates Active',
    'ntg_dates_active' => 'NTG Dates Active',
    'name_suffix' => 'Name Suffix',
    'maiden_surname' => 'Maiden Surname',
    'surname' => 'Creator Surname',
    'given_names' => 'Creator Name',
];

const ESF_LOCATION_MAP = [
    'name' => 'name',
    'type' => 'type',
    'parent_name' => 'parent_name',
    'repository_code' => 'repository_code',
    'code' => 'code',
    'notes' => 'notes',
    'sort_order' => 'sort_order',
    'is_active' => 'is_active',
];

const ESF_BATCH_MAP = [
    'batch_number' => 'batch_number',
    'description' => 'description',
    'type' => 'type',
    'is_active' => 'is_active',
    'repository_code' => 'repository_code',
];

/** @return array<int, array<string, string>> */
function esf_loadCsv(string $path): array
{
    $lines = array_map('str_getcsv', file($path));
    $headers = array_shift($lines);

    return array_values(array_filter(array_map(
        fn (array $row): array => array_combine($headers, $row),
        $lines,
    )));
}

function esf_admin(?int $repoId = null): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repoId]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * Run the REAL hayderhatem ImportExcel job (the streaming path the "Import
 * Excel/CSV" button dispatches) for the given rows.
 *
 * @param class-string $importer
 * @param array<int, array<string, mixed>> $rows keyed by Excel header
 * @param array<string, string> $columnMap importerField => Excel header
 * @param array<string, mixed> $options
 */
function esf_run(string $importer, array $rows, array $columnMap, int $userId, array $options = []): Import
{
    EntityResolver::flushMemo();
    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'esf.xlsx',
        'file_path' => '/tmp/esf.xlsx',
        'importer' => $importer,
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
function esf_failures(Import $import): array
{
    return $import->failedRows()->pluck('validation_error')->all();
}

/**
 * Invoke the REAL vendor ImportExcel::parseErrorMessage() (protected) so we
 * can prove, end-to-end, what the operator ultimately sees for a given
 * exception — including whether our own humanised message gets re-masked.
 */
function esf_vendorParse(Throwable $e): string
{
    $ref = new ReflectionClass(ImportExcel::class);
    $job = $ref->newInstanceWithoutConstructor();
    $method = $ref->getMethod('parseErrorMessage');
    $method->setAccessible(true);

    return $method->invoke($job, $e);
}

// ─────────────────────────────────────────────────────────────────────────
// SECTION A — humaniseImportError() unit tests, real column shapes
// ─────────────────────────────────────────────────────────────────────────

// authorities.identifier: string(32) unique — MySQL 8 duplicate-key text.
test('MySQL duplicate entry on authorities.identifier is humanised, no SQLSTATE, under 200 chars', function () {
    $e = new Exception("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'R1' for key 'authorities.authorities_identifier_unique' (Connection: mysql, SQL: insert into `authorities` (`identifier`, `surname`) values (R1, Abela))");
    $msg = AuthorityImporter::humaniseImportError($e);

    expect($msg)->toContain('already exists')
        ->and($msg)->toContain('R1')
        ->and($msg)->not->toContain('SQLSTATE')
        ->and(mb_strlen($msg))->toBeLessThan(200);
});

// Same unique index, SQLite phrasing (what our test DB actually produces).
test('SQLite UNIQUE constraint failed on authorities.identifier is humanised', function () {
    $e = new Exception('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: authorities.identifier (Connection: sqlite, SQL: insert into "authorities" ("identifier", "surname") values (R1, Abela))');
    $msg = AuthorityImporter::humaniseImportError($e);

    expect($msg)->toContain('already exists')
        ->and($msg)->toContain('authorities.identifier')
        ->and($msg)->not->toContain('SQLSTATE')
        ->and(mb_strlen($msg))->toBeLessThan(200);
});

// authorities.surname has no default and is NOT NULL — MySQL 1048 phrasing.
test('MySQL NOT NULL violation on authorities.surname is humanised into a missing-value message', function () {
    $e = new Exception("SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'surname' cannot be null (Connection: mysql, SQL: insert into `authorities` (`identifier`) values (R999))");
    $msg = AuthorityImporter::humaniseImportError($e);

    expect($msg)->toContain("'surname'")
        ->and($msg)->toContain('required')
        ->and($msg)->not->toContain('SQLSTATE')
        ->and(mb_strlen($msg))->toBeLessThan(200);
});

// authorities.identifier is string(32) — a genuinely too-long value on MySQL
// (unreachable on SQLite, which never enforces VARCHAR length — see the
// dedicated schema/rule-mismatch tests further down for why this matters).
test('MySQL data-too-long on authorities.identifier is humanised', function () {
    $e = new Exception("SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'identifier' at row 1 (Connection: mysql, SQL: insert into `authorities` ...)");
    $msg = AuthorityImporter::humaniseImportError($e);

    expect($msg)->toContain("'identifier'")
        ->and($msg)->toContain('too long')
        ->and($msg)->not->toContain('SQLSTATE');
});

// locations unique index is COMPOSITE (repository_id, code) — MySQL prints
// the composite value in one 'quoted' token, which the regex still captures
// cleanly.
test('MySQL duplicate entry on the composite (repository_id, code) location key is humanised', function () {
    $e = new Exception("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-DUP' for key 'locations.locations_repository_id_code_unique' (Connection: mysql, SQL: insert into `locations` ...)");
    $msg = LocationImporter::humaniseImportError($e);

    expect($msg)->toContain('already exists')
        ->and($msg)->not->toContain('SQLSTATE')
        ->and(mb_strlen($msg))->toBeLessThan(200);
});

// locations.type: string(32) NOT NULL, no DB default — SQLite phrasing.
test('SQLite NOT NULL violation on locations.type is humanised', function () {
    $e = new Exception('SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: locations.type (Connection: sqlite, SQL: insert into "locations" ("name") values (Room X))');
    $msg = LocationImporter::humaniseImportError($e);

    expect($msg)->toContain('locations.type')
        ->and($msg)->toContain('required')
        ->and($msg)->not->toContain('SQLSTATE');
});

// locations.name is string(100) — a too-long value on MySQL.
test('MySQL data-too-long on locations.name is humanised', function () {
    $e = new Exception("SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'name' at row 1 (Connection: mysql, SQL: insert into `locations` ...)");
    $msg = LocationImporter::humaniseImportError($e);

    expect($msg)->toContain("'name'")
        ->and($msg)->toContain('too long')
        ->and($msg)->not->toContain('SQLSTATE');
});

// batches unique index is COMPOSITE (batch_number, repository_id) since
// 2026_05_28_140000_make_batch_number_unique_per_repository.php.
test('MySQL duplicate entry on the composite (batch_number, repository_id) batch key is humanised', function () {
    $e = new Exception("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '50-3' for key 'batches.batches_batch_number_repository_id_unique' (Connection: mysql, SQL: insert into `batches` ...)");
    $msg = BatchImporter::humaniseImportError($e);

    expect($msg)->toContain('already exists')
        ->and($msg)->not->toContain('SQLSTATE')
        ->and(mb_strlen($msg))->toBeLessThan(200);
});

// batches.repository_id is NOT NULL with no default — MySQL 1364 phrasing
// (this is the exact error a Batch row with an unresolved repository hits).
test('MySQL missing-default-value on batches.repository_id is humanised', function () {
    $e = new Exception("SQLSTATE[HY000]: General error: 1364 Field 'repository_id' doesn't have a default value (Connection: mysql, SQL: insert into `batches` (`batch_number`) values (99))");
    $msg = BatchImporter::humaniseImportError($e);

    expect($msg)->toContain("'repository_id'")
        ->and($msg)->toContain('required')
        ->and($msg)->not->toContain('SQLSTATE');
});

// Same rejection, SQLite phrasing.
test('SQLite NOT NULL violation on batches.repository_id is humanised', function () {
    $e = new Exception('SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: batches.repository_id (Connection: sqlite, SQL: insert into "batches" ("batch_number") values (99))');
    $msg = BatchImporter::humaniseImportError($e);

    expect($msg)->toContain('batches.repository_id')
        ->and($msg)->toContain('required')
        ->and($msg)->not->toContain('SQLSTATE');
});

// batches.repository_id FK is ->restrictOnDelete() — a stale/unknown
// repository id would trip the FK, MySQL 1452 phrasing.
test('MySQL foreign key violation on batches.repository_id is humanised', function () {
    $e = new Exception('SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails (`batch_list`.`batches`, CONSTRAINT `batches_repository_id_foreign` FOREIGN KEY (`repository_id`) REFERENCES `repositories` (`id`))');
    $msg = BatchImporter::humaniseImportError($e);

    expect($msg)->toContain('references')
        ->and($msg)->not->toContain('SQLSTATE')
        ->and(mb_strlen($msg))->toBeLessThan(200);
});

// SQLite's FK message carries NO column/table names at all ("FOREIGN KEY
// constraint failed") — humaniseImportError must still recognise it via the
// case-insensitive stripos('foreign key constraint') check.
test('SQLite foreign key violation (no column names in the raw message) is still humanised', function () {
    $e = new Exception('SQLSTATE[23000]: Integrity constraint violation: 19 FOREIGN KEY constraint failed (Connection: sqlite, SQL: insert into "batches" ("repository_id") values (99999))');
    $msg = BatchImporter::humaniseImportError($e);

    expect($msg)->toContain('references')
        ->and($msg)->not->toContain('SQLSTATE');
});

// batches.type is a MySQL ENUM — an invalid value under strict mode raises
// 1366 "Incorrect ... value" (e.g. when a corrupted spreadsheet slips a raw
// string through castStateUsing's fallback).
test('MySQL incorrect-value error is humanised', function () {
    $e = new Exception("SQLSTATE[HY000]: General error: 1366 Incorrect integer value: 'abc' for column 'batch_number' at row 1 (Connection: mysql, SQL: insert into `batches` ...)");
    $msg = BatchImporter::humaniseImportError($e);

    expect($msg)->toContain('abc')
        ->and($msg)->toContain('not a valid')
        ->and($msg)->not->toContain('SQLSTATE');
});

// An SQLSTATE error that matches NONE of the known patterns must still fall
// back to a short, generic (but honest) message — never leak SQLSTATE text.
test('an unrecognised SQLSTATE error falls back to the generic DB-rejection message', function () {
    $e = new Exception('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction (Connection: mysql, SQL: insert into `batches` ...)');
    $msg = BatchImporter::humaniseImportError($e);

    expect($msg)->toBe('The database rejected this row. See the import log for the exact SQL error.')
        ->and($msg)->not->toContain('SQLSTATE');
});

// A short, non-DB exception (e.g. our own RFQ forbidden-batch-number rule
// failure) must pass through completely unchanged.
test('a short non-DB message passes through unchanged', function () {
    $msg = BatchImporter::humaniseImportError(new Exception('Batch number 34 is reserved/forbidden (RFQ rule): cannot be imported.'));

    expect($msg)->toBe('Batch number 34 is reserved/forbidden (RFQ rule): cannot be imported.');
});

// A long, non-DB exception must be clamped to <=180 chars and end with "…".
test('a long non-DB message is clamped to 180 chars with an ellipsis', function () {
    $long = 'Something went wrong: ' . str_repeat('detail ', 40);
    $msg = BatchImporter::humaniseImportError(new Exception($long));

    expect(mb_strlen($msg))->toBeLessThanOrEqual(180)
        ->and($msg)->toEndWith('…');
});

// A "(SQL: ...)" suffix on a non-SQLSTATE exception must be stripped before
// the length is measured (mirrors the vendor's own SQL-stripping step).
test('a trailing (SQL: ...) clause is stripped from a non-DB message before length-checking', function () {
    $msg = BatchImporter::humaniseImportError(new Exception('Some short driver note (SQL: insert into `batches` (`a`,`b`,`c`) values (1,2,3))'));

    expect($msg)->toBe('Some short driver note')
        ->and($msg)->not->toContain('SQL:');
});

// ─────────────────────────────────────────────────────────────────────────
// SECTION A2 — CONFIRMED BUG: long duplicate values re-trigger the vendor
// mask that LogsImportRows exists to defeat.
// ─────────────────────────────────────────────────────────────────────────

/**
 * documents.catalogue_identifier is string(191) UNIQUE (see
 * database/migrations/2026_05_27_170100_tighten_document_lookups.php) and
 * boxes.barcode / accessions.code are string(64) UNIQUE — all real,
 * LogsImportRows-covered columns whose VALUES (not just names) land inside
 * the "Duplicate entry 'X' for key" template. The template text around the
 * value alone is already 119 chars, so any duplicate value over ~80 chars
 * pushes the humanised message past the vendor's own 200-char mask
 * threshold — undoing the entire point of the trait for that row.
 */
test('BUG: a realistically long duplicate-key value produces a humanised message over 200 chars', function () {
    // 120 chars — well inside catalogue_identifier's 191-char column limit.
    $longValue = str_repeat('AB12-CATALOGUE-', 8);
    expect(mb_strlen($longValue))->toBeLessThanOrEqual(191);

    $e = new Exception("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '{$longValue}' for key 'documents.documents_catalogue_identifier_unique' (Connection: mysql, SQL: insert into `documents` ...)");
    $msg = AuthorityImporter::humaniseImportError($e); // trait method is identical on every importer

    // This is the one property humaniseImportError() exists to guarantee —
    // it currently does NOT hold for long duplicate values (CONFIRMED BUG:
    // this assertion fails — mb_strlen($msg) is 239, not < 200).
    expect(mb_strlen($msg))->toBeLessThan(200);
});

/**
 * End-to-end proof: feed the SAME humanised message the trait produces back
 * through the REAL vendor ImportExcel::parseErrorMessage() and show the
 * operator ends up looking at "generic_validation" again — the exact defect
 * LogsImportRows was written to eliminate (see its class docblock).
 */
test('BUG: the vendor re-masks our own humanised long-duplicate message as generic_validation', function () {
    $longValue = str_repeat('AB12-CATALOGUE-', 8);
    $rawException = new Exception("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '{$longValue}' for key 'documents.documents_catalogue_identifier_unique' (Connection: mysql, SQL: insert into `documents` ...)");
    $humanised = AuthorityImporter::humaniseImportError($rawException);

    // What the vendor job actually catches: our RowImportFailedException
    // carrying the humanised text (see LogsImportRows::saveRecord()).
    $thrownToVendor = new RowImportFailedException($humanised);
    $whatOperatorSees = esf_vendorParse($thrownToVendor);

    // CONFIRMED BUG: this currently IS 'generic_validation' — the vendor
    // re-masks our own humanised message because it's over 200 chars.
    expect($whatOperatorSees)->not->toBe('filament-excel-import::import.errors.generic_validation');
});

// ─────────────────────────────────────────────────────────────────────────
// SECTION A3 — CONFIRMED BUG: composite SQLite UNIQUE messages lose every
// column after the first (regex stops at the first whitespace).
// ─────────────────────────────────────────────────────────────────────────

test('BUG: SQLite composite UNIQUE failure on locations only surfaces the FIRST column, dropping "code"', function () {
    // Real SQLite phrasing for the composite (repository_id, code) index,
    // reproduced exactly (verified against a live SQLite unique violation
    // on an identical two-column schema): columns are comma-space joined.
    $e = new Exception('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: locations.repository_id, locations.code (Connection: sqlite, SQL: insert into "locations" ("name", "code", "repository_id") values (Room B, DUP, 1))');
    $msg = LocationImporter::humaniseImportError($e);

    // The genuinely duplicated, operator-actionable column is "code" (two
    // locations in the same repository can't share a code) — the message
    // should mention it. CONFIRMED BUG: it does not (the regex captures
    // only "locations.repository_id," and stops at the first whitespace).
    expect($msg)->toContain('code');
});

test('BUG: SQLite composite UNIQUE failure on batches only surfaces the FIRST column, dropping "repository_id"', function () {
    $e = new Exception('SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: batches.batch_number, batches.repository_id (Connection: sqlite, SQL: insert into "batches" ("batch_number", "repository_id") values (50, 3))');
    $msg = BatchImporter::humaniseImportError($e);

    // CONFIRMED BUG: same root cause — message is "batches.batch_number,"
    // and never mentions repository_id, the actually-relevant second column.
    expect($msg)->toContain('repository_id');
});

// ─────────────────────────────────────────────────────────────────────────
// SECTION B — REAL streaming path (ImportExcel) + REAL client files +
// dirty DB. Corrupted variants derived from the actual production rows.
// ─────────────────────────────────────────────────────────────────────────

test('real Authority CSV imports cleanly through the streaming path with zero masked errors', function () {
    $u = esf_admin();
    $this->actingAs($u);

    $rows = array_slice(esf_loadCsv(ESF_AUTHORITY_CSV), 0, 25);
    $import = esf_run(AuthorityImporter::class, $rows, ESF_AUTHORITY_MAP, $u->id);

    expect(esf_failures($import))->toBe([])
        ->and(Authority::count())->toBe(25);
});

test('real Authority CSV row with a blank Creator Surname fails via ValidationException, never generic_validation', function () {
    $u = esf_admin();
    $this->actingAs($u);

    $rows = array_slice(esf_loadCsv(ESF_AUTHORITY_CSV), 0, 3);
    $rows[1]['Creator Surname'] = ''; // corrupt: required field blanked
    $rows[1]['Identifier'] = 'R-BLANK-SURNAME'; // new record => required kicks in

    $import = esf_run(AuthorityImporter::class, $rows, ESF_AUTHORITY_MAP, $u->id);

    $failures = esf_failures($import);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->not->toContain('generic_validation')
        ->and($failures[0])->not->toContain('SQLSTATE');
});

test('real Location CSV fails cleanly (not generic_validation) when the Repository does not exist yet', function () {
    $u = esf_admin();
    $this->actingAs($u);

    // Deliberately do NOT create the 'NRA' repository the real file references.
    $rows = array_slice(esf_loadCsv(ESF_LOCATION_CSV), 0, 5);
    $import = esf_run(LocationImporter::class, $rows, ESF_LOCATION_MAP, $u->id);

    $failures = esf_failures($import);
    expect($failures)->toHaveCount(5);
    foreach ($failures as $failure) {
        expect($failure)->not->toContain('generic_validation')
            ->and($failure)->toContain("'NRA' not found");
    }
});

test('real Location CSV imports cleanly once the Repository exists', function () {
    Repository::factory()->create(['code' => 'NRA']);
    $u = esf_admin();
    $this->actingAs($u);

    $rows = esf_loadCsv(ESF_LOCATION_CSV);
    $import = esf_run(LocationImporter::class, $rows, ESF_LOCATION_MAP, $u->id);

    expect(esf_failures($import))->toBe([])
        ->and(Location::count())->toBe(count($rows));
});

test('a genuine (repository_id, code) collision on a real Location row surfaces the real reason, not generic_validation', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = esf_admin($repo->id);
    $this->actingAs($u);

    // Dirty state: a LIVE location already owns code 'DUP' in this repo.
    Location::create(['name' => 'Existing Archive', 'code' => 'DUP', 'type' => 'repository', 'is_active' => true, 'repository_id' => $repo->id]);

    // A real row from the file, corrupted to collide on code but resolved
    // by a DIFFERENT name (resolveRecord matches on name, not code — see
    // LocationImporter::resolveRecord() docblock), so it INSERTs instead of
    // updating and hits the real (repository_id, code) unique index.
    $row = esf_loadCsv(ESF_LOCATION_CSV)[0];
    $row['name'] = 'Archive 1 (renamed)';
    $row['code'] = 'DUP';

    $import = esf_run(LocationImporter::class, [$row], ESF_LOCATION_MAP, $u->id);

    $failures = esf_failures($import);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->not->toContain('generic_validation')
        ->and($failures[0])->not->toContain('SQLSTATE')
        ->and(strtolower($failures[0]))->toContain('already exists');
});

test('soft-deleted Locations from a prior real-file import are restored on re-import, zero failures', function () {
    Repository::factory()->create(['code' => 'NRA']);
    $u = esf_admin();
    $this->actingAs($u);

    $rows = array_slice(esf_loadCsv(ESF_LOCATION_CSV), 0, 5);
    esf_run(LocationImporter::class, $rows, ESF_LOCATION_MAP, $u->id);
    expect(Location::count())->toBe(5);

    Location::query()->delete(); // soft-delete every row, as if the operator undid the import
    expect(Location::count())->toBe(0)
        ->and(Location::withTrashed()->count())->toBe(5);

    $import = esf_run(LocationImporter::class, $rows, ESF_LOCATION_MAP, $u->id);

    expect(esf_failures($import))->toBe([])
        ->and(Location::count())->toBe(5)
        ->and(Location::withTrashed()->count())->toBe(5); // restored, not duplicated
});

test('the real Batch CSV\'s own in-file duplicate row (batch_number 43 twice) updates in place, zero failures', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = esf_admin($repo->id);
    $this->actingAs($u);

    $rows = esf_loadCsv(ESF_BATCH_CSV);
    $dupCount = count(array_filter($rows, fn (array $r): bool => $r['batch_number'] === '43'));
    expect($dupCount)->toBe(2); // confirms the real file really does repeat batch 43

    // Batches 34 and 36 are forbidden (RFQ App.1 #1) and DO appear in the
    // real file with type left blank — exclude them here; they're covered
    // by the dedicated forbidden-number test below.
    $rows = array_values(array_filter($rows, fn (array $r): bool => ! in_array($r['batch_number'], ['34', '36'], true)));

    $import = esf_run(BatchImporter::class, $rows, ESF_BATCH_MAP, $u->id);

    expect(esf_failures($import))->toBe([])
        ->and(Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 43)->count())->toBe(1);
});

test('the real Batch CSV\'s forbidden numbers (34, 36) fail with the RFQ message, never generic_validation', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = esf_admin($repo->id);
    $this->actingAs($u);

    $rows = esf_loadCsv(ESF_BATCH_CSV);
    // De-duplicate the real 43/43 repeat so we can assert an exact failure
    // set keyed by batch_number without the duplicate confusing counts.
    $seen = [];
    $rows = array_values(array_filter($rows, function (array $r) use (&$seen): bool {
        if (isset($seen[$r['batch_number']])) {
            return false;
        }
        $seen[$r['batch_number']] = true;

        return true;
    }));

    $import = esf_run(BatchImporter::class, $rows, ESF_BATCH_MAP, $u->id);

    $failures = esf_failures($import);
    expect($failures)->toHaveCount(2); // batch 34 and batch 36
    foreach ($failures as $failure) {
        expect($failure)->toContain('reserved/forbidden')
            ->and($failure)->not->toContain('generic_validation')
            ->and($failure)->not->toContain('SQLSTATE');
    }
});

test('a real Batch row with a blank repository_code and no user default hits a genuine NOT NULL rejection, humanised not masked', function () {
    // No Repository, no default_repository_id on the acting user — mirrors
    // BatchImporter::resolveRecord()'s own documented "cannot determine
    // repository" branch, which returns `new Batch` and lets the INSERT
    // fail cleanly on the NOT NULL repository_id column.
    $u = esf_admin(null);
    $this->actingAs($u);

    $row = esf_loadCsv(ESF_BATCH_CSV)[0]; // batch_number 1
    $row['repository_code'] = '';

    $import = esf_run(BatchImporter::class, [$row], ESF_BATCH_MAP, $u->id);

    $failures = esf_failures($import);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->not->toContain('generic_validation')
        ->and($failures[0])->not->toContain('SQLSTATE')
        ->and(mb_strlen($failures[0]))->toBeLessThan(200);

    // The RAW SQLSTATE text must still be preserved for developer debugging
    // — it just must not reach the operator-facing failed-rows report.
    $today = now()->format('Y-m-d');
    $logPath = storage_path("logs/import-{$today}.log");
    expect(is_file($logPath))->toBeTrue();
    $log = file_get_contents($logPath);
    expect($log)->toContain('ROW FAILED')
        ->and($log)->toContain('BatchImporter');
});

// ─────────────────────────────────────────────────────────────────────────
// SECTION C — CONFIRMED BUG: LocationImporter's validation rules allow
// longer values than the `locations` table actually stores.
// ─────────────────────────────────────────────────────────────────────────

/**
 * database/migrations/2026_05_26_120000_create_locations_table.php:
 *   $table->string('name', 100);
 *   $table->string('code', 32)->nullable();
 * but LocationImporter::getColumns():
 *   name -> rules(['required', 'string', 'max:191'])   (191, not 100)
 *   code -> rules(['nullable', 'string', 'max:64'])    (64, not 32)
 *
 * A name of 101-191 chars or a code of 33-64 chars PASSES Filament's own
 * import validation, then either silently truncates (SQLite — never
 * errors, so this gap is invisible to the whole test suite) or throws the
 * MySQL "Data too long for column" error at save time in PRODUCTION, where
 * it is caught and correctly humanised by LogsImportRows — but the row
 * should never have been accepted as valid in the first place.
 */
test('BUG: LocationImporter validates "code" up to 64 chars but the DB column is only 32', function () {
    $rules = collect(LocationImporter::getColumns())
        ->first(fn ($c) => $c->getName() === 'code')
        ->getDataValidationRules();

    // CONFIRMED BUG: the rule is 'max:64', not 'max:32' — see the test title.
    expect($rules)->toContain('max:32');
});

test('REGRESSION (bug #16): LocationImporter validates "name" against the real 100-char DB column', function () {
    $rules = collect(LocationImporter::getColumns())
        ->first(fn ($c) => $c->getName() === 'name')
        ->getDataValidationRules();

    expect($rules)->toContain('max:100')
        ->and($rules)->not->toContain('max:191');
});

test('REGRESSION (bug #16): a 150-char Location name (over the 100-char DB column) is now rejected at validation', function () {
    Repository::factory()->create(['code' => 'NRA']);
    $u = esf_admin();
    $this->actingAs($u);

    $overlong = trim(str_repeat('Very Long Archive Room Name ', 6)); // 173 chars, over the column's 100
    $row = esf_loadCsv(ESF_LOCATION_CSV)[0];
    $row['name'] = $overlong;

    $import = esf_run(LocationImporter::class, [$row], ESF_LOCATION_MAP, $u->id);

    // The max:100 rule now catches it up front — a clear per-row failure
    // instead of a silent SQLite truncation / hard MySQL "Data too long" error.
    expect(esf_failures($import))->not->toBe([])
        ->and(Location::where('name', $overlong)->exists())->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────
// SECTION D — property-style sweep: every crafted branch stays un-masked.
// ─────────────────────────────────────────────────────────────────────────

test('every realistic-length humanised DB error stays under 200 chars and never contains SQLSTATE', function () {
    $cases = [
        AuthorityImporter::humaniseImportError(new Exception("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'R42' for key 'authorities.authorities_identifier_unique'")),
        AuthorityImporter::humaniseImportError(new Exception("SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'surname' cannot be null")),
        LocationImporter::humaniseImportError(new Exception("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '4-VAULT' for key 'locations.locations_repository_id_code_unique'")),
        LocationImporter::humaniseImportError(new Exception('SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: locations.type')),
        BatchImporter::humaniseImportError(new Exception("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '50-1' for key 'batches.batches_batch_number_repository_id_unique'")),
        BatchImporter::humaniseImportError(new Exception("SQLSTATE[HY000]: General error: 1364 Field 'repository_id' doesn't have a default value")),
        BatchImporter::humaniseImportError(new Exception('SQLSTATE[23000]: Integrity constraint violation: 19 FOREIGN KEY constraint failed')),
    ];

    foreach ($cases as $msg) {
        expect($msg)->not->toContain('SQLSTATE')
            ->and(mb_strlen($msg))->toBeLessThan(200);
    }
});
