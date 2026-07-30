<?php

declare(strict_types=1);

use App\Filament\Imports\LocationImporter;
use App\Filament\Imports\SeriesImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use Filament\Actions\Imports\Jobs\ImportCsv;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * WIZARD area — dispatch/sheet/materialise/preflight coverage for
 * {@see ImportWizard}, driven against the REAL client fixtures:
 *
 *   - nra/outbox/2026-07-22_NAF_import_examples/example_location_import.xlsx
 *     (two-sheet template: "Data" + "READ ME")
 *   - nra/inbox/prod-uploads/20260728_075119_f4b6ebbb.csv
 *     (30-row real Series batch-list export from the client)
 *   - nra/outbox/2026-07-22_NAF_import_examples/example_series_import.xlsx
 *     (two-sheet template with a stray blank first header column)
 *
 * Some tests call the Page's protected helpers (materialiseCsv,
 * readCsvForImport, dispatchImportBatch) directly via Reflection — this is
 * necessary because {@see ImportWizard::startImport()} itself is the subject
 * of a confirmed product bug (see the "CRITICAL BUG" block below) that
 * prevents it from ever reaching those helpers with a real upload.
 */
uses(RefreshDatabase::class);

const WIZ_LOCATION_XLSX = __DIR__ . '/../../../../nra/outbox/2026-07-22_NAF_import_examples/example_location_import.xlsx';
const WIZ_SERIES_XLSX = __DIR__ . '/../../../../nra/outbox/2026-07-22_NAF_import_examples/example_series_import.xlsx';
const WIZ_PROD_CSV = __DIR__ . '/../../../../nra/inbox/prod-uploads/20260728_075119_f4b6ebbb.csv';

function wiz_admin(?int $repoId = null): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repoId]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * Build a genuine Livewire {@see TemporaryUploadedFile} from a REAL fixture
 * file, WITHOUT ever calling the schema's validated getState() — which
 * (per the critical bug documented below) would immediately persist/delete
 * it. We read it back via getRawState() right after ->set(), before any
 * further Livewire round trip.
 */
function wiz_temp_file(string $realPath, ?string $displayName = null): TemporaryUploadedFile
{
    $displayName ??= basename($realPath);
    $bytes = file_get_contents($realPath);
    if ($bytes === false) {
        throw new RuntimeException("Fixture not readable: {$realPath}");
    }
    $fake = UploadedFile::fake()->createWithContent($displayName, $bytes);
    $component = Livewire::test(ImportWizard::class)->set('data.file', $fake);
    $raw = $component->instance()->form->getRawState()['file'];

    /** @var TemporaryUploadedFile $file */
    $file = is_array($raw) ? reset($raw) : $raw;
    expect($file)->toBeInstanceOf(TemporaryUploadedFile::class);

    return $file;
}

/**
 * A bare, un-mounted ImportWizard instance for reflecting into its
 * protected helpers (materialiseCsv / readCsvForImport / dispatchImportBatch).
 * These methods touch none of the Livewire-mounted properties, so a plain
 * `new` instance is sufficient and avoids re-running the whole schema.
 */
function wiz_page(): ImportWizard
{
    return new ImportWizard;
}

/**
 * @return mixed
 */
function wiz_call(object $target, string $method, array $args = [])
{
    $ref = new ReflectionMethod($target, $method);

    return $ref->invokeArgs($target, $args);
}

/* ════════════════════════════════════════════════════════════════════
 * materialiseCsv — reads the SELECTED sheet
 * ════════════════════════════════════════════════════════════════════ */

test('materialiseCsv on a multi-sheet xlsx reads sheet 0 ("Data"), not the README', function () {
    $this->actingAs(wiz_admin());
    $page = wiz_page();
    $file = wiz_temp_file(WIZ_LOCATION_XLSX);

    $csvPath = wiz_call($page, 'materialiseCsv', [$file, 0]);
    $content = file_get_contents($csvPath);

    expect($content)->toContain('Archive Room 1')
        ->and($content)->toContain('Conservation Lab')
        ->and($content)->not->toContain('Leave repository_code blank');
});

test('materialiseCsv on a multi-sheet xlsx honours an explicit sheet index (1 = "READ ME")', function () {
    $this->actingAs(wiz_admin());
    $page = wiz_page();
    $file = wiz_temp_file(WIZ_LOCATION_XLSX);

    $csvPath = wiz_call($page, 'materialiseCsv', [$file, 1]);
    $content = file_get_contents($csvPath);

    expect($content)->toContain('Leave repository_code blank')
        ->and($content)->not->toContain('Archive Room 1');
});

test('materialiseCsv on the Series template reads the "Data" sheet with the real headers', function () {
    $this->actingAs(wiz_admin());
    $page = wiz_page();
    $file = wiz_temp_file(WIZ_SERIES_XLSX);

    $csvPath = wiz_call($page, 'materialiseCsv', [$file, 0]);
    [$headers, $rows] = wiz_call($page, 'readCsvForImport', [$csvPath]);

    expect($headers)->toContain('Identifier')
        ->and($headers)->toContain('Standard title in English (Plural)')
        ->and($rows)->toHaveCount(2)
        ->and($rows[0]['Identifier'])->toBe('REG')
        ->and($rows[1]['Identifier'])->toBe('RWL');
});

test('materialiseCsv copies a real .csv upload byte-for-byte (no xlsx transcoding)', function () {
    $this->actingAs(wiz_admin());
    $page = wiz_page();
    $file = wiz_temp_file(WIZ_PROD_CSV, '20260728_075119_f4b6ebbb.csv');

    $csvPath = wiz_call($page, 'materialiseCsv', [$file, 0]);

    expect(file_get_contents($csvPath))->toBe(file_get_contents(WIZ_PROD_CSV));
});

/* ════════════════════════════════════════════════════════════════════
 * readCsvForImport — row count matches the file
 * ════════════════════════════════════════════════════════════════════ */

test('readCsvForImport row count matches the real prod CSV (30 data rows)', function () {
    $this->actingAs(wiz_admin());
    $page = wiz_page();
    $file = wiz_temp_file(WIZ_PROD_CSV, 'prod.csv');
    $csvPath = wiz_call($page, 'materialiseCsv', [$file, 0]);

    [$headers, $rows] = wiz_call($page, 'readCsvForImport', [$csvPath]);

    expect($headers)->toHaveCount(6)
        ->and($headers)->toBe([
            'Identifier',
            'Standard title in English (Plural)',
            'Level of description',
            'Date of creation',
            'Name of Inputter',
            'Repository',
        ])
        ->and($rows)->toHaveCount(30)
        ->and($rows[0]['Identifier'])->toBe('R')
        ->and($rows[29]['Identifier'])->toBe('MDV');
});

test('readCsvForImport row count matches the Location template Data sheet (2 rows)', function () {
    $this->actingAs(wiz_admin());
    $page = wiz_page();
    $file = wiz_temp_file(WIZ_LOCATION_XLSX);
    $csvPath = wiz_call($page, 'materialiseCsv', [$file, 0]);

    [$headers, $rows] = wiz_call($page, 'readCsvForImport', [$csvPath]);

    expect($headers)->toBe(['name', 'type', 'parent_name', 'repository_code', 'code', 'notes', 'sort_order', 'is_active'])
        ->and($rows)->toHaveCount(2);
});

test('readCsvForImport returns zero rows for a header-only CSV (the precondition startImport rejects on)', function () {
    $headerOnly = "Identifier,Title\n";
    $path = sys_get_temp_dir() . '/wiz_header_only_' . uniqid() . '.csv';
    file_put_contents($path, $headerOnly);

    $page = wiz_page();
    [$headers, $rows] = wiz_call($page, 'readCsvForImport', [$path]);

    expect($headers)->toBe(['Identifier', 'Title'])
        ->and($rows)->toBe([]);

    // nosemgrep: php.lang.security.unlink-use.unlink-use -- $path is a test-controlled sys_get_temp_dir()+uniqid() file, never user input.
    @unlink($path);
});

/* ════════════════════════════════════════════════════════════════════
 * dispatchImportBatch — job_batch + ImportCsv jobs + total_rows
 * ════════════════════════════════════════════════════════════════════ */

test('dispatchImportBatch sets total_rows to the exact row count of the real prod CSV', function () {
    $u = wiz_admin();
    $this->actingAs($u);
    $page = wiz_page();
    $file = wiz_temp_file(WIZ_PROD_CSV, 'prod.csv');
    $csvPath = wiz_call($page, 'materialiseCsv', [$file, 0]);
    [$headers, $rows] = wiz_call($page, 'readCsvForImport', [$csvPath]);

    $columnMap = ImportWizard::guessColumnMap(SeriesImporter::class, $headers);

    $importId = wiz_call($page, 'dispatchImportBatch', [
        SeriesImporter::class,
        'prod.csv',
        $csvPath,
        $rows,
        $columnMap,
        ['skip_duplicates' => true],
    ]);

    $import = Import::query()->findOrFail($importId);
    expect($import->total_rows)->toBe(30)
        ->and($import->importer)->toBe(SeriesImporter::class);
});

test('dispatchImportBatch creates a real Laravel job_batches row (not just an Import row)', function () {
    $u = wiz_admin();
    $this->actingAs($u);
    $page = wiz_page();
    $file = wiz_temp_file(WIZ_PROD_CSV, 'prod.csv');
    $csvPath = wiz_call($page, 'materialiseCsv', [$file, 0]);
    [$headers, $rows] = wiz_call($page, 'readCsvForImport', [$csvPath]);
    $columnMap = ImportWizard::guessColumnMap(SeriesImporter::class, $headers);

    expect(DB::table('job_batches')->count())->toBe(0);

    wiz_call($page, 'dispatchImportBatch', [
        SeriesImporter::class,
        'prod.csv',
        $csvPath,
        $rows,
        $columnMap,
        ['skip_duplicates' => true],
    ]);

    // QUEUE_CONNECTION=sync in testing — the batch runs to completion inline,
    // but Bus::batch() still persists a job_batches row for the run.
    expect(DB::table('job_batches')->count())->toBe(1);
    $batch = DB::table('job_batches')->first();
    expect((int) $batch->total_jobs)->toBe(1); // 30 rows / chunk 100 = 1 ImportCsv job
});

test('dispatchImportBatch actually imports all 30 real Series rows end-to-end (sync queue)', function () {
    $u = wiz_admin();
    $this->actingAs($u);
    $page = wiz_page();
    $file = wiz_temp_file(WIZ_PROD_CSV, 'prod.csv');
    $csvPath = wiz_call($page, 'materialiseCsv', [$file, 0]);
    [$headers, $rows] = wiz_call($page, 'readCsvForImport', [$csvPath]);
    $columnMap = ImportWizard::guessColumnMap(SeriesImporter::class, $headers);

    wiz_call($page, 'dispatchImportBatch', [
        SeriesImporter::class,
        'prod.csv',
        $csvPath,
        $rows,
        $columnMap,
        ['skip_duplicates' => true],
    ]);

    expect(Series::count())->toBe(30)
        ->and(Series::where('code', 'R')->value('title'))->toBe('Register Copies (Registro)')
        ->and(Series::where('code', 'MDV')->value('title'))->toBe('Medieval Collection');
});

test('dispatchImportBatch against a DIRTY DB (some codes pre-existing soft-deleted) restores them via the stock ImportCsv job', function () {
    $u = wiz_admin();
    $this->actingAs($u);

    // Dirty state: three of the real prod codes already exist, soft-deleted —
    // mirrors the production incident (all Series soft-deleted, re-imported).
    foreach (['R', 'REG', 'MDV'] as $code) {
        Series::create(['code' => $code, 'title' => 'stale', 'is_active' => true])->delete();
    }
    expect(Series::count())->toBe(0)
        ->and(Series::withTrashed()->count())->toBe(3);

    $page = wiz_page();
    $file = wiz_temp_file(WIZ_PROD_CSV, 'prod.csv');
    $csvPath = wiz_call($page, 'materialiseCsv', [$file, 0]);
    [$headers, $rows] = wiz_call($page, 'readCsvForImport', [$csvPath]);
    $columnMap = ImportWizard::guessColumnMap(SeriesImporter::class, $headers);

    wiz_call($page, 'dispatchImportBatch', [
        SeriesImporter::class,
        'prod.csv',
        $csvPath,
        $rows,
        $columnMap,
        ['skip_duplicates' => true],
    ]);

    expect(Series::count())->toBe(30)                    // all 30 live, none duplicated
        ->and(Series::withTrashed()->count())->toBe(30)
        ->and(Series::where('code', 'R')->value('title'))->toBe('Register Copies (Registro)');
});

test('dispatchImportBatch respects the Location template end-to-end (repository resolution for the explicit row)', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = wiz_admin($repo->id);
    $this->actingAs($u);

    $page = wiz_page();
    $file = wiz_temp_file(WIZ_LOCATION_XLSX);
    $csvPath = wiz_call($page, 'materialiseCsv', [$file, 0]);
    [$headers, $rows] = wiz_call($page, 'readCsvForImport', [$csvPath]);
    $columnMap = ImportWizard::guessColumnMap(LocationImporter::class, $headers);

    $missing = ImportWizard::findMissingRequiredColumns(LocationImporter::class, $columnMap);
    expect($missing)->toBeEmpty();

    wiz_call($page, 'dispatchImportBatch', [
        LocationImporter::class,
        basename(WIZ_LOCATION_XLSX),
        $csvPath,
        $rows,
        $columnMap,
        ['skip_duplicates' => true],
    ]);

    expect(Location::count())->toBe(2)
        ->and(Location::where('name', 'Archive Room 1')->where('repository_id', $repo->id)->exists())->toBeTrue();
    // "Conservation Lab" (blank repository_code, meant to stay GLOBAL per the
    // template's own README) is covered — and shown to be mis-scoped — by the
    // dedicated CONFIRMED BUG test below.
});

/* ════════════════════════════════════════════════════════════════════
 * CONFIRMED BUG — a "leave repository blank for a shared/global Location"
 * row, exactly as the real client template documents and demonstrates,
 * silently gets stamped with the IMPORTING ADMIN's own default repository
 * instead of staying global.
 *
 * Root cause: App\Models\Concerns\BelongsToRepository::bootBelongsToRepository()
 * (app/Models/Concerns/BelongsToRepository.php:48-50) registers a `creating`
 * hook that force-defaults `repository_id` from the CURRENT USER's
 * `default_repository_id` whenever the attribute is empty at save time —
 * regardless of WHY it is empty. LocationImporter::afterFill()
 * (app/Filament/Imports/LocationImporter.php:147-158) correctly leaves
 * `$record->repository_id` untouched when the operator's repository_code
 * cell is blank (by design — "Blank → GLOBAL location"), but that
 * intentional null is exactly the state the model-level hook treats as
 * "not provided, default it" one step later during save(). The two
 * behaviours are documented independently and contradict each other for
 * every admin/super_admin operator who has a default_repository_id set —
 * which is the wizard's ENTIRE user base (ImportWizard::canAccess()
 * restricts the wizard to admin/super_admin only).
 *
 * The official example_location_import.xlsx template — the exact file
 * NAF was given — ships a "Conservation Lab" row with repository_code
 * deliberately blank and a README that says "Leave repository_code blank
 * for a location shared by every repository." Following those
 * instructions produces a location silently scoped to the ADMIN's own
 * repository instead of the documented global/shared location.
 * ════════════════════════════════════════════════════════════════════ */

test('CONFIRMED BUG: a blank repository_code on the real Location template does NOT stay global — it inherits the admin\'s default repository', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = wiz_admin($repo->id); // realistic setup: admin has a default repository
    $this->actingAs($u);

    $page = wiz_page();
    $file = wiz_temp_file(WIZ_LOCATION_XLSX);
    $csvPath = wiz_call($page, 'materialiseCsv', [$file, 0]);
    [$headers, $rows] = wiz_call($page, 'readCsvForImport', [$csvPath]);
    $columnMap = ImportWizard::guessColumnMap(LocationImporter::class, $headers);

    // Confirm the CSV row really does carry a blank repository_code, matching
    // the README's documented "leave blank for shared" instruction.
    $conservationRow = collect($rows)->firstWhere('name', 'Conservation Lab');
    expect(trim((string) $conservationRow['repository_code']))->toBe('');

    wiz_call($page, 'dispatchImportBatch', [
        LocationImporter::class,
        basename(WIZ_LOCATION_XLSX),
        $csvPath,
        $rows,
        $columnMap,
        ['skip_duplicates' => true],
    ]);

    $lab = Location::where('name', 'Conservation Lab')->first();
    expect($lab)->not->toBeNull();

    // BUG: this is what the template promises (global/shared, repository_id
    // null) but does NOT get. Documented here as the failing expectation so
    // the test is RED until the product bug is fixed.
    expect($lab->repository_id)->toBeNull();
});

/* ════════════════════════════════════════════════════════════════════
 * guessColumnMap / findMissingRequiredColumns — real headers
 * ════════════════════════════════════════════════════════════════════ */

test('guessColumnMap maps the real prod CSV headers onto SeriesImporter fields', function () {
    $this->actingAs(wiz_admin());
    $page = wiz_page();
    $file = wiz_temp_file(WIZ_PROD_CSV, 'prod.csv');
    $csvPath = wiz_call($page, 'materialiseCsv', [$file, 0]);
    [$headers] = wiz_call($page, 'readCsvForImport', [$csvPath]);

    $map = ImportWizard::guessColumnMap(SeriesImporter::class, $headers);

    expect($map['code'])->toBe('Identifier')
        ->and($map['title'])->toBe('Standard title in English (Plural)')
        ->and($map['repository_code'])->toBe('Repository');
});

test('findMissingRequiredColumns is empty for the real prod CSV headers against SeriesImporter', function () {
    $this->actingAs(wiz_admin());
    $page = wiz_page();
    $file = wiz_temp_file(WIZ_PROD_CSV, 'prod.csv');
    $csvPath = wiz_call($page, 'materialiseCsv', [$file, 0]);
    [$headers] = wiz_call($page, 'readCsvForImport', [$csvPath]);
    $map = ImportWizard::guessColumnMap(SeriesImporter::class, $headers);

    expect(ImportWizard::findMissingRequiredColumns(SeriesImporter::class, $map))->toBeEmpty();
});

test('findMissingRequiredColumns flags code as missing when Identifier is stripped from the real headers', function () {
    $this->actingAs(wiz_admin());
    $page = wiz_page();
    $file = wiz_temp_file(WIZ_PROD_CSV, 'prod.csv');
    $csvPath = wiz_call($page, 'materialiseCsv', [$file, 0]);
    [$headers] = wiz_call($page, 'readCsvForImport', [$csvPath]);
    $headersWithoutIdentifier = array_values(array_diff($headers, ['Identifier']));

    $map = ImportWizard::guessColumnMap(SeriesImporter::class, $headersWithoutIdentifier);
    $missing = ImportWizard::findMissingRequiredColumns(SeriesImporter::class, $map);

    expect($missing)->toContain('Identifier (code)');
});

test('guessColumnMap maps the Location template headers 1:1 (exact field-name headers)', function () {
    $this->actingAs(wiz_admin());
    $page = wiz_page();
    $file = wiz_temp_file(WIZ_LOCATION_XLSX);
    $csvPath = wiz_call($page, 'materialiseCsv', [$file, 0]);
    [$headers] = wiz_call($page, 'readCsvForImport', [$csvPath]);

    $map = ImportWizard::guessColumnMap(LocationImporter::class, $headers);

    expect($map['name'])->toBe('name')
        ->and($map['type'])->toBe('type')
        ->and($map['parent_name'])->toBe('parent_name')
        ->and($map['repository_code'])->toBe('repository_code')
        ->and($map['code'])->toBe('code')
        ->and($map['is_active'])->toBe('is_active');
});

test('guessColumnMap on the Series template with its stray blank first header still maps code/title correctly', function () {
    $this->actingAs(wiz_admin());
    $page = wiz_page();
    $file = wiz_temp_file(WIZ_SERIES_XLSX);
    $csvPath = wiz_call($page, 'materialiseCsv', [$file, 0]);
    [$headers] = wiz_call($page, 'readCsvForImport', [$csvPath]);

    // Confirms the fixture really does carry the stray blank column.
    expect($headers[0])->toBe('');

    $map = ImportWizard::guessColumnMap(SeriesImporter::class, $headers);

    expect($map['code'])->toBe('Identifier')
        ->and($map['title'])->toBe('Standard title in English (Plural)')
        // The blank header must not get claimed by any field.
        ->and($map)->not->toContain('');
});

/* ════════════════════════════════════════════════════════════════════
 * runPreflight — uses getRawState(), does not die on the confirm checkbox
 * ════════════════════════════════════════════════════════════════════ */

test('runPreflight validates the real prod CSV without the confirm checkbox ever being ticked', function () {
    $this->actingAs(wiz_admin());

    $component = Livewire::test(ImportWizard::class)
        ->set('data.import_type', 'series')
        ->set('data.file', UploadedFile::fake()->createWithContent('prod.csv', file_get_contents(WIZ_PROD_CSV)));

    // Deliberately do NOT set data.confirm — mirrors sitting on the Validate
    // step, exactly the state that used to make runPreflight() silently die.
    $component->call('runPreflight');

    $result = $component->instance()->preflightResult;
    expect($result)->not->toBeNull()
        ->and($result['total'])->toBe(30)
        ->and($result['valid'])->toBe(30)
        ->and($result['invalid'])->toBe(0);
});

test('runPreflight on the real Location template validates both Data rows cleanly', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $this->actingAs(wiz_admin($repo->id));

    $component = Livewire::test(ImportWizard::class)
        ->set('data.import_type', 'locations')
        ->set('data.file', UploadedFile::fake()->createWithContent('locations.xlsx', file_get_contents(WIZ_LOCATION_XLSX)));

    $component->call('runPreflight');

    $result = $component->instance()->preflightResult;
    expect($result)->not->toBeNull()
        ->and($result['total'])->toBe(2)
        ->and($result['invalid'])->toBe(0);
});

test('runPreflight leaves the uploaded file as a live TemporaryUploadedFile afterwards (unlike startImport)', function () {
    $this->actingAs(wiz_admin());

    $component = Livewire::test(ImportWizard::class)
        ->set('data.import_type', 'series')
        ->set('data.file', UploadedFile::fake()->createWithContent('prod.csv', file_get_contents(WIZ_PROD_CSV)));

    $component->call('runPreflight');

    $raw = $component->instance()->form->getRawState()['file'];
    $file = is_array($raw) ? reset($raw) : $raw;
    expect($file)->toBeInstanceOf(TemporaryUploadedFile::class);
});

/* ════════════════════════════════════════════════════════════════════
 * CRITICAL BUG — startImport() always rejects a real, valid submission.
 *
 * Root cause: startImport() calls $this->form->getState() (VALIDATED
 * state) at its first line. Filament's FileUpload component registers a
 * beforeStateDehydrated hook (BaseFileUpload::saveUploadedFiles(), vendor
 * source) that ANY call to getState() runs: it physically moves the
 * uploaded file to disk('local')/directory('imports'), deletes the temp
 * upload, and replaces the schema's 'file' state with a plain STRING path.
 * startImport() then checks `$file instanceof TemporaryUploadedFile`
 * (app/Filament/Pages/ImportWizard.php:428) against that ALREADY-STRING
 * value — a check that can never pass, because getState() is precisely
 * what converted it. Every real submission — any entity, any file — is
 * unconditionally rejected with "No file uploaded — go back to step 3.",
 * and no Import row / job batch is ever created.
 *
 * THE FIX: the 'file' FileUpload now uses storeFiles(false), so getState()
 * no longer dehydrates the upload — it returns the raw TemporaryUploadedFile
 * (and Filament no longer leaves an orphan copy under storage/app/imports).
 * The tests below prove the wizard now dispatches a real submission end to end.
 * ════════════════════════════════════════════════════════════════════ */

test('REGRESSION (bug #5): with storeFiles(false), getState() returns the raw TemporaryUploadedFile, not a string', function () {
    $this->actingAs(wiz_admin());

    $component = Livewire::test(ImportWizard::class)
        ->set('data.import_type', 'series')
        ->set('data.file', UploadedFile::fake()->createWithContent('prod.csv', file_get_contents(WIZ_PROD_CSV)))
        ->set('data.confirm', true);

    $beforeRaw = $component->instance()->form->getRawState()['file'];
    $beforeFile = is_array($beforeRaw) ? reset($beforeRaw) : $beforeRaw;
    expect($beforeFile)->toBeInstanceOf(TemporaryUploadedFile::class);

    // storeFiles(false) stops FileUpload's dehydration from moving the upload to
    // disk and replacing it with a string path — so startImport() can still read
    // a real TemporaryUploadedFile from getState() (this is the fix).
    $state = $component->instance()->form->getState();

    $stateFile = is_array($state['file']) ? reset($state['file']) : $state['file'];
    expect($stateFile)->toBeInstanceOf(TemporaryUploadedFile::class);
});

test('REGRESSION (bug #5): startImport() dispatches a fully valid real submission from the real button handler', function () {
    $this->actingAs(wiz_admin());

    $component = Livewire::test(ImportWizard::class)
        ->set('data.import_type', 'series')
        ->set('data.file', UploadedFile::fake()->createWithContent('prod.csv', file_get_contents(WIZ_PROD_CSV)))
        ->set('data.confirm', true);

    // Drive the REAL entry point the user clicks — NOT a reflection call on an
    // inner helper. Before the fix, getState() dehydrated the upload to a string
    // path and the `instanceof TemporaryUploadedFile` check rejected every
    // submission with "No file uploaded".
    $component->call('startImport')
        ->assertNotified('Import started');

    expect($component->instance()->lastImportId)->not->toBeNull()
        ->and(Import::query()->count())->toBe(1)
        ->and(Import::query()->first()->total_rows)->toBe(30); // all 30 real rows queued
});

test('REGRESSION (bug #5): startImport() dispatches for the Location template too, not just Series', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $this->actingAs(wiz_admin($repo->id));

    $component = Livewire::test(ImportWizard::class)
        ->set('data.import_type', 'locations')
        ->set('data.file', UploadedFile::fake()->createWithContent('locations.xlsx', file_get_contents(WIZ_LOCATION_XLSX)))
        ->set('data.confirm', true);

    $component->call('startImport')
        ->assertNotified('Import started');

    expect(Import::query()->count())->toBe(1);
});

test('REGRESSION (bug #5): runPreflight AND startImport both succeed on the SAME component', function () {
    $this->actingAs(wiz_admin());

    $component = Livewire::test(ImportWizard::class)
        ->set('data.import_type', 'series')
        ->set('data.file', UploadedFile::fake()->createWithContent('prod.csv', file_get_contents(WIZ_PROD_CSV)));

    // Preflight uses getRawState — always worked.
    $component->call('runPreflight');
    expect($component->instance()->preflightResult['total'])->toBe(30);

    // Start import on the SAME component now ALSO works (it reads the file from
    // getRawState before getState() can dehydrate it).
    $component->set('data.confirm', true)->call('startImport');

    expect($component->instance()->lastImportId)->not->toBeNull()
        ->and(Import::query()->count())->toBe(1);
});

/* ════════════════════════════════════════════════════════════════════
 * Misc wizard contract checks with real fixtures
 * ════════════════════════════════════════════════════════════════════ */

test('detectSheetNames reports both sheets for the real Location template, in file order', function () {
    $this->actingAs(wiz_admin());
    $page = wiz_page();
    $file = wiz_temp_file(WIZ_LOCATION_XLSX);

    $sheets = wiz_call($page, 'detectSheetNames', [$file]);

    expect($sheets)->toBe(['Data', 'READ ME']);
});

test('detectSheetNames returns empty for a real .csv (single implicit sheet)', function () {
    $this->actingAs(wiz_admin());
    $page = wiz_page();
    $file = wiz_temp_file(WIZ_PROD_CSV, 'prod.csv');

    expect(wiz_call($page, 'detectSheetNames', [$file]))->toBe([]);
});

test('parseFilePreview on the real prod CSV reports the correct total row count and header set', function () {
    $this->actingAs(wiz_admin());
    $page = wiz_page();
    $file = wiz_temp_file(WIZ_PROD_CSV, 'prod.csv');

    $info = wiz_call($page, 'parseFilePreview', [$file, 0]);

    expect($info)->not->toBeNull()
        ->and($info['totalRows'])->toBe(30)
        ->and($info['headers'])->toHaveCount(6)
        ->and($info['rows'])->toHaveCount(10); // capped preview
});
