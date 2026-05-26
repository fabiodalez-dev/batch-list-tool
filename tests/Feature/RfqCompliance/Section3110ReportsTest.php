<?php

declare(strict_types=1);

use App\Models\Authority;
use App\Models\Batch;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RFQ §3.2 — Reporting (Documents by batch, by creator, by series, by
 * disinfestation date, etc.). Six tests pinning the query surface that
 * powers each report.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function s3110_seed(): array
{
    $repo = Repository::factory()->create(['code' => 'S3110-' . substr(uniqid(), -4)]);
    $series = Series::create(['code' => 'S3110S-' . substr(uniqid(), -4), 'title' => 'S3110', 'is_active' => true]);
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 6500 + random_int(0, 999),
        'type' => 'MAIN_COLLECTION', 'repository_id' => $repo->id, 'is_active' => true,
    ]);

    return [$repo, $series, $batch];
}

it('§ 3.1.10 #1: Documents by batch — query Document::where(batch_id) returns scoped set', function () {
    [$repo, $series, $batch] = s3110_seed();
    Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'R-' . uniqid(), 'document_type' => 'R',
        'series_id' => $series->id, 'repository_id' => $repo->id,
        'batch_id' => $batch->id,
    ]);
    Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'R-' . uniqid(), 'document_type' => 'R',
        'series_id' => $series->id, 'repository_id' => $repo->id,
        'batch_id' => $batch->id,
    ]);
    $count = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('batch_id', $batch->id)->count();
    expect($count)->toBe(2);
});

it('§ 3.1.10 #2: Documents by series — query Document::where(series_id) returns scoped set', function () {
    [$repo] = s3110_seed();
    $s1 = Series::create(['code' => 'S1-' . substr(uniqid(), -4), 'title' => 'S1', 'is_active' => true]);
    $s2 = Series::create(['code' => 'S2-' . substr(uniqid(), -4), 'title' => 'S2', 'is_active' => true]);
    foreach (range(1, 3) as $i) {
        Document::withoutGlobalScope(RepositoryScope::class)->create([
            'identifier' => 'X-' . uniqid(), 'document_type' => 'R',
            'series_id' => $s1->id, 'repository_id' => $repo->id,
        ]);
    }
    Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'Y-' . uniqid(), 'document_type' => 'R',
        'series_id' => $s2->id, 'repository_id' => $repo->id,
    ]);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('series_id', $s1->id)->count())->toBe(3);
});

it('§ 3.1.10 #3: Documents by creator — query authorities() pivot returns scoped set', function () {
    [$repo, $series] = s3110_seed();
    $authA = Authority::create([
        'identifier' => 'CA-' . uniqid(), 'surname' => 'Abela', 'entity_type' => 'PERSON',
    ]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'CDOC-' . uniqid(), 'document_type' => 'R',
        'series_id' => $series->id, 'repository_id' => $repo->id,
    ]);
    $doc->authorities()->attach($authA->id, ['is_primary' => true]);
    expect($doc->authorities()->count())->toBe(1);
});

it('§ 3.1.10 #4: Documents pending disinfestation — whereNull(disinfestation_date) filter', function () {
    [$repo, $series] = s3110_seed();
    Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'D1-' . uniqid(), 'document_type' => 'R',
        'series_id' => $series->id, 'repository_id' => $repo->id,
        'disinfestation_date' => '2026-04-01',
    ]);
    Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'D2-' . uniqid(), 'document_type' => 'R',
        'series_id' => $series->id, 'repository_id' => $repo->id,
        'disinfestation_date' => null,
    ]);
    $pending = Document::withoutGlobalScope(RepositoryScope::class)
        ->whereNull('disinfestation_date')->count();
    expect($pending)->toBe(1);
});

it('§ 3.1.10 #5: Documents by repository (multi-tenant) — count filtered per tenant', function () {
    $rA = Repository::factory()->create(['code' => 'R3110A-' . substr(uniqid(), -4)]);
    $rB = Repository::factory()->create(['code' => 'R3110B-' . substr(uniqid(), -4)]);
    $s = Series::create(['code' => 'RR-' . substr(uniqid(), -4), 'title' => 'RR', 'is_active' => true]);
    foreach (range(1, 3) as $i) {
        Document::withoutGlobalScope(RepositoryScope::class)->create([
            'identifier' => 'A-' . uniqid(), 'document_type' => 'R',
            'series_id' => $s->id, 'repository_id' => $rA->id,
        ]);
    }
    Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'B-' . uniqid(), 'document_type' => 'R',
        'series_id' => $s->id, 'repository_id' => $rB->id,
    ]);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('repository_id', $rA->id)->count())->toBe(3)
        ->and(Document::withoutGlobalScope(RepositoryScope::class)->where('repository_id', $rB->id)->count())->toBe(1);
});

it('§ 3.1.10 #6: Document::query()->orderBy(identifier) returns the same set in sorted order', function () {
    [$repo, $series] = s3110_seed();
    foreach (['Z-1', 'A-1', 'M-1'] as $ident) {
        Document::withoutGlobalScope(RepositoryScope::class)->create([
            'identifier' => $ident, 'document_type' => 'R',
            'series_id' => $series->id, 'repository_id' => $repo->id,
        ]);
    }
    $ordered = Document::withoutGlobalScope(RepositoryScope::class)
        ->whereIn('identifier', ['Z-1', 'A-1', 'M-1'])
        ->orderBy('identifier')->pluck('identifier')->all();
    expect($ordered)->toBe(['A-1', 'M-1', 'Z-1']);
});
