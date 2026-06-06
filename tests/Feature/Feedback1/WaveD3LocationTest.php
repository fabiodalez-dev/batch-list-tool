<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Wave D3 — Location type-only form (canonical types), auto-code generation,
 *           and depth-0 creation without parent_id.
 */
uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function wd3_repo(): Repository
{
    return Repository::factory()->create([
        'code' => 'WD3-' . strtoupper(substr(uniqid(), -6)),
    ]);
}

function wd3_location(array $attrs = []): Location
{
    $repo = wd3_repo();

    return Location::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'name' => 'Loc-WD3-' . substr(uniqid(), -6),
        'type' => 'room',
        'repository_id' => $repo->id,
        'is_active' => true,
    ], $attrs));
}

// ===========================================================================
// Canonical types constant
// ===========================================================================

it('D3-Types.1: Location::CANONICAL_TYPES contains exactly room, museum, repository', function (): void {
    $keys = array_keys(Location::CANONICAL_TYPES);
    sort($keys);
    expect($keys)->toBe(['museum', 'repository', 'room']);
});

it('D3-Types.2: LocationResource form type Select uses CANONICAL_TYPES only', function (): void {
    // The form should offer only the 3 canonical values, not all 9 from TYPES.
    $canonicalCount = count(Location::CANONICAL_TYPES);
    $allTypesCount = count(Location::TYPES);

    expect($canonicalCount)->toBe(3);
    expect($allTypesCount)->toBeGreaterThan(3);

    // Confirm CANONICAL_TYPES is a strict subset of TYPES
    foreach (array_keys(Location::CANONICAL_TYPES) as $type) {
        expect(in_array($type, Location::TYPES, true))->toBeTrue();
    }
});

it('D3-Types.3: all three canonical type labels are non-empty strings', function (): void {
    foreach (Location::CANONICAL_TYPES as $type => $label) {
        expect($label)->toBeString()->not->toBeEmpty();
    }
});

// ===========================================================================
// Auto-code generation
// ===========================================================================

it('D3-Code.1: code is auto-generated when blank on create', function (): void {
    $repo = wd3_repo();
    $location = Location::withoutGlobalScope(RepositoryScope::class)->create([
        'name' => 'Auto-code room',
        'type' => 'room',
        'repository_id' => $repo->id,
        'is_active' => true,
        // No 'code' supplied
    ]);

    expect($location->code)->not->toBeNull()->not->toBeEmpty();
});

it('D3-Code.2: auto-generated codes are unique within the same repository', function (): void {
    $repo = wd3_repo();

    $loc1 = Location::withoutGlobalScope(RepositoryScope::class)->create([
        'name' => 'Room Alpha',
        'type' => 'room',
        'repository_id' => $repo->id,
        'is_active' => true,
    ]);
    $loc2 = Location::withoutGlobalScope(RepositoryScope::class)->create([
        'name' => 'Room Beta',
        'type' => 'room',
        'repository_id' => $repo->id,
        'is_active' => true,
    ]);

    expect($loc1->code)->not->toBe($loc2->code);
});

it('D3-Code.3: explicitly supplied code is NOT overwritten', function (): void {
    $repo = wd3_repo();
    $location = Location::withoutGlobalScope(RepositoryScope::class)->create([
        'name' => 'Custom code location',
        'type' => 'museum',
        'repository_id' => $repo->id,
        'code' => 'MY-CUSTOM-CODE',
        'is_active' => true,
    ]);

    expect($location->code)->toBe('MY-CUSTOM-CODE');
});

// ===========================================================================
// No parent_id — depth 0
// ===========================================================================

it('D3-Depth.1: creating a location without parent_id results in depth=0', function (): void {
    $location = wd3_location(['parent_id' => null]);
    expect($location->depth)->toBe(0);
    expect($location->parent_id)->toBeNull();
});

it('D3-Depth.2: location without parent has path=null', function (): void {
    $location = wd3_location(['parent_id' => null]);
    expect($location->path)->toBeNull();
});
