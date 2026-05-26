<?php

declare(strict_types=1);

use App\Models\Authority;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Reusable: Laravel Scout Searchable contract.
 *
 * Pin the shape of the toSearchableArray output for Document and Authority.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

it('Searchable: Document::toSearchableArray() exposes identifier, document_type and notes', function () {
    $repo = Repository::factory()->create(['code' => 'SCH-' . substr(uniqid(), -4)]);
    $series = Series::create([
        'code' => 'SCS-' . substr(uniqid(), -4),
        'title' => 'SCS title', 'is_active' => true,
    ]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'SCH-DOC-' . uniqid(),
        'document_type' => 'TEST',
        'series_id' => $series->id,
        'repository_id' => $repo->id,
        'notes' => 'searchable contents',
    ]);

    $arr = $doc->toSearchableArray();
    expect($arr)->toHaveKey('identifier')
        ->and($arr)->toHaveKey('document_type')
        ->and($arr)->toHaveKey('notes')
        ->and($arr['notes'])->toBe('searchable contents');
});

it('Searchable: Document::toSearchableArray() exposes series_code and series_title', function () {
    $repo = Repository::factory()->create(['code' => 'SCH2-' . substr(uniqid(), -4)]);
    $series = Series::create(['code' => 'SCT-' . substr(uniqid(), -4), 'title' => 'My Series Title', 'is_active' => true]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'SCH2-' . uniqid(),
        'document_type' => 'T',
        'series_id' => $series->id,
        'repository_id' => $repo->id,
    ]);
    $arr = $doc->toSearchableArray();
    expect($arr)->toHaveKey('series_code')
        ->and($arr)->toHaveKey('series_title')
        ->and($arr['series_title'])->toBe('My Series Title');
});

it('Searchable: Document::toSearchableArray() exposes flag_tokens key', function () {
    $repo = Repository::factory()->create(['code' => 'SCH3-' . substr(uniqid(), -4)]);
    $series = Series::create(['code' => 'SCT2-' . substr(uniqid(), -4), 'title' => 'F', 'is_active' => true]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'SCH3-' . uniqid(),
        'document_type' => 'T',
        'series_id' => $series->id,
        'repository_id' => $repo->id,
    ]);
    $arr = $doc->toSearchableArray();
    expect($arr)->toHaveKey('flag_tokens');
});

it('Searchable: Authority::toSearchableArray() exposes identifier and surname', function () {
    $a = Authority::create([
        'identifier' => 'SCH-A-' . uniqid(),
        'surname' => 'Bianchi',
        'given_names' => 'Giuseppe',
        'entity_type' => 'PERSON',
    ]);
    $arr = $a->toSearchableArray();
    expect($arr)->toHaveKey('identifier')
        ->and($arr)->toHaveKey('surname')
        ->and($arr['surname'])->toBe('Bianchi');
});
