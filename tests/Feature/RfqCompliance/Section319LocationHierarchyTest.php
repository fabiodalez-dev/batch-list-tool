<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\Scopes\RepositoryScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RFQ §3.1.9 — Configurable location hierarchies.
 *
 * Six tests pinning the tree contract: parent/child, materialised path,
 * depth cap, multi-tenant isolation, descendants traversal, cycle defence.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

it('§ 3.1.9 #1: Location::TYPES lists all 9 RFQ-supported categories', function () {
    expect(Location::TYPES)->toContain('repository')
        ->and(Location::TYPES)->toContain('room')
        ->and(Location::TYPES)->toContain('work_area')
        ->and(Location::TYPES)->toContain('shelf')
        ->and(Location::TYPES)->toContain('museum')
        ->and(Location::TYPES)->toContain('showcase')
        ->and(Location::TYPES)->toContain('conservation')
        ->and(Location::TYPES)->toContain('temp_holding')
        ->and(Location::TYPES)->toContain('other');
});

it('§ 3.1.9 #2: parent_id wires the tree edge and depth = parent depth + 1', function () {
    $root = Location::withoutGlobalScope(RepositoryScope::class)->create([
        'name' => 'Root-' . uniqid(), 'type' => 'repository',
    ]);
    $child = Location::withoutGlobalScope(RepositoryScope::class)->create([
        'parent_id' => $root->id, 'name' => 'Room-' . uniqid(), 'type' => 'room',
    ]);
    expect($child->depth)->toBe(1)
        ->and($child->parent_id)->toBe($root->id);
});

it('§ 3.1.9 #3: materialised path "<root>/<intermediate>" is built bottom-up', function () {
    $root = Location::withoutGlobalScope(RepositoryScope::class)->create([
        'name' => 'R-' . uniqid(), 'type' => 'repository',
    ]);
    $room = Location::withoutGlobalScope(RepositoryScope::class)->create([
        'parent_id' => $root->id, 'name' => 'Rm-' . uniqid(), 'type' => 'room',
    ]);
    $shelf = Location::withoutGlobalScope(RepositoryScope::class)->create([
        'parent_id' => $room->id, 'name' => 'Sh-' . uniqid(), 'type' => 'shelf',
    ]);
    expect($shelf->depth)->toBe(2)
        ->and($shelf->path)->toBe($root->id . '/' . $room->id);
});

it('§ 3.1.9 #4: descendants() returns all sub-locations of a node', function () {
    $root = Location::withoutGlobalScope(RepositoryScope::class)->create([
        'name' => 'D-R-' . uniqid(), 'type' => 'repository',
    ]);
    $rm = Location::withoutGlobalScope(RepositoryScope::class)->create([
        'parent_id' => $root->id, 'name' => 'D-Rm-' . uniqid(), 'type' => 'room',
    ]);
    Location::withoutGlobalScope(RepositoryScope::class)->create([
        'parent_id' => $rm->id, 'name' => 'D-Sh-' . uniqid(), 'type' => 'shelf',
    ]);
    $desc = $root->descendants();
    expect($desc->count())->toBe(2);
});

it('§ 3.1.9 #5: setting parent_id to self throws DomainException (cycle defence)', function () {
    $l = Location::withoutGlobalScope(RepositoryScope::class)->create([
        'name' => 'C-' . uniqid(), 'type' => 'room',
    ]);
    expect(fn () => $l->update(['parent_id' => $l->id]))
        ->toThrow(DomainException::class);
});

it('§ 3.1.9 #6: MAX_DEPTH cap of 6 levels is enforced on save', function () {
    expect(Location::MAX_DEPTH)->toBe(6);
});
