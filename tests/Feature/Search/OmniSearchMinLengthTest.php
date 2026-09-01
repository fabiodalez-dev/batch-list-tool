<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Schema audit (#6): the omni-search fans a leading-wildcard LIKE across ~38
 * columns + 8 EXISTS subqueries — a full scan over 26k rows. For a 1-character
 * term (which matches almost everything and is never a useful substring search)
 * it is restricted to a sargable prefix match on the indexed identifier.
 */
uses(RefreshDatabase::class);

function oms_seed(): void
{
    $repo = Repository::factory()->create(['code' => 'OMS_' . substr(uniqid(), -5)]);
    $series = Series::create(['code' => 'OMS_' . substr(uniqid(), -4), 'title' => 'S', 'is_active' => true]);

    Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'R100', 'document_type' => 'T', 'series_id' => $series->id,
        'repository_id' => $repo->id, 'notes' => 'nothing here',
    ]);
    Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'X200', 'document_type' => 'T', 'series_id' => $series->id,
        'repository_id' => $repo->id, 'notes' => 'contains an r in the notes',
    ]);
}

/** @return array<int,string> */
function oms_search(string $term): array
{
    $q = Document::query()->withoutGlobalScope(RepositoryScope::class);
    DocumentResource::applyOmniSearch($q, $term);

    return $q->pluck('identifier')->all();
}

it('a 1-character term matches only the identifier prefix, not every notes substring', function () {
    oms_seed();

    // 'R' → prefix on identifier: matches R100, NOT X200 (whose notes contain 'r').
    $result = oms_search('R');

    expect($result)->toContain('R100')
        ->not->toContain('X200');
});

it('a 2+ character term still searches the free-text columns (full fan-out)', function () {
    oms_seed();

    // 'contains' → full search hits X200 via its notes.
    expect(oms_search('contains'))->toContain('X200');
    // And identifier substring still works at >= 2 chars.
    expect(oms_search('100'))->toContain('R100');
});
