<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * RFQ §3.3 / §3.1.7 — Auth + RBAC.
 *
 * RFQ §3.3 mentions Administrator / ReadingRoom / General. The codebase
 * uses the (richer) Filament Shield convention: super_admin / admin /
 * editor / viewer — which is a superset (super_admin and admin both
 * represent "Administrator", editor maps to "ReadingRoom",
 * viewer maps to "General").
 *
 * Eight tests pinning role existence, permission inheritance, and panel
 * access gate.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

it('§ 3.1.7 #1: all four roles exist after seeding (super_admin, admin, editor, viewer)', function () {
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        expect(Role::where('name', $r)->where('guard_name', 'web')->exists())
            ->toBeTrue("Role {$r} should exist");
    }
});

it('§ 3.1.7 #2: super_admin holds every Shield permission', function () {
    $sa = Role::findByName('super_admin', 'web');
    $count = Permission::count();
    expect($sa->permissions->count())->toBe($count);
});

it('§ 3.1.7 #3: admin role holds every Shield permission (Administrator)', function () {
    $a = Role::findByName('admin', 'web');
    $count = Permission::count();
    expect($a->permissions->count())->toBe($count);
});

it('§ 3.1.7 #4: editor (ReadingRoom) holds view/create/update/reorder but NOT delete', function () {
    $e = Role::findByName('editor', 'web');
    expect($e->hasPermissionTo('view_any_document'))->toBeTrue()
        ->and($e->hasPermissionTo('create_document'))->toBeTrue()
        ->and($e->hasPermissionTo('update_document'))->toBeTrue()
        ->and($e->hasPermissionTo('reorder_document'))->toBeTrue()
        ->and($e->hasPermissionTo('delete_document'))->toBeFalse();
});

it('§ 3.1.7 #5: viewer (General) holds only view_* permissions', function () {
    $v = Role::findByName('viewer', 'web');
    expect($v->hasPermissionTo('view_any_document'))->toBeTrue()
        ->and($v->hasPermissionTo('create_document'))->toBeFalse()
        ->and($v->hasPermissionTo('update_document'))->toBeFalse()
        ->and($v->hasPermissionTo('delete_document'))->toBeFalse();
});

it('§ 3.1.7 #6: User::canAccessPanel() returns false for inactive users', function () {
    $u = User::factory()->create(['is_active' => false]);
    $u->assignRole('admin');
    $panel = Filament::getPanel('admin');
    expect($u->canAccessPanel($panel))->toBeFalse();
});

it('§ 3.1.7 #7: User::canAccessPanel() returns true for active admin', function () {
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('admin');
    $panel = Filament::getPanel('admin');
    expect($u->canAccessPanel($panel))->toBeTrue();
});

it('§ 3.1.7 #8: Impersonation — super_admin can impersonate, others cannot; super_admin cannot be impersonated', function () {
    $sa = User::factory()->create(['is_active' => true]);
    $sa->assignRole('super_admin');
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');

    expect($sa->canImpersonate())->toBeTrue()
        ->and($admin->canImpersonate())->toBeFalse()
        ->and($sa->canBeImpersonated())->toBeFalse()
        ->and($admin->canBeImpersonated())->toBeTrue();
});
