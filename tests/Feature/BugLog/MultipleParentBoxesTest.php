<?php

declare(strict_types=1);

use App\Models\Box;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Bug-log #36 — a box may have MORE THAN ONE parent box (documents from several
 * origin boxes combined after cataloguing). The additive box_parents pivot must
 * let a box reference several parents, on top of the single parent_box_id, and
 * the single-parent provenance guard (RFQ A1.3) must stay intact.
 */
uses(RefreshDatabase::class);

it('lets a box reference several additional parent boxes', function (): void {
    $child = Box::factory()->create();
    $parentA = Box::factory()->create(['box_type' => 'RAS']);
    $parentB = Box::factory()->create(['box_type' => 'RAS']);

    $child->parents()->syncWithoutDetaching([$parentA->id, $parentB->id]);

    expect($child->parents()->count())->toBe(2)
        ->and($child->parents()->pluck('boxes.id')->all())
        ->toContain($parentA->id)
        ->toContain($parentB->id);
});

it('keeps the single parent_box_id relation independent of the additional parents', function (): void {
    $primary = Box::factory()->create(['box_type' => 'RAS']);
    $extra = Box::factory()->create(['box_type' => 'RAS']);

    $child = Box::factory()->create(['parent_box_id' => $primary->id]);
    $child->parents()->attach($extra->id);

    expect($child->parent?->id)->toBe($primary->id)       // primary provenance unchanged
        ->and($child->parents()->count())->toBe(1)         // additive relation separate
        ->and($child->parents()->first()->id)->toBe($extra->id);
});

it('cascades the pivot rows when a box is hard-deleted', function (): void {
    $child = Box::factory()->create();
    $parent = Box::factory()->create(['box_type' => 'RAS']);
    $child->parents()->attach($parent->id);

    $child->forceDelete();

    expect(DB::table('box_parents')->where('box_id', $child->id)->count())->toBe(0);
});
