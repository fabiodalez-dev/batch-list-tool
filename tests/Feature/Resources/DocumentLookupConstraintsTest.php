<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RFQ-2026-06 — APP2-viii / APP2-ix / APP2-xiii.
 *
 * Verifies the three locked-down Document lookups:
 *   - `digitised` accepts only {VHMML, NRA, none}
 *   - `current_box_type` accepts only {RAS Box, Big Brown Box, Small Brown Box}
 *   - `catalogue_identifier` is UNIQUE when non-null, but allows multiple NULLs
 *
 * The enum gate runs in the Document `saving` event so it fires on both
 * MySQL (where a CHECK constraint also fires at the DB layer) and SQLite
 * (where the test suite runs and the CHECK is skipped at migration time).
 */
uses(RefreshDatabase::class);

function lookupRepo(): Repository
{
    return Repository::factory()->create([
        'code' => 'LKP_' . substr(uniqid(), -6),
    ]);
}

function lookupSeries(): Series
{
    return Series::firstOrCreate(
        ['code' => 'LKP_' . substr(uniqid(), -4)],
        ['title' => 'Lookup series', 'is_active' => true],
    );
}

function lookupDoc(int $repoId, int $seriesId, array $attrs = []): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'LDOC-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'TEST',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
    ], $attrs));
}

/* 1. digitised = 'VHMML' saves OK */
test('Document accepts digitised = VHMML', function () {
    $repo = lookupRepo();
    $series = lookupSeries();

    $doc = lookupDoc($repo->id, $series->id, ['digitised' => 'VHMML']);

    expect($doc->fresh()->digitised)->toBe('VHMML');
});

/* 2. digitised = 'NRA' saves OK */
test('Document accepts digitised = NRA', function () {
    $repo = lookupRepo();
    $series = lookupSeries();

    $doc = lookupDoc($repo->id, $series->id, ['digitised' => 'NRA']);

    expect($doc->fresh()->digitised)->toBe('NRA');
});

/* 3. digitised = 'invalid' rejected on save */
test('Document rejects invalid digitised value with DomainException', function () {
    $repo = lookupRepo();
    $series = lookupSeries();

    expect(fn () => lookupDoc($repo->id, $series->id, ['digitised' => 'invalid']))
        ->toThrow(DomainException::class, "Invalid digitised value 'invalid'");
});

/* 4. current_box_type = 'Big Brown Box' saves OK */
test('Document accepts current_box_type = Big Brown Box', function () {
    $repo = lookupRepo();
    $series = lookupSeries();

    $doc = lookupDoc($repo->id, $series->id, ['current_box_type' => 'Big Brown Box']);

    expect($doc->fresh()->current_box_type)->toBe('Big Brown Box');
});

/* 5. current_box_type = 'My Custom Box' rejected on save */
test('Document rejects unknown current_box_type with DomainException', function () {
    $repo = lookupRepo();
    $series = lookupSeries();

    expect(fn () => lookupDoc($repo->id, $series->id, ['current_box_type' => 'My Custom Box']))
        ->toThrow(DomainException::class, "Invalid current_box_type 'My Custom Box'");
});

/* 6. catalogue_identifier is UNIQUE when non-null */
test('catalogue_identifier UNIQUE rejects duplicate non-null values', function () {
    $repo = lookupRepo();
    $series = lookupSeries();

    $token = 'CAT-' . strtoupper(substr(uniqid(), -8));
    lookupDoc($repo->id, $series->id, ['catalogue_identifier' => $token]);

    expect(fn () => lookupDoc($repo->id, $series->id, ['catalogue_identifier' => $token]))
        ->toThrow(QueryException::class);
});

/* 7. catalogue_identifier UNIQUE permits multiple NULLs (partial / NULL-aware) */
test('catalogue_identifier UNIQUE permits multiple NULL rows', function () {
    $repo = lookupRepo();
    $series = lookupSeries();

    $a = lookupDoc($repo->id, $series->id, ['catalogue_identifier' => null]);
    $b = lookupDoc($repo->id, $series->id, ['catalogue_identifier' => null]);
    $c = lookupDoc($repo->id, $series->id, ['catalogue_identifier' => null]);

    expect($a->fresh()->catalogue_identifier)->toBeNull();
    expect($b->fresh()->catalogue_identifier)->toBeNull();
    expect($c->fresh()->catalogue_identifier)->toBeNull();
    expect(Document::withoutGlobalScope(RepositoryScope::class)
        ->whereNull('catalogue_identifier')
        ->whereIn('id', [$a->id, $b->id, $c->id])
        ->count())->toBe(3);
});
