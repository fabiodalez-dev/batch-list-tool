<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

/* ─── REQ-3.3 User roles ─────────────────────────────────────────── */
describe('REQ-3.3 Roles: super_admin / admin / editor / viewer', function () {
    test('four roles are seeded with the expected names', function () {
        foreach (['super_admin', 'admin', 'editor', 'viewer'] as $name) {
            expect(Role::where('name', $name)->exists())->toBeTrue();
        }
    });

    test('viewer has read-only permissions (view_* only)', function () {
        $viewer = Role::findByName('viewer', 'web');
        $perms = $viewer->permissions->pluck('name');
        expect($perms)->not->toBeEmpty();
        expect($perms->every(fn ($p) => str_starts_with($p, 'view_')))->toBeTrue();
    });

    test('editor can create + update but not delete', function () {
        $editor = Role::findByName('editor', 'web');
        $perms = $editor->permissions->pluck('name');
        expect($perms->contains('create_document'))->toBeTrue();
        expect($perms->contains('update_document'))->toBeTrue();
        expect($perms->contains('delete_document'))->toBeFalse();
    });

    test('super_admin has every Shield permission', function () {
        $superAdmin = Role::findByName('super_admin', 'web');
        $names = bl_shieldPermissionNames();
        $assigned = $superAdmin->permissions->pluck('name')->all();
        foreach ($names as $perm) {
            expect(in_array($perm, $assigned, true))->toBeTrue("super_admin missing: {$perm}");
        }
    });
})->group('rfq:3.3');
