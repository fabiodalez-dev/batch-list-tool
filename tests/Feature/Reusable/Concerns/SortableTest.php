<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Reusable: spatie/eloquent-sortable contract.
 *
 * Pin the per-group ordering rules: Boxes are sorted within their batch,
 * Documents within their current_box, Series globally.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

it('Sortable: Box::create() assigns sort_order = MAX+1 within batch', function () {
    $batch = Batch::factory()->create();
    $a = Box::factory()->create(['batch_id' => $batch->id]);
    $b = Box::factory()->create(['batch_id' => $batch->id]);
    expect([$a->sort_order, $b->sort_order])->toBe([1, 2]);
});

it('Sortable: Box sort_order is scoped per batch (two batches both start at 1)', function () {
    $batchA = Batch::factory()->create();
    $batchB = Batch::factory()->create();
    $a1 = Box::factory()->create(['batch_id' => $batchA->id]);
    $b1 = Box::factory()->create(['batch_id' => $batchB->id]);
    expect($a1->sort_order)->toBe(1)->and($b1->sort_order)->toBe(1);
});

it('Sortable: Box::setNewOrder() persists the new sequence', function () {
    $batch = Batch::factory()->create();
    [$a, $b, $c] = Box::factory()->count(3)->create(['batch_id' => $batch->id]);
    Box::setNewOrder([$c->id, $a->id, $b->id]);
    expect($c->refresh()->sort_order)->toBe(1)
        ->and($a->refresh()->sort_order)->toBe(2)
        ->and($b->refresh()->sort_order)->toBe(3);
});

it('Sortable: Document sort_order is scoped per current_box', function () {
    $box1 = Box::factory()->create();
    $box2 = Box::factory()->create();
    $d1 = Document::factory()->create(['current_box_id' => $box1->id]);
    $d2 = Document::factory()->create(['current_box_id' => $box1->id]);
    $d3 = Document::factory()->create(['current_box_id' => $box2->id]);
    expect($d1->sort_order)->toBe(1)
        ->and($d2->sort_order)->toBe(2)
        ->and($d3->sort_order)->toBe(1);
});

it('Sortable: Series sort_order is global (no group scope)', function () {
    $a = Series::factory()->create();
    $b = Series::factory()->create();
    $c = Series::factory()->create();
    expect($a->sort_order)->toBe(1)
        ->and($b->sort_order)->toBe(2)
        ->and($c->sort_order)->toBe(3);
});

it('Sortable: Box buildSortQuery() returns scoped query for current batch_id', function () {
    $batchA = Batch::factory()->create();
    $batchB = Batch::factory()->create();
    Box::factory()->count(2)->create(['batch_id' => $batchA->id]);
    Box::factory()->count(1)->create(['batch_id' => $batchB->id]);

    $boxInA = Box::withoutGlobalScopes()->where('batch_id', $batchA->id)->first();
    $count = $boxInA->buildSortQuery()->count();
    expect($count)->toBe(2);
});
