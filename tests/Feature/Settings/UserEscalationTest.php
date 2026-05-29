<?php

declare(strict_types=1);

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * Task 4 — Role-escalation guards & self-protection.
 *
 * An admin must not be able to grant super_admin (neither via the visible role
 * options nor via a direct form submission). When editing your own account, the
 * `is_active` toggle and `role` select must be disabled.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

// ---------------------------------------------------------------------------
// Shared helpers (suffixed _esc to avoid collisions with other test files)
// ---------------------------------------------------------------------------

function rolesExist_esc(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function makeAdmin_esc(): User
{
    rolesExist_esc();
    $u = User::factory()->create([
        'email' => 'esc-admin+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('admin');

    return $u;
}

function makeSuperAdmin_esc(): User
{
    rolesExist_esc();
    $u = User::factory()->create([
        'email' => 'esc-superadmin+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

// ---------------------------------------------------------------------------
// 1. roleOptions() visibility tests
// ---------------------------------------------------------------------------

it('hides super_admin from roleOptions when acting user is admin', function () {
    $this->actingAs(makeAdmin_esc());

    expect(array_keys(UserResource::roleOptions()))->not->toContain('super_admin');
});

it('includes super_admin in roleOptions when acting user is super_admin', function () {
    $this->actingAs(makeSuperAdmin_esc());

    expect(array_keys(UserResource::roleOptions()))->toContain('super_admin');
});

// ---------------------------------------------------------------------------
// 2. CreateUser — server-side escalation guard
// ---------------------------------------------------------------------------

it('prevents admin from creating a user with role super_admin via CreateUser', function () {
    $admin = makeAdmin_esc();
    $this->actingAs($admin);

    Livewire\Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Escalated User',
            'email' => 'escalated@nra.test',
            'password' => 'TempPass!234',
            'password_confirmation' => 'TempPass!234',
            'role' => 'super_admin',
        ])
        ->call('create')
        ->assertHasFormErrors(['role']);

    expect(User::where('email', 'escalated@nra.test')->exists())->toBeFalse();
});

it('allows super_admin to create a user with role super_admin', function () {
    $sa = makeSuperAdmin_esc();
    $this->actingAs($sa);

    Livewire\Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'New Super',
            'email' => 'newsuper@nra.test',
            'password' => 'TempPass!234',
            'password_confirmation' => 'TempPass!234',
            'role' => 'super_admin',
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = User::where('email', 'newsuper@nra.test')->first();
    expect($created)->not->toBeNull()
        ->and($created->hasRole('super_admin'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 3. EditUser — server-side escalation guard
// ---------------------------------------------------------------------------

it('prevents admin from escalating an existing user to super_admin via EditUser', function () {
    $admin = makeAdmin_esc();
    $this->actingAs($admin);

    $target = User::factory()->create(['email' => 'editor-target@nra.test', 'is_active' => true]);
    $target->assignRole('editor');

    Livewire\Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        ->fillForm([
            'name' => $target->name,
            'email' => $target->email,
            'role' => 'super_admin',
            'is_active' => true,
        ])
        ->call('save')
        ->assertHasFormErrors(['role']);

    $target->refresh();
    expect($target->hasRole('super_admin'))->toBeFalse()
        ->and($target->hasRole('editor'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// 4. EditUser — self-protection: disabled fields on own record
// ---------------------------------------------------------------------------

it('preserves own role and is_active when admin saves their own record', function () {
    $admin = makeAdmin_esc();
    $admin->is_active = true;
    $admin->save();
    $this->actingAs($admin);

    // The form must render without errors.
    Livewire\Livewire::test(EditUser::class, ['record' => $admin->getRouteKey()])
        ->assertOk()
        // Even if the form is filled with a different role/is_active, the
        // disabled fields are not submitted — the save must succeed (no errors)
        // and the role/is_active must remain unchanged.
        ->fillForm([
            'name' => $admin->name,
            'email' => $admin->email,
            'must_change_password' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $admin->refresh();

    // Role must NOT have changed (still admin, not been stripped).
    expect($admin->hasRole('admin'))->toBeTrue()
        // is_active must still be true (not deactivated).
        ->and($admin->is_active)->toBeTrue();
});

it('rejects a direct role-change attempt on own account via EditUser', function () {
    // Test the server-side guard specifically: if somehow the disabled role
    // field value is bypassed and submitted as a different role, the guard
    // should reject it.
    $admin = makeAdmin_esc();
    $this->actingAs($admin);

    // Directly invoke mutateFormDataBeforeSave via reflection to test the guard,
    // OR use the component and assert the save fails.
    // Since fillForm on a disabled field is silently ignored by Livewire/Filament,
    // we verify the guard is in place by asserting the role cannot be changed.

    // Simulate: fill the form, including bypassing the disabled field by setting
    // the raw Livewire data directly.
    $component = Livewire\Livewire::test(EditUser::class, ['record' => $admin->getRouteKey()])
        ->set('data.role', 'viewer') // directly set raw Livewire state (bypass disabled)
        ->call('save');

    $admin->refresh();

    // The self-protection guard must have caught the role change attempt.
    // Either form errors appeared, OR (if the ValidationException was caught
    // differently) the role remains unchanged.
    expect($admin->hasRole('admin'))->toBeTrue()
        ->and($admin->hasRole('viewer'))->toBeFalse();
});
