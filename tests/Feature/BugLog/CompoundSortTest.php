<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Bug-log #2 — clicking the Box column sorts by parent Batch number FIRST, then
 * Box number. This exercises the compound orderByLeftPowerJoins the BoxResource /
 * DocumentResource "Box" columns use.
 */
uses(RefreshDatabase::class);

function bug2_batch(int $number): Batch
{
    return Batch::factory()->create([
        'batch_number' => $number,
        'type' => 'NOTARY_ACCESSION',
        'is_active' => true,
    ]);
}

function bug2_box(int $batchId, string $boxNumber): Box
{
    return Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create([
        'box_type' => 'RAS',
        'box_number' => $boxNumber,
        'batch_id' => $batchId,
        'barcode' => 'BC' . substr(uniqid(), -8),
        'barcode_status' => 'IN',
    ]);
}

it('orders boxes by parent batch number first, then box number', function (): void {
    // Two batches; create boxes out of order so a naive box-only sort would differ.
    $batchHigh = bug2_batch(20);
    $batchLow = bug2_batch(10);

    $b20a = bug2_box($batchHigh->id, '1');
    $b10b = bug2_box($batchLow->id, '2');
    $b10a = bug2_box($batchLow->id, '1');

    $ordered = Box::query()
        ->withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->orderByLeftPowerJoins('batch.batch_number', 'asc')
        ->orderBy('box_number', 'asc')
        ->pluck('boxes.id')
        ->all();

    // Batch 10 (its boxes, by box_number) then Batch 20.
    expect($ordered)->toBe([$b10a->id, $b10b->id, $b20a->id]);
});
