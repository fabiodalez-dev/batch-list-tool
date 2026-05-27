<?php

declare(strict_types=1);

use App\Filament\Pages\Reports\FlagsByTypeReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function fbt_makeRolesAndUser(string $role): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create([
        'email' => 'fbt-' . $role . '+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole($role);

    return $u;
}

/* ─── 1) Page mounts for super_admin ───────────────────────────────── */

test('FlagsByTypeReport mounts 200 OK for super_admin', function () {
    $this->actingAs(fbt_makeRolesAndUser('super_admin'));

    Livewire::test(FlagsByTypeReport::class)->assertOk();
});

/* ─── 2) Editor with view_any_report can mount ─────────────────────── */

test('editor with view_any_report permission can mount FlagsByTypeReport', function () {
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    // Editor role already has `view_any_report` via bl_seedShieldPermissions()
    // (it runs in beforeEach and grants view_*/create_*/update_* to editor).
    $editor = User::factory()->create([
        'email' => 'fbt-editor+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $editor->assignRole('editor');

    expect($editor->can('view_any_report'))->toBeTrue();

    $this->actingAs($editor);
    expect(FlagsByTypeReport::canAccess())->toBeTrue();

    Livewire::test(FlagsByTypeReport::class)->assertOk();
});

/* ─── 3) Viewer without view_any_report cannot mount ───────────────── */

test('user without view_any_report cannot mount FlagsByTypeReport', function () {
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    // Strip the view_any_report permission from this dedicated role so the
    // gate denies access — viewer normally has it, so we use a custom role.
    $deniedRole = Role::firstOrCreate(['name' => 'no_reports_role', 'guard_name' => 'web']);
    $deniedRole->syncPermissions(
        Permission::query()->where('name', '!=', 'view_any_report')->pluck('name')->all()
    );

    $u = User::factory()->create([
        'email' => 'fbt-denied+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->syncRoles([$deniedRole]);

    $this->actingAs($u);

    expect($u->can('view_any_report'))->toBeFalse();
    expect(FlagsByTypeReport::canAccess())->toBeFalse();
});
