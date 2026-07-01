<?php

declare(strict_types=1);

use App\Filament\Support\SearchableSelects;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

use Spatie\Permission\Models\Role;

/**
 * Regression for the /adamsreview finding on bug #33: the location autocomplete
 * dropped RepositoryScope entirely to surface GLOBAL locations, which also
 * leaked OTHER tenants' locations. The picker must now show the user's own
 * repository PLUS global locations — never another repository's.
 */
uses(RefreshDatabase::class);

function reviewLoc(?int $repoId, string $name): Location
{
    return Location::withoutGlobalScope(RepositoryScope::class)->create([
        'name' => $name,
        'type' => 'room',
        'repository_id' => $repoId,
        'is_active' => true,
    ]);
}

function reviewUser(string $role, ?int $repoId): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole($role);
    if ($repoId !== null) {
        $u->repositories()->attach($repoId);
        $u->update(['default_repository_id' => $repoId]);
    }

    return $u;
}

it('shows own-repository and global locations but NOT another repository to a scoped editor', function (): void {
    $repoA = Repository::factory()->create(['code' => 'RA-' . substr(uniqid(), -5)]);
    $repoB = Repository::factory()->create(['code' => 'RB-' . substr(uniqid(), -5)]);

    $own = reviewLoc($repoA->id, 'Own Room A');
    $global = reviewLoc(null, 'Global Conservation Lab');
    $other = reviewLoc($repoB->id, 'Foreign Room B');

    actingAs(reviewUser('editor', $repoA->id));

    $ids = array_keys(SearchableSelects::locationSearchResults(''));

    expect($ids)->toContain($own->id)
        ->and($ids)->toContain($global->id)
        ->and($ids)->not->toContain($other->id);
});

it('shows every repository to a super_admin (no membership restriction)', function (): void {
    $repoA = Repository::factory()->create(['code' => 'RA-' . substr(uniqid(), -5)]);
    $repoB = Repository::factory()->create(['code' => 'RB-' . substr(uniqid(), -5)]);

    $a = reviewLoc($repoA->id, 'Room A');
    $b = reviewLoc($repoB->id, 'Room B');
    $global = reviewLoc(null, 'Global Lab');

    actingAs(reviewUser('super_admin', $repoA->id));

    $ids = array_keys(SearchableSelects::locationSearchResults(''));

    expect($ids)->toContain($a->id)
        ->and($ids)->toContain($b->id)
        ->and($ids)->toContain($global->id);
});
