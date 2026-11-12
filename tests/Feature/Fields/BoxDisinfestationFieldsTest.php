<?php

declare(strict_types=1);

use App\Models\Box;
use App\Models\Document;
use App\Support\Reports\DisinfestationCapacity;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Fields touched by the NAF document — Box disinfestation capacity:
 * boxes.disinfestation_date, boxes.destroyed_at, boxes.box_type, and the
 * documents.current_box_type → current_box_types.counts_as weighting.
 * Uses the reusable qf_* builders in tests/Pest.php.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => DisinfestationCapacity::flushCache());

it('weights current_box_type Big Brown Box as 2 and the rest as 1', function (): void {
    expect(DisinfestationCapacity::weightFor('Big Brown Box'))->toBe(2)
        ->and(DisinfestationCapacity::weightFor('RAS Box'))->toBe(1)
        ->and(DisinfestationCapacity::weightFor('Small Brown Box'))->toBe(1);
});

it('treats an unknown or null current_box_type as weight 1', function (): void {
    expect(DisinfestationCapacity::weightFor(null))->toBe(1)
        ->and(DisinfestationCapacity::weightFor(''))->toBe(1)
        ->and(DisinfestationCapacity::weightFor('Nonexistent'))->toBe(1);
});

it('weights a box by the heaviest current_box_type among its documents', function (): void {
    $box = qf_box();
    Document::factory()->create(['current_box_id' => $box->id, 'current_box_type' => 'RAS Box']);
    Document::factory()->create(['current_box_id' => $box->id, 'current_box_type' => 'Big Brown Box']);

    expect(DisinfestationCapacity::weightForBox($box))->toBe(2);
});

it('weights an empty box as 1', function (): void {
    expect(DisinfestationCapacity::weightForBox(qf_box()))->toBe(1);
});

it('sums a weighted cycle total across boxes (Big Brown counts twice)', function (): void {
    $big = qf_box();
    Document::factory()->create(['current_box_id' => $big->id, 'current_box_type' => 'Big Brown Box']);

    expect(DisinfestationCapacity::weightedBoxCount([$big, qf_box(), qf_box()]))->toBe(4);
});
