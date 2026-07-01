<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Bug-log #28 — the box-movement "To box" select can create the target box inline
 * as a RAS box. RAS is the only type that needs no parent and no
 * location/disinfestation preconditions, so the exact payload the inline form
 * submits must satisfy every Box model guard and persist as an IN box.
 */
uses(RefreshDatabase::class);

it('creates a RAS box inline with just batch, box number and barcode', function (): void {
    $batch = Batch::factory()->create([
        'batch_number' => 4242,
        'type' => 'NOTARY_ACCESSION',
        'is_active' => true,
    ]);

    // Mirrors BoxMovementResource::createOptionUsing().
    $box = Box::create([
        'batch_id' => $batch->id,
        'box_number' => 'INLINE-1',
        'barcode' => 'BC-INLINE-1',
        'box_type' => 'RAS',
        'barcode_status' => 'IN',
    ]);

    expect($box->exists)->toBeTrue()
        ->and($box->box_type)->toBe('RAS')
        ->and($box->barcode_status)->toBe('IN')
        ->and(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->whereKey($box->id)->exists())->toBeTrue();
});
