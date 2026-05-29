<?php

declare(strict_types=1);

use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\DocumentIdentifierHistory;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Reusable: factory smoke tests.
 *
 * One assertion per model that ::factory()->create() yields a persisted row
 * with a non-null id. These tests anchor the factory contract so future
 * feature work can rely on it without re-asserting.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

it('Factory: User::factory()->create() persists with id and email', function () {
    $u = User::factory()->create();
    expect($u->id)->not->toBeNull()->and($u->email)->not->toBeEmpty();
});

it('Factory: Repository::factory()->create() persists with code', function () {
    $r = Repository::factory()->create();
    expect($r->id)->not->toBeNull()->and($r->code)->not->toBeEmpty();
});

it('Factory: Series::factory()->create() persists with unique code', function () {
    $s = Series::factory()->create();
    expect($s->id)->not->toBeNull()->and($s->code)->not->toBeEmpty();
});

it('Factory: Batch::factory()->create() persists with non-forbidden number', function () {
    $b = Batch::withoutGlobalScope(RepositoryScope::class)
        ->where('id', Batch::factory()->create()->id)->first();
    expect($b->id)->not->toBeNull()
        ->and(in_array($b->batch_number, [34, 36], true))->toBeFalse();
});

it('Factory: Box::factory()->create() persists with default RAS type', function () {
    $box = Box::factory()->create();
    $found = Box::withoutGlobalScopes()->find($box->id);
    expect($found)->not->toBeNull()->and($found->box_type)->toBe('RAS');
});

it('Factory: Document::factory()->create() persists with identifier', function () {
    $d = Document::factory()->create();
    $found = Document::withoutGlobalScope(RepositoryScope::class)->find($d->id);
    expect($found)->not->toBeNull()->and($found->identifier)->not->toBeEmpty();
});

it('Factory: DocumentFlag::factory()->create() persists with open status', function () {
    $f = DocumentFlag::factory()->create();
    $found = DocumentFlag::withoutGlobalScope(RepositoryScope::class)->find($f->id);
    expect($found)->not->toBeNull()->and($found->status)->toBe('open');
});

it('Factory: DocumentIdentifierHistory::factory()->create() persists with previous_identifier', function () {
    $h = DocumentIdentifierHistory::factory()->create();
    expect($h->id)->not->toBeNull()->and($h->previous_identifier)->not->toBeEmpty();
});

it('Factory: Location::factory()->create() persists with default repository type', function () {
    $l = Location::factory()->create();
    $found = Location::withoutGlobalScope(RepositoryScope::class)->find($l->id);
    expect($found)->not->toBeNull()->and($found->type)->toBe('repository');
});

it('Factory: Location::factory()->ofType("shelf")->create() honours state', function () {
    $l = Location::factory()->ofType('shelf')->create();
    $found = Location::withoutGlobalScope(RepositoryScope::class)->find($l->id);
    expect($found->type)->toBe('shelf');
});

it('Factory: Location::factory()->inactive()->create() persists is_active=false', function () {
    $l = Location::factory()->inactive()->create();
    $found = Location::withoutGlobalScope(RepositoryScope::class)->find($l->id);
    expect($found->is_active)->toBeFalse();
});

it('Factory: DocumentFlag::factory()->resolved()->create() yields resolved state', function () {
    $f = DocumentFlag::factory()->resolved('test resolution')->create();
    $found = DocumentFlag::withoutGlobalScope(RepositoryScope::class)->find($f->id);
    expect($found->status)->toBe('resolved')
        ->and($found->resolution_notes)->toBe('test resolution');
});

it('Factory: DocumentFlag::factory()->critical()->create() yields critical severity', function () {
    $f = DocumentFlag::factory()->critical()->create();
    $found = DocumentFlag::withoutGlobalScope(RepositoryScope::class)->find($f->id);
    expect($found->severity)->toBe('critical');
});

it('Factory: Authority has no dedicated factory but model is creatable directly', function () {
    $a = Authority::create([
        'identifier' => 'R-FAC-' . substr(uniqid(), -6),
        'surname' => 'Smith',
        'entity_type' => 'PERSON',
    ]);
    expect($a->id)->not->toBeNull();
});
