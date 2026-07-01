<?php

declare(strict_types=1);

use App\Models\Box;
use App\Models\Document;
use App\Support\Reports\DisinfestationCapacity;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Q2 (NAF Queries) — a "Big Brown Box" counts as 2 boxes against the
 * disinfestation cycle limit; every other current_box_type counts as 1.
 * The weight is stored on the editable current_box_types lookup (counts_as).
 */
uses(RefreshDatabase::class);

beforeEach(fn () => DisinfestationCapacity::flushCache());

it('weights a Big Brown Box as 2 and every other container as 1', function (): void {
    expect(DisinfestationCapacity::weightFor('Big Brown Box'))->toBe(2)
        ->and(DisinfestationCapacity::weightFor('RAS Box'))->toBe(1)
        ->and(DisinfestationCapacity::weightFor('Small Brown Box'))->toBe(1)
        ->and(DisinfestationCapacity::weightFor(null))->toBe(1)
        ->and(DisinfestationCapacity::weightFor('Unknown label'))->toBe(1);
});

it('weights a box by the heaviest container type among its documents', function (): void {
    $bigBox = Box::factory()->create();
    Document::factory()->create([
        'identifier' => 'CAP-BIG-' . uniqid(),
        'current_box_id' => $bigBox->id,
        'current_box_type' => 'Big Brown Box',
    ]);

    $plainBox = Box::factory()->create();
    Document::factory()->create([
        'identifier' => 'CAP-RAS-' . uniqid(),
        'current_box_id' => $plainBox->id,
        'current_box_type' => 'RAS Box',
    ]);

    $emptyBox = Box::factory()->create();

    expect(DisinfestationCapacity::weightForBox($bigBox))->toBe(2)
        ->and(DisinfestationCapacity::weightForBox($plainBox))->toBe(1)
        ->and(DisinfestationCapacity::weightForBox($emptyBox))->toBe(1);
});

it('sums a weighted cycle total across a set of boxes', function (): void {
    $boxes = [];

    $big = Box::factory()->create();
    Document::factory()->create([
        'identifier' => 'CAP-SUM-BIG-' . uniqid(),
        'current_box_id' => $big->id,
        'current_box_type' => 'Big Brown Box',
    ]);
    $boxes[] = $big;

    $boxes[] = Box::factory()->create();
    $boxes[] = Box::factory()->create();

    // 2 (big) + 1 + 1 = 4 slots against the cycle limit.
    expect(DisinfestationCapacity::weightedBoxCount($boxes))->toBe(4);
});
