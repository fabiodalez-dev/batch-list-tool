<?php

declare(strict_types=1);

use App\Filament\Pages\Reports\DisinfestationCycleReport;
use App\Models\Box;
use App\Models\Document;
use App\Support\Reports\DisinfestationCapacity;
use App\Support\Reports\DisinfestationCycle as Cycle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Fields touched by the NAF document — Box disinfestation / cycle / capacity:
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

it('classifies disinfestation_date into the 40/80-day cycle statuses', function (): void {
    $now = CarbonImmutable::create(2026, 7, 1);

    expect(Cycle::status(null, $now))->toBe(Cycle::NEVER)
        ->and(Cycle::status($now->subDays(39), $now))->toBe(Cycle::CURRENT)
        ->and(Cycle::status($now->subDays(40), $now))->toBe(Cycle::DUE)
        ->and(Cycle::status($now->subDays(79), $now))->toBe(Cycle::DUE)
        ->and(Cycle::status($now->subDays(80), $now))->toBe(Cycle::OVERDUE);
});

it('computes the next disinfestation due date at last + 40 days', function (): void {
    expect(Cycle::dueDate(CarbonImmutable::create(2026, 1, 1))->toDateString())->toBe('2026-02-10')
        ->and(Cycle::dueDate(null))->toBeNull();
});

it('lists a never-disinfested box in the cycle report regardless of age', function (): void {
    $this->actingAs(qf_admin());
    $never = qf_box(['disinfestation_date' => null]);

    Livewire::test(DisinfestationCycleReport::class)->assertCanSeeTableRecords([$never]);
});

it('excludes a recently-disinfested box and a destroyed box from the cycle report', function (): void {
    $this->actingAs(qf_admin());
    $current = qf_box(['disinfestation_date' => now()->subDays(5)]);
    $destroyed = qf_box(['disinfestation_date' => null, 'destroyed_at' => now(), 'destroyed_reason' => 'test']);
    $due = qf_box(['disinfestation_date' => now()->subDays(60)]);

    Livewire::test(DisinfestationCycleReport::class)
        ->assertCanSeeTableRecords([$due])
        ->assertCanNotSeeTableRecords([$current, $destroyed]);
});

it('filters cycle boxes by box_type', function (): void {
    $this->actingAs(qf_admin());
    $ras = qf_box(['box_type' => 'RAS', 'disinfestation_date' => null]);
    $insitu = Box::factory()->create([
        'box_type' => 'IN_SITU',
        'disinfestation_date' => null,
        'location_id' => qf_location()->id,
        'provenance_unknown' => true, // A1.3: allows a null parent
    ]);

    Livewire::test(DisinfestationCycleReport::class)
        ->filterTable('box_type', ['RAS'])
        ->assertCanSeeTableRecords([$ras])
        ->assertCanNotSeeTableRecords([$insitu]);
});
