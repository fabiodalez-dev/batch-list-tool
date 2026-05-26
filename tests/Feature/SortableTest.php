<?php

use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Series;

it('assigns sort_order = MAX+1 within a batch when a Box is created', function () {
    $batch = Batch::factory()->create();
    $a = Box::factory()->create(['batch_id' => $batch->id]);
    $b = Box::factory()->create(['batch_id' => $batch->id]);
    $c = Box::factory()->create(['batch_id' => $batch->id]);

    expect([$a->sort_order, $b->sort_order, $c->sort_order])->toBe([1, 2, 3]);
});

it('scopes Box sort_order per batch (two batches both start at 1)', function () {
    $batchA = Batch::factory()->create();
    $batchB = Batch::factory()->create();

    $boxA1 = Box::factory()->create(['batch_id' => $batchA->id]);
    $boxA2 = Box::factory()->create(['batch_id' => $batchA->id]);
    $boxB1 = Box::factory()->create(['batch_id' => $batchB->id]);

    expect($boxA1->sort_order)->toBe(1)
        ->and($boxA2->sort_order)->toBe(2)
        ->and($boxB1->sort_order)->toBe(1);
});

it('reorders Boxes via setNewOrder() and persists the new sequence', function () {
    $batch = Batch::factory()->create();
    [$a, $b, $c] = Box::factory()->count(3)->create(['batch_id' => $batch->id]);

    Box::setNewOrder([$c->id, $a->id, $b->id]);

    expect($c->refresh()->sort_order)->toBe(1)
        ->and($a->refresh()->sort_order)->toBe(2)
        ->and($b->refresh()->sort_order)->toBe(3);
});

it('scopes Document sort_order per current_box', function () {
    $box1 = Box::factory()->create();
    $box2 = Box::factory()->create();

    $d1 = Document::factory()->create(['current_box_id' => $box1->id]);
    $d2 = Document::factory()->create(['current_box_id' => $box1->id]);
    $d3 = Document::factory()->create(['current_box_id' => $box2->id]);

    expect($d1->sort_order)->toBe(1)
        ->and($d2->sort_order)->toBe(2)
        ->and($d3->sort_order)->toBe(1);
});

it('assigns Series sort_order globally', function () {
    $a = Series::factory()->create();
    $b = Series::factory()->create();

    expect($a->sort_order)->toBe(1)
        ->and($b->sort_order)->toBe(2);
});
