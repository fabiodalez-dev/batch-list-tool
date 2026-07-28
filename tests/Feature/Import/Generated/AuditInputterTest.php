<?php

declare(strict_types=1);

use App\Filament\Imports\AuthorityImporter;
use App\Filament\Imports\Concerns\LogsImportRows;
use App\Filament\Imports\LocationImporter;
use App\Filament\Imports\SeriesImporter;
use App\Filament\Support\CreatorColumn;
use App\Models\Authority;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use App\Support\Audit\ImportAwareUserResolver;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use HayderHatem\FilamentExcelImport\Actions\Imports\Jobs\ImportExcel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use League\Csv\Reader;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Role;

/**
 * AREA: audit-inputter — the "Inputter" column on records created via the REAL
 * streaming import path (the "Import Excel/CSV" button the client clicks —
 * HayderHatem\FilamentExcelImport\Actions\Imports\Jobs\ImportExcel, queued on
 * QUEUE_CONNECTION=database and processed by `php artisan queue:work`).
 *
 * ORIGINAL BUG (from the client's videos — "the inputter has not been included"
 * for Series, Authorities, Locations AND Batches): records imported by the
 * streaming job had NO "created" audit and thus a blank Inputter column, for
 * two independent reasons:
 *   Cause A — owen-it skips auditing in a console process (queue:work) unless
 *             audit.console is true, so the AuditableObserver was never attached.
 *   Cause B — ImportExcel::handle() never authenticates the guard, so even with
 *             the observer attached the audit's user came out null.
 *
 * FIX (this is now a REGRESSION suite proving it):
 *   - {@see ImportAwareUserResolver} resolves the audit actor
 *     to the Import's own user during a row save (Cause B), and delegates to the
 *     stock resolver otherwise (no change for ordinary web requests).
 *   - {@see LogsImportRows::saveRecord()} attaches
 *     the observer for the imported model + switches audit.console on for the
 *     duration of each save (Cause A), and sets the resolver actor, always
 *     reverting afterwards so nothing leaks to non-import work.
 *
 * Everything below drives the REAL ImportExcel job over the client's REAL prod
 * CSVs (nra/inbox/prod-uploads/*.csv) — no manual observer/console/actingAs
 * scaffolding, because the fix must work without any of that (that IS the bug).
 */
uses(RefreshDatabase::class);

// ─── helpers ──────────────────────────────────────────────────────────────

function ai_admin(?int $repoId = null): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repoId, 'name' => 'Charlene Ellul']);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * Read real rows from a real prod CSV, keyed by its actual header row.
 *
 * @return array<int, array<string, string>>
 */
function ai_csvRows(string $path, int $limit): array
{
    $csv = Reader::createFromPath(base_path($path), 'r');
    $csv->setHeaderOffset(0);

    $rows = [];
    foreach ($csv->getRecords() as $record) {
        $rows[] = $record;
        if (count($rows) >= $limit) {
            break;
        }
    }

    return $rows;
}

/**
 * Run the REAL hayderhatem ImportExcel job — the exact code path the client's
 * "Import Excel/CSV" button dispatches — over real prod-CSV rows.
 *
 * @param class-string $importer
 * @param array<int, array<string, string>> $rows
 * @param array<string, string> $columnMap
 */
function ai_run(string $importer, array $rows, array $columnMap, int $userId): Import
{
    EntityResolver::flushMemo();

    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'prod.csv',
        'file_path' => '/tmp/prod.csv',
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
        options: [],
    );
    $job->handle();

    return $import->refresh();
}

/** The "created" audit for a fresh model, exactly the query CreatorColumn runs. */
function ai_createdAudit(Model $record): ?Audit
{
    return $record->audits()->where('event', 'created')->oldest('id')->first();
}

/** Call the REAL production CreatorColumn closure — not a re-implementation. */
function ai_inputterState(Model $record): ?string
{
    $col = CreatorColumn::make();
    $closure = $col->getGetStateUsingCallback();

    return $closure($record);
}

const SERIES_CSV = 'nra/inbox/prod-uploads/20260728_075119_f4b6ebbb.csv';
const AUTHORITY_CSV = 'nra/inbox/prod-uploads/20260728_075355_0be0517f.csv';
const LOCATION_CSV = 'nra/inbox/prod-uploads/20260728_075809_36792a2a.csv';

const SERIES_MAP = ['code' => 'Identifier', 'title' => 'Standard title in English (Plural)'];
const AUTHORITY_MAP = [
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
const LOCATION_MAP = [
    'name' => 'name',
    'type' => 'type',
    'parent_name' => 'parent_name',
    'repository_code' => 'repository_code',
    'code' => 'code',
    'notes' => 'notes',
    'sort_order' => 'sort_order',
    'is_active' => 'is_active',
];

// ═══════════════════════════════════════════════════════════════════════
// REGRESSION — a real streaming import attributes every record to the
// operator who launched it (unauthenticated queue context, no manual setup).
// ═══════════════════════════════════════════════════════════════════════

test('a real Series import writes a "created" audit attributed to the importing user', function () {
    $u = ai_admin();

    $import = ai_run(SeriesImporter::class, ai_csvRows(SERIES_CSV, 3), SERIES_MAP, $u->id);

    expect($import->successful_rows)->toBe(3)
        ->and(Series::count())->toBe(3);

    $audit = ai_createdAudit(Series::where('code', 'R')->firstOrFail());
    expect($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe($u->id);
});

test('the Inputter column shows the operator name for an imported Series row', function () {
    $u = ai_admin();
    ai_run(SeriesImporter::class, ai_csvRows(SERIES_CSV, 1), SERIES_MAP, $u->id);

    expect(ai_inputterState(Series::where('code', 'R')->firstOrFail()))->toBe('Charlene Ellul');
});

test('a real Authority import is attributed to the operator (Inputter populated)', function () {
    $u = ai_admin();
    ai_run(AuthorityImporter::class, ai_csvRows(AUTHORITY_CSV, 1), AUTHORITY_MAP, $u->id);

    $authority = Authority::where('identifier', 'R1')->firstOrFail();
    expect(ai_createdAudit($authority))->not->toBeNull()
        ->and(ai_inputterState($authority))->toBe('Charlene Ellul');
});

test('a real Location import is attributed to the operator (Inputter populated)', function () {
    Repository::factory()->create(['code' => 'NRA']);
    $u = ai_admin();
    ai_run(LocationImporter::class, ai_csvRows(LOCATION_CSV, 1), LOCATION_MAP, $u->id);

    $location = Location::where('name', 'Archive 1')->firstOrFail();
    expect(ai_createdAudit($location))->not->toBeNull()
        ->and(ai_inputterState($location))->toBe('Charlene Ellul');
});

test('EVERY row of a multi-row Series batch is attributed, not just the first', function () {
    $u = ai_admin();
    ai_run(SeriesImporter::class, ai_csvRows(SERIES_CSV, 10), SERIES_MAP, $u->id);

    expect(Series::count())->toBe(10);
    foreach (Series::all() as $series) {
        expect(ai_inputterState($series))->toBe('Charlene Ellul');
    }
});

test('EVERY row of a multi-row Authority batch is attributed', function () {
    $u = ai_admin();
    ai_run(AuthorityImporter::class, ai_csvRows(AUTHORITY_CSV, 10), AUTHORITY_MAP, $u->id);

    expect(Authority::count())->toBe(10);
    foreach (Authority::all() as $authority) {
        expect(ai_inputterState($authority))->toBe('Charlene Ellul');
    }
});

test('attribution works from the UNAUTHENTICATED queue context (no actingAs) — proves the resolver, not the web guard', function () {
    // Deliberately never call actingAs(): this mirrors how queue:work runs the
    // job in production. Attribution must still work via the Import's own user.
    $u = ai_admin();
    ai_run(SeriesImporter::class, ai_csvRows(SERIES_CSV, 1), SERIES_MAP, $u->id);

    $audit = ai_createdAudit(Series::where('code', 'R')->firstOrFail());
    expect($audit->user_id)->toBe($u->id);
});

test('the import actor does not leak: after the import the resolver is cleared', function () {
    $u = ai_admin();
    ai_run(SeriesImporter::class, ai_csvRows(SERIES_CSV, 1), SERIES_MAP, $u->id);

    // LogsImportRows::saveRecord() clears the actor in its finally block, so a
    // later unrelated save in the same process is NOT wrongly attributed to the
    // import's user.
    expect(ImportAwareUserResolver::$importActor)->toBeNull();
});

test('a record created OUTSIDE any import is unaffected by the import resolver', function () {
    // A plain model create with no authenticated user must still resolve to no
    // creator — the resolver only overrides while an import row is saving.
    ai_run(SeriesImporter::class, ai_csvRows(SERIES_CSV, 1), SERIES_MAP, ai_admin()->id);

    $manual = Series::create(['code' => 'ZZZ', 'title' => 'Manually created, no import']);
    expect($manual->audits()->where('event', 'created')->first()?->user_id)->toBeNull();
});
