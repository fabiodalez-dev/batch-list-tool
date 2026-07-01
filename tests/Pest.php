<?php

use App\Filament\Pages\Reports\StockTakeReport;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Bind Pest tests in Feature/ (and SecurityBaseline subdir) to the Laravel
| TestCase so they boot the application container.
|
*/

uses(TestCase::class)
    ->in('Feature');

uses(TestCase::class)
    ->in('Compliance');

// Browser (E2E) tests use a real headless Chromium via Pest's Playwright
// engine. RefreshDatabase is bound here so every E2E scenario starts from a
// clean, migrated schema and seeds only the data it needs.
uses(TestCase::class, RefreshDatabase::class)
    ->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Create a User with the given role, attached to a repository (created if not
 * given) as their default. Used to set up browser-E2E actors. Assumes
 * bl_seedShieldPermissions()/bl_seedRoles() has run.
 */
function bl_actor(string $role, ?Repository $repo = null): User
{
    $repo ??= Repository::factory()->create();
    $user = User::factory()->create(['default_repository_id' => $repo->id]);
    $user->assignRole($role);
    $user->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);

    return $user;
}

/**
 * Authenticate the headless browser as $user via the testing-only login route
 * and return the resulting page (positioned at the panel dashboard). From
 * here, chain ->navigate('/admin/...') to drive the E2E scenario.
 */
function bl_login(User $user)
{
    return visit('/__test-login__/' . $user->getKey());
}

function something()
{
    // Helper placeholder
}

/*
|--------------------------------------------------------------------------
| Shared seeding helpers for RefreshDatabase tests
|--------------------------------------------------------------------------
|
| Tests that use RefreshDatabase get a clean SQLite in-memory DB per class.
| Resource/Policy/Authorization tests need the four roles + the Shield-
| generated permissions assigned per role exactly as InitialDataSeeder does
| in dev/prod. We replicate that mapping here in PHP (no Artisan call) so it
| is fast enough to call from beforeEach() in every affected test file.
|
| All helper names are prefixed with `bl_` (Batch List) so they cannot
| collide with the per-file helpers each test already defines.
*/

/**
 * Seed the four operational roles. Idempotent.
 */
function bl_seedRoles(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $role) {
        Role::findOrCreate($role, 'web');
    }
}

/**
 * The list of (resource, operation) Shield permissions the policies depend on.
 * Keep this synchronised with InitialDataSeeder + the actual generated set.
 */
function bl_shieldPermissionNames(): array
{
    $resources = [
        'accession', 'audit', 'authority', 'batch', 'box', 'box_movement',
        'document', 'document_flag', 'import_profile', 'location', 'report',
        'report_template', 'repository', 'role', 'series', 'user', 'volume',
    ];
    $ops = [
        'view_any', 'view', 'create', 'update', 'delete', 'delete_any',
        'force_delete', 'force_delete_any', 'restore', 'restore_any',
        'replicate', 'reorder',
    ];

    $names = [];
    foreach ($resources as $r) {
        foreach ($ops as $o) {
            $names[] = "{$o}_{$r}";
        }
    }

    // Custom (non-Shield-default) permission gating DocumentFlag workflow
    // transitions; mirrored from InitialDataSeeder.
    $names[] = 'resolve_document_flag';

    return $names;
}

/**
 * Seed Shield permissions + assign them per role (admin = all, editor =
 * view/create/update/reorder, viewer = view_*). Mirrors
 * InitialDataSeeder::run() without invoking shield:generate (which would
 * cost ~500ms per call).
 *
 * Also assigns every permission to super_admin so policies that check
 * specific permissions (e.g. `view_any_document`) succeed for super_admin
 * users — InitialDataSeeder relies on shield:generate to do this via
 * giveSuperAdminPermission(); we do it explicitly here.
 *
 * Idempotent: safe to call multiple times per test class (RefreshDatabase
 * resets between classes; beforeEach inside a class is a no-op after the
 * first call because firstOrCreate is used).
 */
function bl_seedShieldPermissions(): void
{
    bl_seedRoles();

    $names = bl_shieldPermissionNames();
    foreach ($names as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $all = Permission::pluck('name')->all();

    /** @var Role $superAdmin */
    $superAdmin = Role::findByName('super_admin', 'web');
    $superAdmin->syncPermissions($all);

    /** @var Role $admin */
    $admin = Role::findByName('admin', 'web');
    $admin->syncPermissions($all);

    /** @var Role $editor */
    $editor = Role::findByName('editor', 'web');
    $editor->syncPermissions(
        collect($all)
            ->filter(fn ($p) => str_starts_with($p, 'view_')
                || str_starts_with($p, 'create_')
                || str_starts_with($p, 'update_')
                || str_starts_with($p, 'reorder_')
                || $p === 'resolve_document_flag')
            ->all()
    );

    /** @var Role $viewer */
    $viewer = Role::findByName('viewer', 'web');
    $viewer->syncPermissions(
        collect($all)
            ->filter(fn ($p) => str_starts_with($p, 'view_') && ! str_ends_with($p, '_user'))
            ->all()
    );

    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/*
|--------------------------------------------------------------------------
| Reusable field builders for the NAF Bug-Log + Queries suites (qf_*)
|--------------------------------------------------------------------------
|
| Small, composable factories used by the "fields touched by the document"
| tests. Prefixed `qf_` (Queries/Fields) so they never collide with the
| per-file helpers. They bypass the tenant global scopes when seeding so a
| test can build cross-repository fixtures deterministically.
*/

/** Super-admin (bypasses Gate + tenant scope), optionally bound to a repository. */
function qf_admin(?int $repoId = null): User
{
    bl_seedRoles();
    /** @var User $u */
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repoId]);
    $u->assignRole('super_admin');

    return $u;
}

/** A repository with a unique code. */
function qf_repo(string $prefix = 'QF'): Repository
{
    return Repository::factory()->create(['code' => $prefix . substr(uniqid(), -6)]);
}

/** A series (idempotent by code). */
function qf_series(string $code = 'REG'): Series
{
    return Series::firstOrCreate(['code' => $code], ['title' => $code . ' series', 'is_active' => true]);
}

/** A batch (factory sets a repository when none given). */
function qf_batch(array $attrs = []): Batch
{
    return Batch::factory()->create($attrs);
}

/** A box (factory defaults to a RAS box with a batch + barcode). */
function qf_box(array $attrs = []): Box
{
    return Box::factory()->create($attrs);
}

/** A document (factory sets the required series/repository). */
function qf_doc(array $attrs = []): Document
{
    return Document::factory()->create($attrs);
}

/** A location, optionally repository-scoped (NULL = global). Bypasses the scope on seed. */
function qf_location(?int $repoId = null, array $attrs = []): Location
{
    return Location::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'name' => 'Room ' . substr(uniqid(), -6),
        'type' => 'room',
        'repository_id' => $repoId,
        'is_active' => true,
    ], $attrs));
}

/** Run StockTakeReport::reportQuery() (protected) and return the row for a location. */
function qf_stockRow(int $locationId): ?Location
{
    $page = new StockTakeReport;
    $m = new ReflectionMethod($page, 'reportQuery');
    $m->setAccessible(true);

    /** @var Location|null $row */
    $row = $m->invoke($page)->where('locations.id', $locationId)->first();

    return $row;
}
