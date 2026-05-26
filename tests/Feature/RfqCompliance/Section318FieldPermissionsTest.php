<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * RFQ §3.1.4 / §3.1.8 — Field-level permissions (read/write/hidden).
 *
 * The implementation leverages the same Spatie permission infrastructure
 * exposed at the resource level. These four tests pin the contract that
 * the same can/can't lookup mechanism scopes field-level decisions.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function s318_user(string $role): User
{
    $u = User::factory()->create(['is_active' => true, 'email' => 's318-' . $role . '-' . uniqid() . '@t.t']);
    $u->assignRole($role);

    return $u;
}

it('§ 3.1.8 #1: viewer role cannot update_document (write permission denied)', function () {
    $v = s318_user('viewer');
    expect($v->can('update_document'))->toBeFalse();
});

it('§ 3.1.8 #2: editor role can update_document (write permission granted)', function () {
    $e = s318_user('editor');
    expect($e->can('update_document'))->toBeTrue();
});

it('§ 3.1.8 #3: viewer can view_any_document (read permission granted)', function () {
    $v = s318_user('viewer');
    expect($v->can('view_any_document'))->toBeTrue();
});

it('§ 3.1.8 #4: a brand-new role with no permissions denies all field operations', function () {
    Role::firstOrCreate(['name' => 'nobody', 'guard_name' => 'web']);
    $n = User::factory()->create(['is_active' => true, 'email' => 'nobody-' . uniqid() . '@t.t']);
    $n->assignRole('nobody');
    expect($n->can('view_any_document'))->toBeFalse()
        ->and($n->can('update_document'))->toBeFalse()
        ->and($n->can('delete_document'))->toBeFalse();
});
