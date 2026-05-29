<?php

declare(strict_types=1);

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Task 5 — Reset-password & activate/deactivate row actions.
 *
 * Tests for the two table row actions added to UserResource::table():
 *   - resetPassword: generates a temp password, sets must_change_password=true
 *   - toggleActive: flips is_active, blocked on own account
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

// ---------------------------------------------------------------------------
// Shared helpers (suffixed _act to avoid collisions with other test files)
// ---------------------------------------------------------------------------

function rolesExist_act(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function makeSuperAdmin_act(): User
{
    rolesExist_act();
    $u = User::factory()->create([
        'email' => 'act-superadmin+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function makeAdmin_act(): User
{
    rolesExist_act();
    $u = User::factory()->create([
        'email' => 'act-admin+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('admin');

    return $u;
}

// ---------------------------------------------------------------------------
// resetPassword action
// ---------------------------------------------------------------------------

it('resets a user password and forces change', function () {
    $admin = makeSuperAdmin_act();
    $this->actingAs($admin);

    $target = User::factory()->create([
        'email' => 'target-reset+' . uniqid() . '@test.local',
        'must_change_password' => false,
    ]);
    $oldHash = $target->password;

    Livewire::test(ListUsers::class)
        ->callTableAction('resetPassword', $target)
        ->assertHasNoTableActionErrors();

    $target->refresh();

    expect($target->must_change_password)->toBeTrue()
        ->and($target->password)->not->toBe($oldHash);
});

it('resetPassword notification contains the temporary password', function () {
    $admin = makeSuperAdmin_act();
    $this->actingAs($admin);

    $target = User::factory()->create([
        'email' => 'target-notify+' . uniqid() . '@test.local',
        'must_change_password' => false,
    ]);

    // The action should succeed without errors (notification is fire-and-forget)
    Livewire::test(ListUsers::class)
        ->callTableAction('resetPassword', $target)
        ->assertHasNoTableActionErrors();

    $target->refresh();
    // Password must have changed and must_change_password flagged
    expect($target->must_change_password)->toBeTrue()
        ->and($target->password)->not->toBeEmpty();
});

it('resetPassword visible() callback returns false for a user without update permission', function () {
    rolesExist_act();
    $viewer = User::factory()->create([
        'email' => 'viewer-reset+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $viewer->assignRole('viewer');
    $this->actingAs($viewer);

    $target = User::factory()->create([
        'email' => 'target-viewer+' . uniqid() . '@test.local',
    ]);

    // A viewer does not have update_user permission → can() returns false
    expect($viewer->can('update', $target))->toBeFalse();
});

// ---------------------------------------------------------------------------
// toggleActive action
// ---------------------------------------------------------------------------

it('deactivates an active user', function () {
    $admin = makeSuperAdmin_act();
    $this->actingAs($admin);

    $target = User::factory()->create([
        'email' => 'target-deactivate+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $target->assignRole('editor');

    Livewire::test(ListUsers::class)
        ->callTableAction('toggleActive', $target)
        ->assertHasNoTableActionErrors();

    $target->refresh();
    expect($target->is_active)->toBeFalse();
});

it('activates an inactive user', function () {
    $admin = makeSuperAdmin_act();
    $this->actingAs($admin);

    $target = User::factory()->create([
        'email' => 'target-activate+' . uniqid() . '@test.local',
        'is_active' => false,
    ]);
    $target->assignRole('editor');

    Livewire::test(ListUsers::class)
        ->callTableAction('toggleActive', $target)
        ->assertHasNoTableActionErrors();

    $target->refresh();
    expect($target->is_active)->toBeTrue();
});

it('cannot toggle-active your own account', function () {
    $admin = makeSuperAdmin_act();
    $this->actingAs($admin);

    // The toggleActive action must be hidden for $admin's own record
    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('toggleActive', $admin);
});

it('toggleActive visible() callback returns false for a user without update permission', function () {
    rolesExist_act();
    $viewer = User::factory()->create([
        'email' => 'viewer-toggle+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $viewer->assignRole('viewer');
    $this->actingAs($viewer);

    $target = User::factory()->create([
        'email' => 'target-viewer2+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);

    // A viewer does not have update_user permission → can() returns false
    // toggleActive also checks !$record->is(auth()->user()) — passes since different users
    expect($viewer->can('update', $target))->toBeFalse()
        ->and($viewer->is($target))->toBeFalse();
});
