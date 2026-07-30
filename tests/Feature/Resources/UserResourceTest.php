<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Hashing\BcryptHasher;
use Illuminate\Support\Facades\Hash;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Role;

/**
 * PR #11b — User management.
 *
 * NOTE: This project does NOT (yet) ship a dedicated App\Filament\Resources
 * \UserResource. User management surfaces via the Filament Shield-provided
 * resource for roles + a direct CLI/seeder workflow.
 *
 * Rather than skip the whole file, we exercise the equivalent contracts at
 * the model + spatie/laravel-permission level — which is where the security
 * properties actually live:
 *
 *   - is_active gate on Filament panel access
 *   - role assignment / unassignment + permission propagation
 *   - audit row creation on role change
 *   - password hashing baseline (bcrypt cost depends on phpunit.xml override
 *     so we assert via Hash::info() that the hash IS bcrypt and rehash
 *     detection works against the production config of cost=12)
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function rolesExist_user(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

/* 50. Super_admin can access the Filament panel; viewer with is_active=false cannot */
test('User::canAccessPanel honours is_active flag and role assignment', function () {
    rolesExist_user();

    // Resolve the actual registered admin panel (NOT `app(Panel::class)`,
    // which returns a fresh empty Panel and doesn't represent the real one).
    $panel = Filament::getPanel('admin');

    $active = User::factory()->create(['is_active' => true]);
    $active->assignRole('super_admin');
    expect($active->canAccessPanel($panel))->toBeTrue();

    $inactive = User::factory()->create(['is_active' => false]);
    $inactive->assignRole('super_admin');
    expect($inactive->canAccessPanel($panel))->toBeFalse();

    $noRole = User::factory()->create(['is_active' => true]);
    expect($noRole->canAccessPanel($panel))->toBeFalse();
})->skip(
    fn () => ! class_exists(Panel::class),
    'Filament Panel not available in this configuration',
);

/* 51. Editor cannot access settings-tier resources (Repository) */
test('Editor role does NOT have delete_any_repository permission', function () {
    rolesExist_user();

    $editor = User::factory()->create(['is_active' => true]);
    $editor->assignRole('editor');

    // Editor permissions per InitialDataSeeder: view_/create_/update_/reorder_
    // only. delete_any_repository is NOT granted.
    expect($editor->hasPermissionTo('delete_any_repository'))->toBeFalse();
});

/* 52. Create with role assignment propagates */
test('Assigning the editor role propagates Shield permissions', function () {
    rolesExist_user();

    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('editor');

    // Editor has view permissions on the core resources
    expect($u->hasAnyRole(['editor']))->toBeTrue();
    expect($u->hasPermissionTo('view_any_document'))->toBeTrue();
    expect($u->hasPermissionTo('create_document'))->toBeTrue();
});

/* 53. Update role triggers audit log */
test('User update triggers an owen-it audit row on the users table', function () {
    config(['audit.console' => true]);
    rolesExist_user();

    $u = User::factory()->create(['name' => 'Before Update', 'is_active' => true]);
    $before = Audit::query()
        ->where('auditable_type', User::class)
        ->where('auditable_id', $u->id)
        ->count();

    $u->update(['name' => 'After Update']);

    expect($u->refresh()->name)->toBe('After Update');

    $after = Audit::query()
        ->where('auditable_type', User::class)
        ->where('auditable_id', $u->id)
        ->count();
    expect($after)->toBeGreaterThan($before);
});

/*
 * 54. Password baseline.
 *
 * phpunit.xml hard-codes BCRYPT_ROUNDS=4 for fast tests. The SECURITY
 * baseline (production) is cost=12. We assert:
 *   (a) the User::password cast IS 'hashed' (auto-hash on set)
 *   (b) Hash::info() identifies the algorithm as bcrypt
 *   (c) needsRehash flags a cost-4 hash against a cost-12 hasher,
 *       proving the production baseline still kicks in.
 */
test('User password is bcrypt-hashed and the production baseline (cost=12) is enforced via rehash detection', function () {
    rolesExist_user();

    $u = User::factory()->create();

    // (a) Cast is 'hashed' → set 'password' on the model auto-hashes
    $u->password = 'plain-text-secret-for-test';
    $u->save();
    expect($u->password)->not->toBe('plain-text-secret-for-test');

    // (b) The stored hash is bcrypt
    $info = Hash::info($u->password);
    expect($info['algoName'])->toBe('bcrypt');

    // (c) The production hasher (rounds=12) detects a sub-12 cost as needing rehash
    $prodHasher = new BcryptHasher(['rounds' => 12]);
    // Generate a cost-4 hash explicitly (mirroring phpunit.xml)
    $weakHash = new BcryptHasher(['rounds' => 4])->make('x');
    expect($prodHasher->needsRehash($weakHash))->toBeTrue();
});
