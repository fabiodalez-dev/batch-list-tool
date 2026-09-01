<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Schema audit (#16): force-deleting a Repository must NOT cascade-wipe its
 * location hierarchy. locations.repository_id is now ON DELETE SET NULL, so an
 * affected location simply becomes global (repository_id NULL) instead of being
 * destroyed — matching every other tenant FK's restrict/null policy.
 */
uses(RefreshDatabase::class);

it('sets a location repository_id to NULL when its repository is force-deleted (no cascade wipe)', function () {
    $repo = Repository::factory()->create(['code' => 'CAS_' . substr(uniqid(), -5)]);
    $loc = Location::withoutGlobalScope(RepositoryScope::class)->create([
        'code' => 'SHELF-1',
        'name' => 'Shelf 1',
        'type' => 'repository',
        'is_active' => true,
        'repository_id' => $repo->id,
    ]);

    // Hard-delete the repository (it has no documents/batches/accessions to
    // restrict it, so this is the exact edge the finding describes).
    $repo->forceDelete();

    $survivor = Location::withoutGlobalScope(RepositoryScope::class)->find($loc->id);

    expect($survivor)->not->toBeNull()
        ->and($survivor->repository_id)->toBeNull();
});
