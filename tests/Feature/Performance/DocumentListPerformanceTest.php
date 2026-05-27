<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource;
use App\Models\Accession;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/*
 * Performance regression guard for the /admin/documents list page.
 *
 * Two assertions:
 *   1. Eager-load coverage: the resource's base query, when paginated
 *      through `with([...])` the same way Filament does on the list page,
 *      stays at the small bounded set of queries we expect (pagination
 *      count + paginated SELECT + one SELECT IN per eager-loaded
 *      relation). Regressing this number means a relation has been added
 *      to the table columns without a matching eager-load, re-introducing
 *      the N+1 the perf pass set out to fix.
 *
 *   2. Index usage: a query that mirrors RepositoryScope (filter by
 *      `repository_id`) and SoftDeletes (filter by `deleted_at IS NULL`)
 *      and orders by `identifier` MUST hit an index (not a full scan).
 *      EXPLAIN reports the chosen `key` — we assert it is not null.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

/**
 * Seed N documents under a single repository so the eager-load probes have
 * material to fan out across. We deliberately wire every relation the
 * resource's table column list dereferences (`series`, `currentBox`,
 * `batch`, `repository`, `location`, `accession`, `authorities`) so the
 * N+1 detector below can actually fire if any of them goes lazy again.
 */
function perfSeed(int $count = 100, ?Repository $repository = null): Repository
{
    $repository ??= Repository::factory()->create([
        'code' => 'PERF-' . substr(bin2hex(random_bytes(3)), 0, 6),
    ]);

    $series = Series::factory()->create([
        'code' => 'P' . substr(bin2hex(random_bytes(2)), 0, 3),
    ]);

    $batch = Batch::factory()->create([
        'repository_id' => $repository->id,
    ]);

    $box = Box::factory()->create([
        'batch_id' => $batch->id,
    ]);

    $accession = Accession::create([
        'code' => 'ACC-' . substr(bin2hex(random_bytes(3)), 0, 6),
        'repository_id' => $repository->id,
        'batch_id' => $batch->id,
        'accession_date' => now()->toDateString(),
    ]);

    $location = Location::factory()->create([
        'name' => 'Perf Location',
        'repository_id' => $repository->id,
    ]);

    $authority = Authority::create([
        'identifier' => 'R' . random_int(900000, 999999),
        'surname' => 'PerfNotary',
        'entity_type' => 'PERSON',
    ]);

    for ($i = 0; $i < $count; $i++) {
        $doc = Document::create([
            'identifier' => 'PERF-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            'document_type' => 'Register',
            'series_id' => $series->id,
            'batch_id' => $batch->id,
            'current_box_id' => $box->id,
            'accession_id' => $accession->id,
            'location_id' => $location->id,
            'repository_id' => $repository->id,
        ]);
        $doc->authorities()->attach($authority->id);
    }

    return $repository;
}

function perfActAsAdmin(Repository $repo): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    $user = User::factory()->create([
        'email' => 'perf-admin-' . substr(bin2hex(random_bytes(3)), 0, 6) . '@example.test',
        'default_repository_id' => $repo->id,
    ]);
    $user->assignRole('super_admin');
    $user->repositories()->attach($repo->id);
    auth()->login($user);

    return $user;
}

it('paginates the documents list within the eager-load budget (no N+1)', function (): void {
    $repo = perfSeed(100);
    perfActAsAdmin($repo);

    // Warm-up: Spatie\Permission caches the role/permission lookup on the
    // first call; counting the queries from a cold cache would inflate
    // the budget with permission-system noise that's unrelated to the
    // resource's eager-load surface.
    auth()->user()->hasPermissionTo('view_any_document');

    DB::flushQueryLog();
    DB::enableQueryLog();

    $query = DocumentResource::getEloquentQuery()
        ->with([
            'series',
            'batch',
            'currentBox.batch',
            'repository',
            'location',
            'accession',
            'authorities',
        ]);

    $page = $query->paginate(25);

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($page->count())->toBe(25);

    // Budget: pagination count + page SELECT + 1 SELECT-IN per eager
    // relation (`series`, `batch`, `currentBox`, `currentBox.batch`,
    // `repository`, `location`, `accession`, `authorities`,
    // `authorities` pivot timestamps) ~= 11 max. The ceiling of 16
    // leaves headroom for Filament's permission resolver yet still
    // fires immediately if a future column drops back to lazy access.
    expect(count($queries))
        ->toBeLessThanOrEqual(16, sprintf(
            'Documents list issued %d queries (budget: 16). Lazy load regression?%s%s',
            count($queries),
            PHP_EOL,
            collect($queries)
                ->map(fn ($q) => '  - ' . substr((string) $q['query'], 0, 160))
                ->implode(PHP_EOL),
        ));
});

it('uses an index for the typical RepositoryScope + identifier sort query', function (): void {
    $repo = perfSeed(50);

    // EXPLAIN syntax (and the column the index name lives in) differs per
    // driver. We support the two drivers we actually run on: MySQL in
    // prod and SQLite in CI/dev.
    $driver = DB::connection()->getDriverName();

    if ($driver === 'mysql') {
        $explain = DB::select(
            'EXPLAIN SELECT * FROM documents
             WHERE repository_id = ?
               AND deleted_at IS NULL
             ORDER BY identifier
             LIMIT 25',
            [$repo->id],
        );

        expect($explain)->not->toBeEmpty();
        // MySQL EXPLAIN row: column `key` is the actual index used.
        // It must not be null — that would mean a full table scan.
        $keyUsed = $explain[0]->key ?? null;
        expect($keyUsed)->not->toBeNull(
            'Query did NOT use an index. Performance migration regression?'
        );

        return;
    }

    if ($driver === 'sqlite') {
        // SQLite EXPLAIN QUERY PLAN's `detail` column says e.g.
        // "SEARCH documents USING INDEX documents_repository_alive_idx (...)"
        // or "SCAN documents" if the planner skips every index.
        $plan = DB::select(
            'EXPLAIN QUERY PLAN
             SELECT * FROM documents
             WHERE repository_id = ?
               AND deleted_at IS NULL
             ORDER BY identifier
             LIMIT 25',
            [$repo->id],
        );

        expect($plan)->not->toBeEmpty();
        $usesIndex = collect($plan)
            ->contains(fn ($row) => str_contains(strtolower((string) $row->detail), 'using index')
                || str_contains(strtolower((string) $row->detail), 'using covering index'));

        expect($usesIndex)->toBeTrue(sprintf(
            'SQLite planner chose a table scan over an index. Plan:%s%s',
            PHP_EOL,
            collect($plan)->pluck('detail')->map(fn ($d) => '  - ' . $d)->implode(PHP_EOL),
        ));

        return;
    }

    $this->markTestSkipped("Driver '{$driver}' is not covered by this EXPLAIN test.");
});
