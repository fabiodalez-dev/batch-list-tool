<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

it('blocks creating a new MAV box', function () {
    $r = Repository::factory()->create();
    $b = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 5,
        'repository_id' => $r->id,
        'type' => 'MAIN_COLLECTION',
    ]);
    Box::create(['box_type' => 'MAV', 'box_number' => 1, 'batch_id' => $b->id, 'is_legacy' => true]);
})->throws(ValidationException::class);

it('blocks creating a new STVC box', function () {
    $r = Repository::factory()->create();
    $b = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 6,
        'repository_id' => $r->id,
        'type' => 'MAIN_COLLECTION',
    ]);
    Box::create(['box_type' => 'STVC', 'box_number' => 1, 'batch_id' => $b->id, 'is_legacy' => true]);
})->throws(ValidationException::class);

it('allows a new non-legacy box', function () {
    $r = Repository::factory()->create();
    $b = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 7,
        'repository_id' => $r->id,
        'type' => 'MAIN_COLLECTION',
    ]);
    $box = Box::create(['box_type' => 'RAS', 'box_number' => 1, 'batch_id' => $b->id]);
    expect($box->exists)->toBeTrue();
});

it('still lets an EXISTING legacy box be updated', function () {
    $r = Repository::factory()->create();
    $b = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 8,
        'repository_id' => $r->id,
        'type' => 'MAIN_COLLECTION',
    ]);
    $box = Box::withoutEvents(fn () => Box::create([
        'box_type' => 'MAV',
        'box_number' => 2,
        'batch_id' => $b->id,
        'is_legacy' => true,
    ]));
    $box->update(['box_number' => 3]);
    expect((int) $box->fresh()->box_number)->toBe(3);
});
