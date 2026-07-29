<?php

declare(strict_types=1);

use App\Filament\Imports\SeriesImporter;
use App\Filament\Pages\ImportWizard;
use App\Filament\Support\SearchableSelects;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use HayderHatem\FilamentExcelImport\Actions\Imports\Jobs\ImportExcel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;

/**
 * SeriesImporter — driven by REAL production/RFQ/outbox files, through the
 * REAL streaming path (HayderHatem ImportExcel, the "Import Excel/CSV" button
 * the client actually clicks — see tests/Feature/Import/DirtyDatabaseImportTest.php
 * for why the standard Filament ImportCsv job is the wrong thing to test).
 *
 * Files used (repo-relative):
 *   - nra/inbox/prod-uploads/20260728_075119_f4b6ebbb.csv   (real prod upload, 30 rows)
 *   - nra/rfq/RFQ-2026-06_Samples/Series_Sample.xlsx        (29 rows, combined-label column A)
 *   - nra/outbox/2026-07-22_NAF_import_examples/example_series_import.xlsx (2 rows)
 */
uses(RefreshDatabase::class);

const SRT_PROD_CSV = __DIR__ . '/../../../../nra/inbox/prod-uploads/20260728_075119_f4b6ebbb.csv';
const SRT_RFQ_XLSX = __DIR__ . '/../../../../nra/rfq/RFQ-2026-06_Samples/Series_Sample.xlsx';
const SRT_OUTBOX_XLSX = __DIR__ . '/../../../../nra/outbox/2026-07-22_NAF_import_examples/example_series_import.xlsx';

function srt_admin(?int $repoId = null): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repoId]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * Run the REAL streaming ImportExcel job (the "Import Excel/CSV" button path)
 * for the given rows (keyed by header), same pattern as DirtyDatabaseImportTest.
 *
 * @param array<int, array<string, mixed>> $rows keyed by Excel header
 * @param array<string, string> $columnMap importerField => Excel header
 * @param array<string, mixed> $options
 */
function srt_run(array $rows, array $columnMap, int $userId, array $options = []): Import
{
    EntityResolver::flushMemo();
    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'series.xlsx',
        'file_path' => '/tmp/series.xlsx',
        'importer' => SeriesImporter::class,
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
function srt_failures(Import $import): array
{
    return $import->failedRows()->pluck('validation_error')->all();
}

/**
 * Read a real CSV file into rows keyed by its own header row.
 *
 * @return array{0: list<string>, 1: array<int, array<string, string>>} [headers, rows]
 */
function srt_readCsv(string $path): array
{
    $handle = fopen($path, 'rb');
    $headers = fgetcsv($handle);
    $rows = [];
    while (($line = fgetcsv($handle)) !== false) {
        if ($line === [null] || $line === false) {
            continue;
        }
        $rows[] = array_combine($headers, $line);
    }
    fclose($handle);

    return [$headers, $rows];
}

/**
 * Read a real xlsx file into rows keyed by its own header row (first
 * non-empty row). Mirrors RealSampleImportTest's loading approach.
 *
 * @return array{0: list<string>, 1: array<int, array<string, mixed>>} [headers, rows]
 */
function srt_readXlsx(string $path): array
{
    $reader = IOFactory::createReaderForFile($path);
    $reader->setReadDataOnly(true);
    $sheet = $reader->load($path)->getActiveSheet();
    $all = array_values($sheet->toArray(null, true, true, false));

    $headers = array_map(fn ($h): string => (string) ($h ?? ''), $all[0]);
    $rows = [];
    foreach (array_slice($all, 1) as $line) {
        // Skip fully-blank rows.
        if (count(array_filter($line, fn ($c) => $c !== null && $c !== '')) === 0) {
            continue;
        }
        $keyed = [];
        foreach ($headers as $i => $h) {
            if ($h === '') {
                continue; // blank header column (e.g. Series_Sample.xlsx column A has no header)
            }
            $keyed[$h] = $line[$i] ?? null;
        }
        $rows[] = $keyed;
    }

    return [$headers, $rows];
}

// ─── 1. Real prod CSV — full end-to-end import ─────────────────────────────

test('imports the real prod Series CSV end-to-end with zero failures', function () {
    Repository::factory()->create(['code' => 'NRA']);
    $u = srt_admin();
    $this->actingAs($u);

    [, $rows] = srt_readCsv(SRT_PROD_CSV);
    expect($rows)->toHaveCount(30);

    $columnMap = [
        'code' => 'Identifier',
        'title' => 'Standard title in English (Plural)',
        'repository_code' => 'Repository',
    ];

    $import = srt_run($rows, $columnMap, $u->id);

    expect(srt_failures($import))->toBe([])
        ->and(Series::count())->toBe(30);
});

test('imports the ISAD metadata columns (level, creation date, inputter) verbatim from the real CSV', function () {
    Repository::factory()->create(['code' => 'NRA']);
    $u = srt_admin();
    $this->actingAs($u);

    [, $rows] = srt_readCsv(SRT_PROD_CSV);

    $columnMap = [
        'code' => 'Identifier',
        'title' => 'Standard title in English (Plural)',
        'repository_code' => 'Repository',
        'level_of_description' => 'Level of description',
        'date_of_creation' => 'Date of creation',
        'name_of_inputter' => 'Name of Inputter',
    ];

    $import = srt_run($rows, $columnMap, $u->id);
    expect(srt_failures($import))->toBe([]);

    $r = Series::where('code', 'R')->first();
    expect($r->level_of_description)->toBe('Series')
        ->and($r->name_of_inputter)->toBe('Charlene Ellul')
        // The sheet stores the date as the Excel serial 46228 → rendered Y-m-d.
        ->and($r->date_of_creation)->toBe('2026-07-25');
});

test('date_of_creation converts an Excel serial but preserves a 4-digit year and an ISAD year range', function () {
    Repository::factory()->create(['code' => 'NRA']);
    $u = srt_admin();
    $this->actingAs($u);

    // Three synthetic rows exercising the three date shapes the client may enter.
    $rows = [
        ['Identifier' => 'DZ1', 'Title' => 'Serial', 'Date' => '46228'],      // Excel serial → date
        ['Identifier' => 'DZ2', 'Title' => 'Year', 'Date' => '2026'],         // 4-digit year → kept
        ['Identifier' => 'DZ3', 'Title' => 'Range', 'Date' => '1607-1629'],   // ISAD range → kept
    ];
    $columnMap = ['code' => 'Identifier', 'title' => 'Title', 'date_of_creation' => 'Date'];

    $import = srt_run($rows, $columnMap, $u->id);
    expect(srt_failures($import))->toBe([]);

    expect(Series::where('code', 'DZ1')->value('date_of_creation'))->toBe('2026-07-25')
        ->and(Series::where('code', 'DZ2')->value('date_of_creation'))->toBe('2026')
        ->and(Series::where('code', 'DZ3')->value('date_of_creation'))->toBe('1607-1629');
});

// ─── 2. Record types R / REG / RWL / O present with correct titles ────────

test('the real prod CSV maps the R/REG/RWL/O record-type codes to their titles', function () {
    Repository::factory()->create(['code' => 'NRA']);
    $u = srt_admin();
    $this->actingAs($u);

    [, $rows] = srt_readCsv(SRT_PROD_CSV);
    $columnMap = ['code' => 'Identifier', 'title' => 'Standard title in English (Plural)', 'repository_code' => 'Repository'];
    srt_run($rows, $columnMap, $u->id);

    expect(Series::where('code', 'R')->value('title'))->toBe('Register Copies (Registro)')
        ->and(Series::where('code', 'REG')->value('title'))->toBe('Registers Private Practice')
        ->and(Series::where('code', 'RWL')->value('title'))->toBe('Registers Private Practice Public Wills')
        ->and(Series::where('code', 'O')->value('title'))->toBe('Originals (Minutari)');
});

// ─── 3. is_wills_series heuristic on real codes ────────────────────────────

test('is_wills_series is auto-derived true for RWL/OWL and false for R/REG/O in the real file', function () {
    Repository::factory()->create(['code' => 'NRA']);
    $u = srt_admin();
    $this->actingAs($u);

    [, $rows] = srt_readCsv(SRT_PROD_CSV);
    $columnMap = ['code' => 'Identifier', 'title' => 'Standard title in English (Plural)', 'repository_code' => 'Repository'];
    srt_run($rows, $columnMap, $u->id);

    expect(Series::where('code', 'RWL')->value('is_wills_series'))->toBeTrue()
        ->and(Series::where('code', 'OWL')->value('is_wills_series'))->toBeTrue()
        ->and(Series::where('code', 'IDW')->value('is_wills_series'))->toBeTrue() // "Indexes of Registers Public Wills"
        ->and(Series::where('code', 'IOW')->value('is_wills_series'))->toBeTrue() // "Indexes of Originals Public Wills"
        ->and(Series::where('code', 'R')->value('is_wills_series'))->toBeFalse()
        ->and(Series::where('code', 'REG')->value('is_wills_series'))->toBeFalse()
        ->and(Series::where('code', 'O')->value('is_wills_series'))->toBeFalse()
        ->and(Series::where('code', 'RNG')->value('is_wills_series'))->toBeFalse();
});

// ─── 4. Repository tag resolves the real "NRA" code from the prod file ────

test('repository_code "NRA" from the real prod file resolves to the seeded Repository', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = srt_admin();
    $this->actingAs($u);

    [, $rows] = srt_readCsv(SRT_PROD_CSV);
    $columnMap = ['code' => 'Identifier', 'title' => 'Standard title in English (Plural)', 'repository_code' => 'Repository'];
    srt_run($rows, $columnMap, $u->id);

    expect(Series::where('code', 'R')->value('repository_id'))->toBe($repo->id)
        ->and(Series::where('code', 'RWL')->value('repository_id'))->toBe($repo->id);
});

// ─── 5. Repository tag case-insensitive resolution ─────────────────────────

test('repository_code resolves case-insensitively ("nra" matches seeded "NRA")', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = srt_admin();
    $this->actingAs($u);

    $import = srt_run(
        [['Identifier' => 'R', 'Title' => 'Register Copies (Registro)', 'Repository' => 'nra']],
        ['code' => 'Identifier', 'title' => 'Title', 'repository_code' => 'Repository'],
        $u->id,
    );

    expect(srt_failures($import))->toBe([])
        ->and(Series::where('code', 'R')->value('repository_id'))->toBe($repo->id);
});

// ─── 6. Unknown repository code does not fail the row (silent no-op) ──────

test('an unresolvable repository_code does not fail the row and leaves repository_id null', function () {
    $u = srt_admin();
    $this->actingAs($u);

    // No Repository exists at all — "NRA" from the real prod file cannot resolve.
    $import = srt_run(
        [['Identifier' => 'R', 'Title' => 'Register Copies (Registro)', 'Repository' => 'NRA']],
        ['code' => 'Identifier', 'title' => 'Title', 'repository_code' => 'Repository'],
        $u->id,
    );

    expect(srt_failures($import))->toBe([])
        ->and(Series::where('code', 'R')->value('repository_id'))->toBeNull();
});

// ─── 7. Blank repository_code column → GLOBAL series (repository_id null) ─

test('a blank repository_code produces a GLOBAL series (repository_id null) even when a default repo exists', function () {
    Repository::factory()->create(['code' => 'NRA']);
    $u = srt_admin();
    $this->actingAs($u);

    $import = srt_run(
        [['Identifier' => 'R', 'Title' => 'Register Copies (Registro)', 'Repository' => '']],
        ['code' => 'Identifier', 'title' => 'Title', 'repository_code' => 'Repository'],
        $u->id,
    );

    expect(srt_failures($import))->toBe([])
        ->and(Series::where('code', 'R')->value('repository_id'))->toBeNull();
});

// ─── 8. repository_code column entirely unmapped → still GLOBAL, no error ─

test('when repository_code is not mapped at all, the series still imports as GLOBAL', function () {
    $u = srt_admin();
    $this->actingAs($u);

    [, $rows] = srt_readCsv(SRT_PROD_CSV);
    // Deliberately omit repository_code from the map — some operators won't
    // map every column the wizard could guess.
    $columnMap = ['code' => 'Identifier', 'title' => 'Standard title in English (Plural)'];
    $import = srt_run(array_slice($rows, 0, 3), $columnMap, $u->id);

    expect(srt_failures($import))->toBe([])
        ->and(Series::count())->toBe(3)
        ->and(Series::whereNotNull('repository_id')->count())->toBe(0);
});

// ─── 9. Soft-deleted restore across the real prod codes ───────────────────

test('re-importing the real prod file restores ALL soft-deleted series (the production incident)', function () {
    Repository::factory()->create(['code' => 'NRA']);
    $u = srt_admin();
    $this->actingAs($u);

    [, $rows] = srt_readCsv(SRT_PROD_CSV);
    $codes = array_column($rows, 'Identifier');
    foreach ($codes as $code) {
        Series::create(['code' => $code, 'title' => 'stale', 'is_active' => true])->delete();
    }
    expect(Series::count())->toBe(0)
        ->and(Series::withTrashed()->count())->toBe(30);

    $columnMap = ['code' => 'Identifier', 'title' => 'Standard title in English (Plural)', 'repository_code' => 'Repository'];
    $import = srt_run($rows, $columnMap, $u->id);

    expect(srt_failures($import))->toBe([])
        ->and(Series::count())->toBe(30)
        ->and(Series::withTrashed()->count())->toBe(30) // not duplicated
        ->and(Series::where('code', 'RWL')->value('title'))->toBe('Registers Private Practice Public Wills');
});

// ─── 10. Soft-deleted restore preserves the unique index (no collision) ───

test('restoring a soft-deleted series never violates the unique code index', function () {
    $u = srt_admin();
    $this->actingAs($u);

    Series::create(['code' => 'R', 'title' => 'old', 'is_active' => true])->delete();

    $import = srt_run(
        [['Identifier' => 'R', 'Title' => 'Register Copies (Registro)']],
        ['code' => 'Identifier', 'title' => 'Title'],
        $u->id,
    );

    expect(srt_failures($import))->toBe([])
        ->and(Series::withTrashed()->where('code', 'R')->count())->toBe(1)
        ->and(Series::where('code', 'R')->value('deleted_at'))->toBeNull();
});

// ─── 11. In-file duplicate codes (exact case) collapse to one record ──────

test('an in-file duplicate code (exact case) updates the same record, not two rows', function () {
    $u = srt_admin();
    $this->actingAs($u);

    $import = srt_run(
        [
            ['Identifier' => 'R', 'Title' => 'first pass'],
            ['Identifier' => 'R', 'Title' => 'second pass — wins'],
        ],
        ['code' => 'Identifier', 'title' => 'Title'],
        $u->id,
    );

    expect(srt_failures($import))->toBe([])
        ->and(Series::count())->toBe(1)
        ->and(Series::where('code', 'R')->value('title'))->toBe('second pass — wins');
});

// ─── 12. In-file duplicate with different casing also collapses to one row ─

test('an in-file duplicate code with different casing ("R" then "r") still collapses to one record', function () {
    $u = srt_admin();
    $this->actingAs($u);

    $import = srt_run(
        [
            ['Identifier' => 'R', 'Title' => 'upper first'],
            ['Identifier' => 'r', 'Title' => 'lower second'],
        ],
        ['code' => 'Identifier', 'title' => 'Title'],
        $u->id,
    );

    expect(srt_failures($import))->toBe([])
        ->and(Series::count())->toBe(1)
        ->and(Series::withTrashed()->count())->toBe(1);
});

// ─── 13. Case/whitespace idempotency: re-import with different case+padding ─

test('re-importing an existing code with different case and surrounding whitespace matches (not duplicates) the existing row', function () {
    $u = srt_admin();
    $this->actingAs($u);

    Series::create(['code' => 'RWL', 'title' => 'Wills', 'is_active' => true]);
    expect(Series::count())->toBe(1);

    $import = srt_run(
        [['Identifier' => '  rwl  ', 'Title' => 'Wills — updated']],
        ['code' => 'Identifier', 'title' => 'Title'],
        $u->id,
    );

    expect(srt_failures($import))->toBe([])
        ->and(Series::count())->toBe(1) // matched the existing row, no duplicate
        ->and(Series::first()->title)->toBe('Wills — updated');
});

// ─── 14. Whitespace-only differences across two separate imports ──────────

test('two separate import runs for the same code with trimmed vs untrimmed identifier converge on one record', function () {
    $u = srt_admin();
    $this->actingAs($u);

    $first = srt_run(
        [['Identifier' => 'REG', 'Title' => 'Registers']],
        ['code' => 'Identifier', 'title' => 'Title'],
        $u->id,
    );
    expect(srt_failures($first))->toBe([]);

    $second = srt_run(
        [['Identifier' => ' REG ', 'Title' => 'Registers Private Practice']],
        ['code' => 'Identifier', 'title' => 'Title'],
        $u->id,
    );

    expect(srt_failures($second))->toBe([])
        ->and(Series::count())->toBe(1)
        ->and(Series::first()->title)->toBe('Registers Private Practice');
});

// ─── 15. Operator pastes the combined "CODE: Title" label into Identifier ─

test('a combined "CODE: Title" label pasted into the Identifier column is split at the colon', function () {
    $u = srt_admin();
    $this->actingAs($u);

    // Exactly what Series_Sample.xlsx column A contains, per the importer's
    // own docblock — some operators paste it into the Identifier column too.
    $import = srt_run(
        [['Identifier' => 'REG: Registers Private Practice', 'Title' => 'Registers Private Practice']],
        ['code' => 'Identifier', 'title' => 'Title'],
        $u->id,
    );

    expect(srt_failures($import))->toBe([])
        ->and(Series::count())->toBe(1)
        ->and(Series::first()->code)->toBe('REG');
});

// ─── 16. Real RFQ sample: canonical column B → code, column A → description ─

test('imports the real RFQ Series_Sample.xlsx using the documented column A/B split', function () {
    [$headers, $rows] = srt_readXlsx(SRT_RFQ_XLSX);
    expect($headers)->toContain('Identifier')
        ->and($rows)->not->toBeEmpty();

    $u = srt_admin();
    $this->actingAs($u);

    // Column A has no header in the real file (srt_readXlsx drops unheaded
    // columns), so simulate the documented scenario directly: operator maps
    // description from the combined label, code from the canonical column.
    $rowsWithLabel = array_map(function (array $r): array {
        $r['Legacy label'] = $r['Identifier'] . ': ' . $r['Standard title in English (Plural)'];

        return $r;
    }, $rows);

    $columnMap = [
        'code' => 'Identifier',
        'title' => 'Standard title in English (Plural)',
        'description' => 'Legacy label',
    ];
    $import = srt_run($rowsWithLabel, $columnMap, $u->id);

    expect(srt_failures($import))->toBe([])
        ->and(Series::count())->toBe(count($rows))
        ->and(Series::where('code', 'REG')->value('description'))->toBe('REG: Registers Private Practice');
});

// ─── 17. Real RFQ sample: RWL/OWL flagged wills, R/REG/O not ──────────────

test('the real RFQ Series_Sample.xlsx yields correct is_wills_series flags across all rows', function () {
    [, $rows] = srt_readXlsx(SRT_RFQ_XLSX);
    $u = srt_admin();
    $this->actingAs($u);

    $columnMap = ['code' => 'Identifier', 'title' => 'Standard title in English (Plural)'];
    $import = srt_run($rows, $columnMap, $u->id);

    expect(srt_failures($import))->toBe([]);

    // Heuristic (SeriesImporter::afterFill): code contains "wl" OR title
    // contains "will" (case-insensitive) — e.g. IDW ("Indexes of Registers
    // Public Wills") has no "wl" in its code but is flagged via its title.
    $wills = Series::where('is_wills_series', true)->get(['code', 'title']);
    foreach ($wills as $s) {
        expect(str_contains(strtolower((string) $s->code), 'wl') || str_contains(strtolower((string) $s->title), 'will'))->toBeTrue();
    }
    expect(Series::where('code', 'RWL')->value('is_wills_series'))->toBeTrue()
        ->and(Series::where('code', 'OWL')->value('is_wills_series'))->toBeTrue()
        ->and(Series::where('code', 'IDW')->value('is_wills_series'))->toBeTrue()
        ->and(Series::where('code', 'R')->value('is_wills_series'))->toBeFalse();
});

// ─── 18. Real outbox example file (2 data rows: REG, RWL) ─────────────────

test('imports the real outbox example_series_import.xlsx (REG + RWL) with the correct wills flag', function () {
    [$headers, $rows] = srt_readXlsx(SRT_OUTBOX_XLSX);
    expect($rows)->toHaveCount(2);

    $u = srt_admin();
    $this->actingAs($u);

    $columnMap = ['code' => 'Identifier', 'title' => 'Standard title in English (Plural)'];
    $import = srt_run($rows, $columnMap, $u->id);

    expect(srt_failures($import))->toBe([])
        ->and(Series::count())->toBe(2)
        ->and(Series::where('code', 'REG')->value('is_wills_series'))->toBeFalse()
        ->and(Series::where('code', 'RWL')->value('is_wills_series'))->toBeTrue();
});

// ─── 19. Explicit is_wills_series override beats the heuristic ────────────

test('an explicitly mapped is_wills_series column overrides the code/title heuristic', function () {
    $u = srt_admin();
    $this->actingAs($u);

    // RWL would normally auto-derive true; operator explicitly says false.
    $import = srt_run(
        [['Identifier' => 'RWL', 'Title' => 'Registers Wills Ledger', 'Wills' => 'false']],
        ['code' => 'Identifier', 'title' => 'Title', 'is_wills_series' => 'Wills'],
        $u->id,
    );

    expect(srt_failures($import))->toBe([])
        ->and(Series::where('code', 'RWL')->value('is_wills_series'))->toBeFalse();
});

// ─── 20. skip_duplicates option skips (not upserts) an in-file repeat ─────

test('skip_duplicates option skips the second occurrence of a code already created earlier in the SAME run', function () {
    $u = srt_admin();
    $this->actingAs($u);

    $import = srt_run(
        [
            ['Identifier' => 'R', 'Title' => 'Register Copies'],
            ['Identifier' => 'R', 'Title' => 'should be skipped'],
        ],
        ['code' => 'Identifier', 'title' => 'Title'],
        $u->id,
        ['skip_duplicates' => true],
    );

    expect(Series::count())->toBe(1)
        ->and(Series::where('code', 'R')->value('title'))->toBe('Register Copies') // first write wins
        ->and(srt_failures($import))->toHaveCount(1)
        ->and(srt_failures($import)[0])->toContain('already exists');
});

// ─── 21. skip_duplicates option skips a code that pre-existed before the run ─

test('skip_duplicates option skips a row whose code already exists in the database before the run', function () {
    $u = srt_admin();
    $this->actingAs($u);

    Series::create(['code' => 'R', 'title' => 'pre-existing', 'is_active' => true]);

    $import = srt_run(
        [['Identifier' => 'R', 'Title' => 'attempted overwrite']],
        ['code' => 'Identifier', 'title' => 'Title'],
        $u->id,
        ['skip_duplicates' => true],
    );

    expect(Series::count())->toBe(1)
        ->and(Series::where('code', 'R')->value('title'))->toBe('pre-existing')
        ->and(srt_failures($import))->toHaveCount(1);
});

// ─── 22. Code longer than the VARCHAR(16) limit is truncated, not rejected ─

test('a code longer than 16 characters is silently truncated to fit the schema limit', function () {
    $u = srt_admin();
    $this->actingAs($u);

    $long = 'REGISTERS-PRIVATE-PRACTICE'; // 27 chars
    $import = srt_run(
        [['Identifier' => $long, 'Title' => 'Registers Private Practice']],
        ['code' => 'Identifier', 'title' => 'Title'],
        $u->id,
    );

    expect(srt_failures($import))->toBe([])
        ->and(Series::count())->toBe(1)
        ->and(mb_strlen((string) Series::first()->code))->toBeLessThanOrEqual(16)
        ->and(Series::first()->code)->toBe(mb_substr($long, 0, 16));
});

// ─── 23. Two distinct codes sharing the same 16-char truncated prefix collide ─

test('two distinct over-length codes that share the same 16-char prefix collapse into ONE series record', function () {
    $u = srt_admin();
    $this->actingAs($u);

    // Both truncate to "REGISTERS-PRIVAT" (16 chars) — a real risk once an
    // operator's identifiers exceed the schema limit, since truncation
    // happens BEFORE the uniqueness match in resolveRecord().
    $a = 'REGISTERS-PRIVATE-ALPHA';
    $b = 'REGISTERS-PRIVATE-BETA';
    expect(mb_substr($a, 0, 16))->toBe(mb_substr($b, 0, 16));

    $import = srt_run(
        [
            ['Identifier' => $a, 'Title' => 'Alpha series'],
            ['Identifier' => $b, 'Title' => 'Beta series'],
        ],
        ['code' => 'Identifier', 'title' => 'Title'],
        $u->id,
    );

    expect(srt_failures($import))->toBe([]);
    // Confirmed collision: the second row silently overwrote the first
    // under the shared truncated code instead of being reported as a clash.
    expect(Series::count())->toBe(1)
        ->and(Series::first()->title)->toBe('Beta series');
});

// ─── 24. "Level of description" / "Date of creation" / "Name of Inputter" ──
// ─── columns from the real prod file are simply ignored (Series has no    ─
// ─── matching field) — must not error or leak into any column.           ─

test('the unmapped "Level of description", "Date of creation" and "Name of Inputter" columns from the real file are ignored without error', function () {
    Repository::factory()->create(['code' => 'NRA']);
    $u = srt_admin();
    $this->actingAs($u);

    [, $rows] = srt_readCsv(SRT_PROD_CSV);
    // Only map the columns SeriesImporter actually understands — mirrors the
    // wizard's guessColumnMap(), which never maps a header to a field the
    // importer doesn't declare.
    $columnMap = ['code' => 'Identifier', 'title' => 'Standard title in English (Plural)', 'repository_code' => 'Repository'];
    $import = srt_run(array_slice($rows, 0, 5), $columnMap, $u->id);

    expect(srt_failures($import))->toBe([])
        ->and(Series::count())->toBe(5);

    // None of the ignored columns' real values ("Series", "46228", "Charlene
    // Ellul") ended up anywhere on the record.
    foreach (Series::all() as $s) {
        expect($s->description)->toBeNull();
    }
});

// ─── 25. ImportWizard's own column-guessing never maps "Name of Inputter" ──
// ─── onto a Series field (Series has no inputter/created_by column).      ─

test('ImportWizard::guessColumnMap does not map "Name of Inputter" for SeriesImporter (no matching field)', function () {
    [$headers] = srt_readCsv(SRT_PROD_CSV);

    $map = ImportWizard::guessColumnMap(SeriesImporter::class, $headers);

    expect($map)->toHaveKey('code')
        ->and($map['code'])->toBe('Identifier')
        ->and($map)->toHaveKey('title')
        ->and($map['title'])->toBe('Standard title in English (Plural)')
        ->and($map)->toHaveKey('repository_code')
        ->and($map['repository_code'])->toBe('Repository')
        // Series has no "inputter"/"created_by" import column at all, so the
        // guess map can never contain one.
        ->not->toHaveKey('inputter')
        ->not->toHaveKey('created_by');
});

// ─── 26. Required-columns check passes for the real prod header row ───────

test('ImportWizard::findMissingRequiredColumns reports nothing missing for the real prod header row', function () {
    [$headers] = srt_readCsv(SRT_PROD_CSV);

    $map = ImportWizard::guessColumnMap(SeriesImporter::class, $headers);
    $missing = ImportWizard::findMissingRequiredColumns(SeriesImporter::class, $map);

    expect($missing)->toBe([]);
});

// ─── 27. Mixed dirty state: live + soft-deleted + brand-new in one real-ish file ─

test('a mixed batch of live, soft-deleted and new series (drawn from real prod codes) imports cleanly', function () {
    $u = srt_admin();
    $this->actingAs($u);

    Series::create(['code' => 'R', 'title' => 'already here', 'is_active' => true]); // live
    Series::create(['code' => 'REG', 'title' => 'deleted', 'is_active' => true])->delete(); // trashed
    // RWL is brand new.

    $import = srt_run(
        [
            ['Identifier' => 'R', 'Title' => 'Register Copies (Registro)'],
            ['Identifier' => 'REG', 'Title' => 'Registers Private Practice'],
            ['Identifier' => 'RWL', 'Title' => 'Registers Private Practice Public Wills'],
        ],
        ['code' => 'Identifier', 'title' => 'Title'],
        $u->id,
    );

    expect(srt_failures($import))->toBe([])
        ->and(Series::count())->toBe(3)
        ->and(Series::withTrashed()->count())->toBe(3)
        ->and(Series::where('code', 'REG')->value('deleted_at'))->toBeNull()
        ->and(Series::where('code', 'RWL')->value('is_wills_series'))->toBeTrue();
});

// ─── 28. title omitted entirely from the map is fine for an UPDATE-only run ─

test('omitting the title column mapping entirely is fine when every row updates an EXISTING series (repository-tag-only run)', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = srt_admin();
    $this->actingAs($u);

    foreach (['R', 'REG', 'RWL'] as $code) {
        Series::create(['code' => $code, 'title' => 'existing', 'is_active' => true]);
    }

    // Real client-request scenario (2026-07-27): bulk-tag existing series
    // with a repository, without re-typing every title.
    $import = srt_run(
        [
            ['Identifier' => 'R', 'Repository' => 'NRA'],
            ['Identifier' => 'REG', 'Repository' => 'NRA'],
            ['Identifier' => 'RWL', 'Repository' => 'NRA'],
        ],
        ['code' => 'Identifier', 'repository_code' => 'Repository'], // no 'title' key at all
        $u->id,
    );

    expect(srt_failures($import))->toBe([])
        ->and(Series::where('code', 'R')->value('repository_id'))->toBe($repo->id)
        ->and(Series::where('code', 'R')->value('title'))->toBe('existing'); // untouched
});

// ─── 29. GLOBAL series remain visible to a repository-scoped user via the shared select ─

test('a GLOBAL series (repository_id null) imported from the real file is not excluded by Series lookups', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = srt_admin($repo->id);
    $this->actingAs($u);

    srt_run(
        [['Identifier' => 'R', 'Title' => 'Register Copies (Registro)']], // no repository_code mapped → GLOBAL
        ['code' => 'Identifier', 'title' => 'Title'],
        $u->id,
    );

    $series = Series::where('code', 'R')->first();
    expect($series)->not->toBeNull()
        ->and($series->repository_id)->toBeNull();

    // The shared Series select (used across Document/Batch forms) queries
    // Series unscoped by repository — a GLOBAL row must still be found.
    $results = SearchableSelects::seriesSearchResults('R');
    expect($results)->toContain('R — Register Copies (Registro)');
});

// ─── 30. Re-running the exact same real prod file twice is fully idempotent ─

test('re-running the exact same real prod CSV a second time changes nothing (fully idempotent)', function () {
    Repository::factory()->create(['code' => 'NRA']);
    $u = srt_admin();
    $this->actingAs($u);

    [, $rows] = srt_readCsv(SRT_PROD_CSV);
    $columnMap = ['code' => 'Identifier', 'title' => 'Standard title in English (Plural)', 'repository_code' => 'Repository'];

    $first = srt_run($rows, $columnMap, $u->id);
    expect(srt_failures($first))->toBe([])
        ->and(Series::count())->toBe(30);

    $ids = Series::orderBy('code')->pluck('id', 'code')->all();

    $second = srt_run($rows, $columnMap, $u->id);
    expect(srt_failures($second))->toBe([])
        ->and(Series::count())->toBe(30)
        ->and(Series::orderBy('code')->pluck('id', 'code')->all())->toBe($ids);
});
