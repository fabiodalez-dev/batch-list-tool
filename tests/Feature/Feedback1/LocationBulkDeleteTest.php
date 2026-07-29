<?php

declare(strict_types=1);

use App\Filament\Resources\LocationResource\Pages\ListLocations;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Charlene's feedback (2026-07-28): "For Locations I cannot bulk delete them."
 *
 * The Locations list now has a bulk "Delete selected" action that RESPECTS the
 * same guard as the single-row delete: a location that still has child locations
 * or is referenced by Boxes/Documents is skipped and reported; only the safely
 * deletable ones are soft-deleted. It never force-deletes and never breaks
 * referential integrity.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

function lbd_admin(int $repoId): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repoId]);
    $u->assignRole('super_admin');

    return $u;
}

function lbd_location(int $repoId, array $attrs = []): Location
{
    return Location::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'name' => 'Loc-' . substr(uniqid(), -6),
        'type' => 'room',
        'repository_id' => $repoId,
        'is_active' => true,
        'parent_id' => null,
    ], $attrs));
}

test('bulk delete removes deletable locations and skips those with children or references', function () {
    $repo = Repository::factory()->create(['code' => 'LBD']);
    $u = lbd_admin($repo->id);
    $this->actingAs($u);

    // (a) A plain, safely-deletable location.
    $deletable = lbd_location($repo->id, ['name' => 'Deletable']);

    // (b) A location that is a PARENT of another location → must be skipped.
    $parent = lbd_location($repo->id, ['name' => 'HasChild']);
    lbd_location($repo->id, ['name' => 'Child', 'parent_id' => $parent->id]);

    // (c) A location REFERENCED by a Box → must be skipped.
    $referenced = lbd_location($repo->id, ['name' => 'Referenced']);
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => '1', 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);
    Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create([
        'box_type' => 'RAS', 'box_number' => '1', 'batch_id' => $batch->id,
        'barcode' => 'BC-1', 'location_id' => $referenced->id,
    ]);

    // Sanity: the guard sees the blockers.
    expect($parent->hasChildren())->toBeTrue()
        ->and($referenced->isReferenced())->toBeTrue()
        ->and($deletable->hasChildren())->toBeFalse()
        ->and($deletable->isReferenced())->toBeFalse();

    Livewire::test(ListLocations::class)
        ->callTableBulkAction('deleteDeletable', [$deletable, $parent, $referenced]);

    // Only the deletable one is soft-deleted; the guarded ones survive.
    expect(Location::withoutGlobalScope(RepositoryScope::class)->find($deletable->id))->toBeNull()
        ->and(Location::withoutGlobalScope(RepositoryScope::class)->withTrashed()->find($deletable->id)->trashed())->toBeTrue()
        ->and(Location::withoutGlobalScope(RepositoryScope::class)->find($parent->id))->not->toBeNull()
        ->and(Location::withoutGlobalScope(RepositoryScope::class)->find($referenced->id))->not->toBeNull();
});

test('bulk delete removes all selected when every one is safely deletable', function () {
    $repo = Repository::factory()->create(['code' => 'LBD2']);
    $u = lbd_admin($repo->id);
    $this->actingAs($u);

    $a = lbd_location($repo->id);
    $b = lbd_location($repo->id);

    Livewire::test(ListLocations::class)
        ->callTableBulkAction('deleteDeletable', [$a, $b]);

    expect(Location::withoutGlobalScope(RepositoryScope::class)->whereIn('id', [$a->id, $b->id])->count())->toBe(0);
});

test('bulk delete skips a location the operator lacks permission to delete', function () {
    $repo = Repository::factory()->create(['code' => 'LBD3']);

    // A user who CAN reach the page and the bulk action (view_any / delete_any)
    // but does NOT hold the per-record `delete_location` ability — so the
    // per-item can('delete') guard skips the row instead of deleting it.
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id]);
    $u->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);
    $u->givePermissionTo(['view_any_location', 'delete_any_location']); // NOT delete_location
    $this->actingAs($u);

    $loc = lbd_location($repo->id);

    Livewire::test(ListLocations::class)
        ->callTableBulkAction('deleteDeletable', [$loc]);

    // Skipped for lack of permission — still present, not soft-deleted.
    expect(Location::withoutGlobalScope(RepositoryScope::class)->find($loc->id))->not->toBeNull();
});
