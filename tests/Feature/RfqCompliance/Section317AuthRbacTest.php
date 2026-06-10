<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * RFQ §3.3 / §3.1.7 — Auth + RBAC (§3.3 roles) and §3.1.7 barcode management.
 *
 * RFQ §3.3 mentions Administrator / ReadingRoom / General. The codebase
 * uses the (richer) Filament Shield convention: super_admin / admin /
 * editor / viewer — which is a superset (super_admin and admin both
 * represent "Administrator", editor maps to "ReadingRoom",
 * viewer maps to "General").
 *
 * Original eight tests pin role existence, permission inheritance, and panel
 * access gate (§3.3). The additional tests below (§3.1.7) cover barcode
 * management validation rules per RFQ-3.1.7-B finding.
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

/* ─────────── §3.1.7 barcode management (RFQ-3.1.7-B) ───────────────────────── */

// Helpers for barcode management tests.
function s317_repo(): Repository
{
    return Repository::factory()->create(['code' => '317-' . substr(uniqid(), -4)]);
}

function s317_batch(int $repoId, int $n): Batch
{
    return Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => $n,
        'type' => 'MAIN_COLLECTION',
        'repository_id' => $repoId,
        'is_active' => true,
    ]);
}

function s317_ras_box(int $batchId, array $attrs = []): Box
{
    return Box::withoutGlobalScopes()->create(array_merge([
        'box_type' => 'RAS',
        'box_number' => 'RAS-' . substr(uniqid(), -6),
        'batch_id' => $batchId,
        'barcode' => 'BARC317-' . strtoupper(substr(uniqid(), -8)),
        'barcode_status' => 'IN',
        'is_legacy' => false,
    ], $attrs));
}

it('§ 3.1.7 barcode #1: PERM_OUT transition requires disinfestation_date (Box::canBePermOut)', function () {
    $repo = s317_repo();
    $batch = s317_batch($repo->id, 50);
    $loc = Location::withoutGlobalScopes()->create([
        'name' => 'Loc317A',
        'type' => 'room',
        'repository_id' => $repo->id,
        'is_active' => true,
    ]);
    $box = s317_ras_box($batch->id, ['location_id' => $loc->id]);

    // Without disinfestation_date → must throw.
    expect(fn () => $box->update(['barcode_status' => 'PERM_OUT']))
        ->toThrow(ValidationException::class);
});

it('§ 3.1.7 barcode #2: PERM_OUT transition with disinfestation_date AND location succeeds', function () {
    $repo = s317_repo();
    $batch = s317_batch($repo->id, 51);
    $loc = Location::withoutGlobalScopes()->create([
        'name' => 'Loc317B',
        'type' => 'room',
        'repository_id' => $repo->id,
        'is_active' => true,
    ]);
    $box = s317_ras_box($batch->id, [
        'disinfestation_date' => '2026-05-15',
        'location_id' => $loc->id,
    ]);

    $box->update(['barcode_status' => 'PERM_OUT']);
    $box->refresh();

    expect($box->barcode_status)->toBe('PERM_OUT');
});

it('§ 3.1.7 barcode #3: PERM_OUT transition without location throws (RFQ-3.1.7-A)', function () {
    $repo = s317_repo();
    $batch = s317_batch($repo->id, 52);
    $box = s317_ras_box($batch->id, [
        'disinfestation_date' => '2026-05-15',
        'location_id' => null,
    ]);

    expect(fn () => $box->update(['barcode_status' => 'PERM_OUT']))
        ->toThrow(ValidationException::class);
});

it('§ 3.1.7 barcode #4: barcode history written on barcode change (Box::captureBarcodeTransition)', function () {
    $repo = s317_repo();
    $batch = s317_batch($repo->id, 53);
    $box = s317_ras_box($batch->id);

    $originalBarcode = $box->barcode;

    // First make it PERM_OUT to archive the original barcode.
    $loc = Location::withoutGlobalScopes()->create([
        'name' => 'Loc317C',
        'type' => 'room',
        'repository_id' => $repo->id,
        'is_active' => true,
    ]);
    // Update via DB to skip model guards (we are testing the history, not the guard).
    DB::table('boxes')->where('id', $box->id)->update([
        'barcode_status' => 'PERM_OUT',
        'disinfestation_date' => '2026-05-15',
        'location_id' => $loc->id,
    ]);
    $box->refresh();

    // Now re-enter at IN with a new barcode (triggers barcode history write).
    $newBarcode = 'NEWBARC-' . strtoupper(substr(uniqid(), -8));
    $box->update(['barcode_status' => 'IN', 'barcode' => $newBarcode]);

    // The box_barcode_history table should have an entry for this box.
    $historyCount = DB::table('box_barcode_history')
        ->where('box_id', $box->id)
        ->count();

    expect($historyCount)->toBeGreaterThan(0);
});
