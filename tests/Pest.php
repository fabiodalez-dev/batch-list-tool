<?php

use App\Models\Repository;
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
            ->filter(fn ($p) => str_starts_with($p, 'view_'))
            ->all()
    );

    app(PermissionRegistrar::class)->forgetCachedPermissions();
}
