<?php

declare(strict_types=1);

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Task 3 — App\Filament\Resources\UserResource (admin User CRUD).
 *
 * UserResource is the centerpiece of the Administration navigation group: it
 * lets super_admin / admin operators provision new users, assign a role, pin
 * them to repositories and force a password change on first login.
 *
 * Role assignment is NOT a User column — the `role` form field is synced into
 * spatie/laravel-permission on create/edit (see CreateUser/EditUser pages).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function rolesExist_userres(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function actAsSuperAdmin_userres(): User
{
    rolesExist_userres();
    $u = User::factory()->create([
        'email' => 'userres-superadmin+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

it('creates a user with role, repositories and forced password change', function () {
    $this->actingAs(actAsSuperAdmin_userres());

    $repo = Repository::factory()->create();

    Livewire\Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Maria Borg',
            'email' => 'maria@nra.test',
            'password' => 'TempPass!234',
            'password_confirmation' => 'TempPass!234',
            'role' => 'editor',
            'repositories' => [$repo->id],
            'default_repository_id' => $repo->id,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $u = User::where('email', 'maria@nra.test')->first();

    expect($u)->not->toBeNull()
        ->and($u->must_change_password)->toBeTrue()
        ->and($u->hasRole('editor'))->toBeTrue()
        ->and(Hash::check('TempPass!234', $u->password))->toBeTrue()
        ->and($u->repositories->pluck('id')->all())->toContain($repo->id)
        ->and($u->default_repository_id)->toBe($repo->id)
        ->and($u->is_active)->toBeTrue();
});

it('lists users for an administrator', function () {
    $this->actingAs(actAsSuperAdmin_userres());

    $target = User::factory()->create(['name' => 'Listed User']);

    Livewire\Livewire::test(ListUsers::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$target]);
});

it('edits a user and re-syncs the role without rehashing an empty password', function () {
    $this->actingAs(actAsSuperAdmin_userres());

    $repo = Repository::factory()->create();

    $target = User::factory()->create([
        'email' => 'edit-target@nra.test',
        'is_active' => true,
    ]);
    $target->assignRole('viewer');
    $originalHash = $target->password;

    Livewire\Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
        ->fillForm([
            'name' => 'Edited Name',
            'email' => 'edit-target@nra.test',
            'role' => 'editor',
            'repositories' => [$repo->id],
            'default_repository_id' => $repo->id,
            'is_active' => true,
            'must_change_password' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $target->refresh();

    expect($target->name)->toBe('Edited Name')
        ->and($target->hasRole('editor'))->toBeTrue()
        ->and($target->hasRole('viewer'))->toBeFalse()
        ->and($target->password)->toBe($originalHash) // blank password => not rehashed
        ->and($target->repositories->pluck('id')->all())->toContain($repo->id);
});

it('requires a password on create', function () {
    $this->actingAs(actAsSuperAdmin_userres());

    Livewire\Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'No Password',
            'email' => 'nopass@nra.test',
            'role' => 'viewer',
        ])
        ->call('create')
        ->assertHasFormErrors(['password' => 'required']);
});

it('exposes all role labels via roleOptions when acting as super_admin', function () {
    // roleOptions() filters out super_admin for non-super_admin callers,
    // so we must act as a super_admin to see the full set.
    $this->actingAs(actAsSuperAdmin_userres());

    $options = UserResource::roleOptions();

    expect($options)->toMatchArray([
        'super_admin' => 'Administrator',
        'admin' => 'Administrator',
        'editor' => 'ReadingRoom',
        'viewer' => 'General',
    ]);
});
