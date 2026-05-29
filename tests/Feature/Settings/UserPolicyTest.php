<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

function makeUserWithRole(string $role): User
{
    $u = User::factory()->create();
    $u->assignRole($role);

    return $u;
}

it('lets admin manage normal users but not super_admins, and forbids self-delete', function () {
    $admin = makeUserWithRole('admin');
    $sa = makeUserWithRole('super_admin');
    $target = makeUserWithRole('editor');

    expect($admin->can('create', User::class))->toBeTrue();
    expect($admin->can('update', $target))->toBeTrue();
    expect($admin->can('update', $sa))->toBeFalse();   // cannot touch a super_admin
    expect($admin->can('delete', $admin))->toBeFalse(); // no self-delete
    expect(makeUserWithRole('viewer')->can('viewAny', User::class))->toBeFalse();
    expect($sa->can('update', $sa))->toBeTrue();        // super_admin can edit super_admins
});
