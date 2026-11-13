<?php

declare(strict_types=1);

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use App\Policies\UserPolicy;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\EditRole;
use Database\Seeders\InitialDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Livewire\livewire;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Security audit 2026-11-13, leads 6a and 6b.
 *  6a: whoever holds update_role could rename the built-in roles in the
 *      Shield role editor (super_admin → anything, then admin → super_admin)
 *      and so become super_admin.
 *  6b: the seeder gave the editor (ReadingRoom) role every create_/update_/
 *      view_ permission, user and role management included, so an editor
 *      could create an admin account or reset an admin's password.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function sa_activeUserWithRole(string $role): User
{
    /** @var User $user */
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    return $user;
}

it('does not let admin rename the built-in roles', function (string $roleName, string $newName) {
    $this->actingAs(sa_activeUserWithRole('admin'));
    $role = Role::findByName($roleName, 'web');

    livewire(EditRole::class, ['record' => $role->getRouteKey()])
        ->fillForm(['name' => $newName])
        ->call('save')
        ->assertHasFormErrors(['name']);

    expect($role->fresh()->name)->toBe($roleName);
})->with([
    'super_admin away' => ['super_admin', 'old_super'],
    'admin to super_admin' => ['admin', 'super_admin'],
    'editor away' => ['editor', 'reading_room'],
]);

it('does not let admin delete a built-in role', function () {
    $this->actingAs(sa_activeUserWithRole('admin'));
    $role = Role::findByName('super_admin', 'web');

    expect(fn () => $role->delete())->toThrow(Exception::class);
    expect(Role::where('name', 'super_admin')->exists())->toBeTrue();
});

it('still lets admin rename and delete a role of their own making', function () {
    $this->actingAs(sa_activeUserWithRole('admin'));
    $role = Role::create(['name' => 'archivist', 'guard_name' => 'web']);

    $role->update(['name' => 'senior_archivist']);
    expect($role->fresh()->name)->toBe('senior_archivist');

    $role->delete();
    expect(Role::where('name', 'senior_archivist')->exists())->toBeFalse();
});

it('does not give the seeded editor role user or role management', function () {
    $names = collect(InitialDataSeeder::editorPermissions(bl_shieldPermissionNames()));

    expect($names->filter(fn (string $p): bool => str_ends_with($p, '_user') || str_ends_with($p, '_role'))->all())->toBe([])
        ->and($names)->toContain('update_document', 'create_box', 'view_any_document', 'resolve_document_flag');
});

it('takes user and role management away from an editor role that already has it', function () {
    // The state production was seeded with: editor holds create_/update_/view_ on users and roles.
    $editor = Role::findByName('editor', 'web');
    $editor->givePermissionTo(['create_user', 'update_user', 'view_any_user', 'create_role', 'update_role', 'view_any_role']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $migration = require database_path('migrations/2026_11_13_100000_revoke_user_and_role_management_from_editor.php');
    $migration->up();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = sa_activeUserWithRole('editor');
    $victim = sa_activeUserWithRole('admin');

    expect($user->can('create_user'))->toBeFalse()
        ->and($user->can('update_role'))->toBeFalse()
        ->and((new UserPolicy)->update($user, $victim))->toBeFalse()
        ->and($user->can('update_document'))->toBeTrue()
        ->and(Permission::where('name', 'create_user')->exists())->toBeTrue();
});

it('does not let an editor create an admin account or reset an admin password', function () {
    $editor = sa_activeUserWithRole('editor');
    $victim = sa_activeUserWithRole('admin');
    $oldHash = $victim->password;
    $this->actingAs($editor);

    $this->get('/admin/users/create')->assertForbidden();
    livewire(ListUsers::class)->assertForbidden();

    expect(User::where('email', 'mallory@example.test')->exists())->toBeFalse()
        ->and($victim->fresh()->password)->toBe($oldHash);
});

it('still lets admin create users', function () {
    $this->actingAs(sa_activeUserWithRole('admin'));
    $repo = qf_repo('A');

    livewire(CreateUser::class)->fillForm([
        'name' => 'New Colleague',
        'email' => 'colleague@example.test',
        'password' => 'Secret-pass-1234!',
        'password_confirmation' => 'Secret-pass-1234!',
        'role' => 'editor',
        'default_repository_id' => $repo->id,
        'is_active' => true,
    ])->call('create')->assertHasNoFormErrors();

    expect(User::where('email', 'colleague@example.test')->first()?->hasRole('editor'))->toBeTrue();
});
