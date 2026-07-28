<?php

declare(strict_types=1);

use App\Filament\Imports\AuthorityImporter;
use App\Models\Authority;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use HayderHatem\FilamentExcelImport\Actions\Imports\Jobs\ImportExcel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;

/**
 * AuthorityImporter, driven through the REAL streaming path (ImportExcel —
 * the job the "Import Excel/CSV" button on the Authorities resource
 * dispatches), against real client files and a deliberately dirty database.
 *
 * Mirrors the pattern established in DirtyDatabaseImportTest.php.
 */
uses(RefreshDatabase::class);

const AT_PROD_CSV = __DIR__ . '/../../../../nra/inbox/prod-uploads/20260728_075355_0be0517f.csv';
const AT_RFQ_SAMPLE_XLSX = __DIR__ . '/../../../../nra/rfq/RFQ-2026-06_Samples/Authorities_Sample.xlsx';
const AT_NAF_EXAMPLE_XLSX = __DIR__ . '/../../../../nra/outbox/2026-07-22_NAF_import_examples/example_authority_import.xlsx';

const AT_COLUMN_MAP = [
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

function at_admin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * Run the actual hayderhatem ImportExcel job (the streaming path the client
 * uses) for the given rows, and return the completed Import with its failed rows.
 *
 * @param array<int, array<string, mixed>> $rows keyed by Excel header
 * @param array<string, string> $columnMap importerField => Excel header
 * @param array<string, mixed> $options
 */
function at_run(array $rows, array $columnMap, int $userId, array $options = []): Import
{
    EntityResolver::flushMemo();
    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'authorities.xlsx',
        'file_path' => '/tmp/authorities.xlsx',
        'importer' => AuthorityImporter::class,
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
function at_failures(Import $import): array
{
    return $import->failedRows()->pluck('validation_error')->all();
}

/**
 * Load a CSV file into rows keyed by the header row (BOM-safe).
 *
 * @return array<int, array<string, string>>
 */
function at_loadCsv(string $path): array
{
    $fh = fopen($path, 'r');
    $header = fgetcsv($fh);
    $header[0] = preg_replace('/^\x{FEFF}/u', '', (string) $header[0]);
    $rows = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) === 1 && $row[0] === null) {
            continue;
        }
        $rows[] = array_combine($header, $row);
    }
    fclose($fh);

    return $rows;
}

/**
 * Load an xlsx file into non-blank rows keyed by the header row.
 *
 * @return array<int, array<string, mixed>>
 */
function at_loadXlsx(string $path): array
{
    $reader = IOFactory::createReaderForFile($path);
    $reader->setReadDataOnly(true);
    $sheet = $reader->load($path)->getActiveSheet();
    $raw = $sheet->toArray(null, true, true, false);
    $header = array_shift($raw);

    $rows = [];
    foreach ($raw as $r) {
        if (count(array_filter($r, fn ($c) => $c !== null && $c !== '')) === 0) {
            continue;
        }
        $rows[] = array_combine($header, $r);
    }

    return $rows;
}

// ─── Numeric coercion (the client's 533/678-row incident) ─────────────────

test('an integer Alternative Identifier cell imports as a string, not rejected', function () {
    $u = at_admin();
    $this->actingAs($u);

    $import = at_run(
        [['Identifier' => 'R1', 'Alternative Identifier' => 511, 'Creator Surname' => 'Abela', 'Creator Name' => 'Antonio', 'Type of Entity' => 'Person']],
        AT_COLUMN_MAP,
        $u->id,
    );

    expect(at_failures($import))->toBe([]);
    $a = Authority::where('identifier', 'R1')->first();
    expect($a)->not->toBeNull()
        ->and($a->alternative_identifier)->toBe('511')
        ->and($a->alternative_identifier)->toBeString();
});

test('a float Alternative Identifier cell (511.5) imports as a string, not rejected', function () {
    $u = at_admin();
    $this->actingAs($u);

    $import = at_run(
        [['Identifier' => 'R2', 'Alternative Identifier' => 511.5, 'Creator Surname' => 'Zammit', 'Creator Name' => 'Carmela', 'Type of Entity' => 'Person']],
        AT_COLUMN_MAP,
        $u->id,
    );

    expect(at_failures($import))->toBe([]);
    $a = Authority::where('identifier', 'R2')->first();
    expect($a->alternative_identifier)->toBe('511.5');
});

test('a whole-number float Alternative Identifier (511.0) does not grow a spurious decimal point', function () {
    $u = at_admin();
    $this->actingAs($u);

    $import = at_run(
        [['Identifier' => 'R3', 'Alternative Identifier' => 511.0, 'Creator Surname' => 'Abela', 'Creator Name' => 'Nicola', 'Type of Entity' => 'Person']],
        AT_COLUMN_MAP,
        $u->id,
    );

    expect(at_failures($import))->toBe([]);
    expect(Authority::where('identifier', 'R3')->value('alternative_identifier'))->toBe('511');
});

// ─── The full production file ──────────────────────────────────────────────

test('the real 678-row production CSV imports 678/678 with zero failures', function () {
    $u = at_admin();
    $this->actingAs($u);

    $rows = at_loadCsv(AT_PROD_CSV);
    expect($rows)->toHaveCount(678);

    $import = at_run($rows, AT_COLUMN_MAP, $u->id);

    expect(at_failures($import))->toBe([])
        ->and($import->successful_rows)->toBe(678)
        ->and(Authority::count())->toBe(678);
})->skip(fn () => ! is_file(AT_PROD_CSV), 'prod CSV not present (nra/inbox is untracked)');

test('re-importing the real 678-row CSV a second time stays idempotent: still 678 rows, zero failures, zero duplicates', function () {
    $u = at_admin();
    $this->actingAs($u);

    $rows = at_loadCsv(AT_PROD_CSV);

    at_run($rows, AT_COLUMN_MAP, $u->id);
    expect(Authority::count())->toBe(678);

    $second = at_run($rows, AT_COLUMN_MAP, $u->id);

    expect(at_failures($second))->toBe([])
        ->and(Authority::count())->toBe(678)
        ->and(Authority::withTrashed()->count())->toBe(678);
})->skip(fn () => ! is_file(AT_PROD_CSV), 'prod CSV not present (nra/inbox is untracked)');

test('every failed row in a large real import batch carries a real reason, never the masked generic_validation string', function () {
    $u = at_admin();
    $this->actingAs($u);

    $rows = at_loadXlsx(AT_RFQ_SAMPLE_XLSX);
    expect(count($rows))->toBeGreaterThan(100);

    $import = at_run($rows, AT_COLUMN_MAP, $u->id);

    foreach (at_failures($import) as $reason) {
        expect($reason)->not->toBeNull()
            ->and($reason)->not->toContain('SQLSTATE')
            ->and(mb_strlen($reason))->toBeLessThan(250);
    }
    // Every row was accounted for — no silent drops.
    expect($import->successful_rows + $import->getFailedRowsCount())->toBe(count($rows));
})->skip(fn () => ! is_file(AT_RFQ_SAMPLE_XLSX), 'RFQ sample not present');

// ─── Dedup on identifier ────────────────────────────────────────────────────

test('re-importing the same identifier updates the existing row instead of duplicating it', function () {
    $u = at_admin();
    $this->actingAs($u);

    at_run(
        [['Identifier' => 'R10', 'Alternative Identifier' => '', 'Creator Surname' => 'Original', 'Creator Name' => 'Name', 'Type of Entity' => 'Person']],
        AT_COLUMN_MAP,
        $u->id,
    );
    expect(Authority::count())->toBe(1);

    $import = at_run(
        [['Identifier' => 'R10', 'Alternative Identifier' => '', 'Creator Surname' => 'Updated', 'Creator Name' => 'Name', 'Type of Entity' => 'Person']],
        AT_COLUMN_MAP,
        $u->id,
    );

    expect(at_failures($import))->toBe([])
        ->and(Authority::count())->toBe(1)
        ->and(Authority::where('identifier', 'R10')->value('surname'))->toBe('Updated');
});

test('skip_duplicates option skips an already-existing identifier instead of updating it', function () {
    $u = at_admin();
    $this->actingAs($u);

    at_run(
        [['Identifier' => 'R11', 'Alternative Identifier' => '', 'Creator Surname' => 'Keep Me', 'Creator Name' => 'Name', 'Type of Entity' => 'Person']],
        AT_COLUMN_MAP,
        $u->id,
    );

    $import = at_run(
        [['Identifier' => 'R11', 'Alternative Identifier' => '', 'Creator Surname' => 'Should Not Overwrite', 'Creator Name' => 'Name', 'Type of Entity' => 'Person']],
        AT_COLUMN_MAP,
        $u->id,
        ['skip_duplicates' => true],
    );

    expect(at_failures($import))->toHaveCount(1)
        ->and(Authority::where('identifier', 'R11')->value('surname'))->toBe('Keep Me');
});

test('two rows with the SAME identifier in one batch do not crash — the second updates the first', function () {
    $u = at_admin();
    $this->actingAs($u);

    $import = at_run(
        [
            ['Identifier' => 'R12', 'Alternative Identifier' => '', 'Creator Surname' => 'First', 'Creator Name' => 'Name', 'Type of Entity' => 'Person'],
            ['Identifier' => 'R12', 'Alternative Identifier' => '', 'Creator Surname' => 'Second', 'Creator Name' => 'Name', 'Type of Entity' => 'Person'],
        ],
        AT_COLUMN_MAP,
        $u->id,
    );

    expect(at_failures($import))->toBe([])
        ->and(Authority::where('identifier', 'R12')->count())->toBe(1)
        ->and(Authority::where('identifier', 'R12')->value('surname'))->toBe('Second');
});

// ─── Dirty database: soft-deleted residue ──────────────────────────────────

test('re-importing an identifier whose row was soft-deleted RESTORES it instead of colliding on the unique index', function () {
    $u = at_admin();
    $this->actingAs($u);

    Authority::create(['identifier' => 'R20', 'surname' => 'Old', 'entity_type' => 'PERSON'])->delete();
    expect(Authority::count())->toBe(0)
        ->and(Authority::withTrashed()->count())->toBe(1);

    $import = at_run(
        [['Identifier' => 'R20', 'Alternative Identifier' => '', 'Creator Surname' => 'Restored', 'Creator Name' => 'Antonio', 'Type of Entity' => 'Person']],
        AT_COLUMN_MAP,
        $u->id,
    );

    expect(at_failures($import))->toBe([])
        ->and(Authority::count())->toBe(1)
        ->and(Authority::withTrashed()->count())->toBe(1)
        ->and(Authority::where('identifier', 'R20')->value('surname'))->toBe('Restored');
});

test('a dirty batch mixing live, soft-deleted and brand-new authorities imports cleanly with no duplicates', function () {
    $u = at_admin();
    $this->actingAs($u);

    Authority::create(['identifier' => 'R30', 'surname' => 'Live', 'entity_type' => 'PERSON']); // live
    Authority::create(['identifier' => 'R31', 'surname' => 'Gone', 'entity_type' => 'PERSON'])->delete(); // trashed

    $import = at_run(
        [
            ['Identifier' => 'R30', 'Alternative Identifier' => '', 'Creator Surname' => 'Live Updated', 'Creator Name' => '', 'Type of Entity' => 'Person'],
            ['Identifier' => 'R31', 'Alternative Identifier' => '', 'Creator Surname' => 'Restored', 'Creator Name' => '', 'Type of Entity' => 'Person'],
            ['Identifier' => 'R32', 'Alternative Identifier' => '', 'Creator Surname' => 'Brand New', 'Creator Name' => '', 'Type of Entity' => 'Person'],
        ],
        AT_COLUMN_MAP,
        $u->id,
    );

    expect(at_failures($import))->toBe([])
        ->and(Authority::count())->toBe(3)
        ->and(Authority::withTrashed()->count())->toBe(3)
        ->and(Authority::where('identifier', 'R30')->value('surname'))->toBe('Live Updated')
        ->and(Authority::where('identifier', 'R31')->value('surname'))->toBe('Restored');
});

// ─── Over-long fields ───────────────────────────────────────────────────────

test('an over-long identifier (>32 chars) fails validation cleanly instead of crashing the batch', function () {
    $u = at_admin();
    $this->actingAs($u);

    $longId = str_repeat('X', 33);
    $import = at_run(
        [['Identifier' => $longId, 'Alternative Identifier' => '', 'Creator Surname' => 'Someone', 'Creator Name' => '', 'Type of Entity' => 'Person']],
        AT_COLUMN_MAP,
        $u->id,
    );

    expect(at_failures($import))->toHaveCount(1)
        ->and(Authority::where('identifier', $longId)->exists())->toBeFalse();
});

test('an over-long surname (>255 chars) fails validation cleanly instead of crashing the batch', function () {
    $u = at_admin();
    $this->actingAs($u);

    $longSurname = str_repeat('S', 256);
    $import = at_run(
        [['Identifier' => 'R40', 'Alternative Identifier' => '', 'Creator Surname' => $longSurname, 'Creator Name' => '', 'Type of Entity' => 'Person']],
        AT_COLUMN_MAP,
        $u->id,
    );

    expect(at_failures($import))->toHaveCount(1)
        ->and(Authority::where('identifier', 'R40')->exists())->toBeFalse();
});

test('an over-long Alternative Identifier (>32 chars) fails validation cleanly instead of crashing the batch', function () {
    $u = at_admin();
    $this->actingAs($u);

    $longAlt = str_repeat('9', 33);
    $import = at_run(
        [['Identifier' => 'R41', 'Alternative Identifier' => $longAlt, 'Creator Surname' => 'Someone', 'Creator Name' => '', 'Type of Entity' => 'Person']],
        AT_COLUMN_MAP,
        $u->id,
    );

    expect(at_failures($import))->toHaveCount(1)
        ->and(Authority::where('identifier', 'R41')->exists())->toBeFalse();
});

// ─── Required field enforcement ────────────────────────────────────────────

test('a row with a blank required Creator Surname fails validation and is NOT silently imported', function () {
    $u = at_admin();
    $this->actingAs($u);

    $import = at_run(
        [['Identifier' => 'R50', 'Alternative Identifier' => '', 'Creator Surname' => '', 'Creator Name' => 'Antonio', 'Type of Entity' => 'Person']],
        AT_COLUMN_MAP,
        $u->id,
    );

    expect(at_failures($import))->toHaveCount(1)
        ->and(Authority::where('identifier', 'R50')->exists())->toBeFalse();
});

test('a row with a blank Identifier fails validation and is NOT silently imported', function () {
    $u = at_admin();
    $this->actingAs($u);

    $import = at_run(
        [['Identifier' => '', 'Alternative Identifier' => '', 'Creator Surname' => 'Someone', 'Creator Name' => '', 'Type of Entity' => 'Person']],
        AT_COLUMN_MAP,
        $u->id,
    );

    expect(at_failures($import))->toHaveCount(1)
        ->and(Authority::count())->toBe(0);
});

// ─── entity_type normalisation ─────────────────────────────────────────────

test('entity_type "Person" (real CSV casing) normalises to PERSON', function () {
    $u = at_admin();
    $this->actingAs($u);

    at_run(
        [['Identifier' => 'R60', 'Alternative Identifier' => '', 'Creator Surname' => 'Abela', 'Creator Name' => '', 'Type of Entity' => 'Person']],
        AT_COLUMN_MAP,
        $u->id,
    );

    expect(Authority::where('identifier', 'R60')->value('entity_type'))->toBe('PERSON');
});

test('entity_type "Notary" (real NAF example file value) normalises to INSTITUTION, the documented unknown-value fallback', function () {
    $u = at_admin();
    $this->actingAs($u);

    at_run(
        [['Identifier' => 'R61', 'Alternative Identifier' => '', 'Creator Surname' => 'Farrugia', 'Creator Name' => 'Antonio', 'Type of Entity' => 'Notary']],
        AT_COLUMN_MAP,
        $u->id,
    );

    expect(Authority::where('identifier', 'R61')->value('entity_type'))->toBe('INSTITUTION');
});

// ─── practice_dates_active / NTG / maiden surname / name suffix ───────────

test('a "1607-1629" Private Practice Dates Active cell splits into practice_dates_start/end integers', function () {
    $u = at_admin();
    $this->actingAs($u);

    at_run(
        [['Identifier' => 'R70', 'Alternative Identifier' => '', 'Creator Surname' => 'Abela', 'Creator Name' => 'Antonio', 'Type of Entity' => 'Person', 'Private Practice Dates Active' => '1607-1629']],
        AT_COLUMN_MAP,
        $u->id,
    );

    $a = Authority::where('identifier', 'R70')->first();
    expect($a->practice_dates_start)->toBe(1607)
        ->and($a->practice_dates_end)->toBe(1629);
});

test('NTG Dates Active (real NAF example row 2) is appended into notes', function () {
    $u = at_admin();
    $this->actingAs($u);

    at_run(
        [['Identifier' => 'R71', 'Alternative Identifier' => '', 'Creator Surname' => 'Grech', 'Creator Name' => 'Carmela', 'Type of Entity' => 'Notary', 'NTG Dates Active' => '1885-1890']],
        AT_COLUMN_MAP,
        $u->id,
    );

    expect(Authority::where('identifier', 'R71')->value('notes'))->toContain('NTG dates: 1885-1890');
});

test('Maiden Surname (real NAF example row 2) is appended into notes', function () {
    $u = at_admin();
    $this->actingAs($u);

    at_run(
        [['Identifier' => 'R72', 'Alternative Identifier' => '', 'Creator Surname' => 'Grech', 'Creator Name' => 'Carmela', 'Type of Entity' => 'Notary', 'Maiden Surname' => 'Zammit']],
        AT_COLUMN_MAP,
        $u->id,
    );

    expect(Authority::where('identifier', 'R72')->value('notes'))->toContain('Maiden surname: Zammit');
});

test('Name Suffix is appended onto given_names', function () {
    $u = at_admin();
    $this->actingAs($u);

    at_run(
        [['Identifier' => 'R73', 'Alternative Identifier' => '', 'Creator Surname' => 'Abela', 'Creator Name' => 'Antonio', 'Type of Entity' => 'Person', 'Name Suffix' => 'Jr.']],
        AT_COLUMN_MAP,
        $u->id,
    );

    expect(Authority::where('identifier', 'R73')->value('given_names'))->toBe('Antonio Jr.');
});

// ─── Whitespace ─────────────────────────────────────────────────────────────

test('whitespace-padded identifier and surname are trimmed before matching/saving', function () {
    $u = at_admin();
    $this->actingAs($u);

    at_run(
        [['Identifier' => '  R80  ', 'Alternative Identifier' => '', 'Creator Surname' => '  Abela  ', 'Creator Name' => '', 'Type of Entity' => 'Person']],
        AT_COLUMN_MAP,
        $u->id,
    );

    $a = Authority::where('identifier', 'R80')->first();
    expect($a)->not->toBeNull()
        ->and($a->surname)->toBe('Abela');
});

// ─── Real NAF example file, end to end ─────────────────────────────────────

test('the real NAF example_authority_import.xlsx (2 rows) imports cleanly end-to-end', function () {
    $u = at_admin();
    $this->actingAs($u);

    $rows = at_loadXlsx(AT_NAF_EXAMPLE_XLSX);
    expect($rows)->toHaveCount(2);

    $import = at_run($rows, AT_COLUMN_MAP, $u->id);

    expect(at_failures($import))->toBe([])
        ->and(Authority::count())->toBe(2)
        ->and(Authority::where('identifier', 'R646')->value('surname'))->toBe('Farrugia')
        ->and(Authority::where('identifier', 'R647')->value('notes'))->toContain('Maiden surname: Zammit');
})->skip(fn () => ! is_file(AT_NAF_EXAMPLE_XLSX), 'NAF example file not present');
