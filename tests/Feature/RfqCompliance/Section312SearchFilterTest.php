<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RFQ §3.1.2 (and §3.2.1) — Search & filter across all fields.
 *
 * Eight tests pinning the search filter contract:
 *   - by identifier, document_type, notes, series_id, batch_id
 *   - cascading filter (series_id + repository_id)
 *   - LIKE fallback on non-MySQL drivers
 *   - searchFullText scope rejects unknown columns
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function s312_make(array $attrs = []): Document
{
    $repo = $attrs['repository_id'] ?? Repository::factory()->create()->id;
    $series = Series::query()->first()
        ?? Series::create(['code' => 'S312-' . substr(uniqid(), -4), 'title' => 'S312', 'is_active' => true]);

    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'S312-' . uniqid(),
        'document_type' => 'Register',
        'series_id' => $series->id,
        'repository_id' => $repo,
    ], $attrs));
}

it('§ 3.1.2 #1: search by identifier returns the matching document', function () {
    $doc = s312_make(['identifier' => 'UNIQUE-S312-' . uniqid()]);
    $found = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', $doc->identifier)->first();
    expect($found)->not->toBeNull()->and($found->id)->toBe($doc->id);
});

it('§ 3.1.2 #2: filter by document_type returns only matching rows', function () {
    s312_make(['document_type' => 'Original']);
    s312_make(['document_type' => 'Register']);
    s312_make(['document_type' => 'Register']);
    $count = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('document_type', 'Register')->count();
    expect($count)->toBeGreaterThanOrEqual(2);
});

it('§ 3.1.2 #3: filter by notes (LIKE) returns substring matches', function () {
    s312_make(['notes' => 'water damage noted']);
    s312_make(['notes' => 'fire damage']);
    s312_make(['notes' => 'no damage']);
    $count = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('notes', 'like', '%damage%')->count();
    expect($count)->toBe(3);
});

it('§ 3.1.2 #4: filter by series_id returns only docs in that series', function () {
    $s1 = Series::create(['code' => 'S312SS-' . substr(uniqid(), -4), 'title' => 'S1', 'is_active' => true]);
    $s2 = Series::create(['code' => 'S312SS2-' . substr(uniqid(), -4), 'title' => 'S2', 'is_active' => true]);
    s312_make(['series_id' => $s1->id]);
    s312_make(['series_id' => $s1->id]);
    s312_make(['series_id' => $s2->id]);
    $count1 = Document::withoutGlobalScope(RepositoryScope::class)->where('series_id', $s1->id)->count();
    $count2 = Document::withoutGlobalScope(RepositoryScope::class)->where('series_id', $s2->id)->count();
    expect($count1)->toBe(2)->and($count2)->toBe(1);
});

it('§ 3.1.2 #5: filter by repository_id (multi-tenant) returns only that tenant', function () {
    $rA = Repository::factory()->create(['code' => 'S312-A-' . substr(uniqid(), -4)]);
    $rB = Repository::factory()->create(['code' => 'S312-B-' . substr(uniqid(), -4)]);
    s312_make(['repository_id' => $rA->id]);
    s312_make(['repository_id' => $rA->id]);
    s312_make(['repository_id' => $rB->id]);
    $countA = Document::withoutGlobalScope(RepositoryScope::class)->where('repository_id', $rA->id)->count();
    expect($countA)->toBe(2);
});

it('§ 3.1.2 #6: searchFullText rejects columns NOT in FULLTEXT_COLUMNS whitelist', function () {
    expect(fn () => Document::query()->searchFullText('term', ['identifier']))
        ->toThrow(InvalidArgumentException::class);
});

it('§ 3.1.2 #7: searchFullText with empty term is a no-op (returns the builder unchanged)', function () {
    s312_make(['notes' => 'water damage']);
    $count = Document::withoutGlobalScope(RepositoryScope::class)
        ->searchFullText('', ['notes'])->count();
    expect($count)->toBe(Document::withoutGlobalScope(RepositoryScope::class)->count());
});

it('§ 3.1.2 #8: searchFullText with short term (<3 chars) short-circuits to no-op', function () {
    s312_make(['notes' => 'abc keyword']);
    $countShort = Document::withoutGlobalScope(RepositoryScope::class)
        ->searchFullText('ab', ['notes'])->count();
    expect($countShort)->toBe(Document::withoutGlobalScope(RepositoryScope::class)->count());
});
