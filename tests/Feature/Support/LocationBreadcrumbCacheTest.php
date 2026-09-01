<?php

declare(strict_types=1);

use App\Models\Location;
use App\Models\Scopes\RepositoryScope;
use App\Support\LocationBreadcrumbCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Schema audit (#7/#8): the Documents/Boxes list Location columns memoise the
 * breadcrumb by location id so a page of rows sharing a location resolves the
 * ancestors once, not per row.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => LocationBreadcrumbCache::flush());

it('returns null for a null location', function () {
    expect(LocationBreadcrumbCache::for(null))->toBeNull();
});

it('returns the full-path breadcrumb of a location', function () {
    $loc = Location::withoutGlobalScope(RepositoryScope::class)->create([
        'code' => 'ROOT', 'name' => 'Root', 'type' => 'repository', 'is_active' => true,
    ]);

    expect(LocationBreadcrumbCache::for($loc))->toBe($loc->full_path);
});

it('memoises by location id — the second lookup issues no query', function () {
    $root = Location::withoutGlobalScope(RepositoryScope::class)->create([
        'code' => 'R', 'name' => 'Root', 'type' => 'repository', 'is_active' => true,
    ]);
    $child = Location::withoutGlobalScope(RepositoryScope::class)->create([
        'code' => 'C', 'name' => 'Child', 'type' => 'shelf', 'is_active' => true,
        'parent_id' => $root->id, 'path' => (string) $root->id,
    ]);

    // Prime the cache (this fires the ancestors() whereIn).
    $first = LocationBreadcrumbCache::for($child);

    // A second call for the SAME id must not touch the DB.
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    $second = LocationBreadcrumbCache::for($child);

    expect($second)->toBe($first)
        ->and($queries)->toBe(0);
});
