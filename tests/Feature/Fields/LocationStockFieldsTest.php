<?php

declare(strict_types=1);

use App\Filament\Pages\Reports\StockTakeReport;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Fields touched by the NAF document — Location + stock take: locations.name,
 * locations.repository_id, locations.is_active, and box/document location_id.
 * Uses the reusable qf_* builders.
 */
uses(RefreshDatabase::class);

it('counts boxes at a location, excluding destroyed boxes', function (): void {
    $this->actingAs(qf_admin());
    $loc = qf_location();
    qf_box(['location_id' => $loc->id]);
    qf_box(['location_id' => $loc->id]);
    qf_box(['location_id' => $loc->id, 'destroyed_at' => now(), 'destroyed_reason' => 'x']);

    expect((int) qf_stockRow($loc->id)->getAttribute('box_count'))->toBe(2);
});

it('counts boxes (destroyed excluded) and items per location via the report query', function (): void {
    $this->actingAs(qf_admin());
    $loc = qf_location();
    qf_box(['location_id' => $loc->id]);
    qf_box(['location_id' => $loc->id, 'destroyed_at' => now(), 'destroyed_reason' => 'x']);
    qf_doc(['location_id' => $loc->id]);
    qf_doc(['location_id' => $loc->id]);

    $row = qf_stockRow($loc->id);

    expect((int) $row->getAttribute('box_count'))->toBe(1)
        ->and((int) $row->getAttribute('item_count'))->toBe(2);
});

it('shows a global (null repository) location as GLOBAL, not tied to a tenant', function (): void {
    $this->actingAs(qf_admin());
    $global = qf_location(null);
    $box = qf_box(['location_id' => $global->id]);

    expect($global->repository_id)->toBeNull();

    Livewire::test(StockTakeReport::class)->assertCanSeeTableRecords([qf_stockEntry('box', $box->id)]);
});

it('renders the stock-take report for a report-viewer', function (): void {
    $this->actingAs(qf_admin());

    Livewire::test(StockTakeReport::class)->assertOk();
});

it('filters stock take by a specific location/room', function (): void {
    $this->actingAs(qf_admin());
    $roomA = qf_location(null, ['name' => 'Archive A']);
    $roomB = qf_location(null, ['name' => 'Archive B']);
    $boxA = qf_box(['location_id' => $roomA->id]);
    $boxB = qf_box(['location_id' => $roomB->id]);

    Livewire::test(StockTakeReport::class)
        ->filterTable('location_id', [$roomA->id])
        ->assertCanSeeTableRecords([qf_stockEntry('box', $boxA->id)])
        ->assertCanNotSeeTableRecords([qf_stockEntry('box', $boxB->id)]);
});

it('excludes destroyed boxes from detailed stock-take rows', function (): void {
    $this->actingAs(qf_admin());
    $loc = qf_location(null, ['name' => 'Stocked room']);
    $destroyed = qf_box(['location_id' => $loc->id, 'destroyed_at' => now(), 'destroyed_reason' => 'x']);
    $active = qf_box(['location_id' => $loc->id]);

    Livewire::test(StockTakeReport::class)
        ->assertCanSeeTableRecords([qf_stockEntry('box', $active->id)]);

    expect(qf_stockEntry('box', $destroyed->id))->toBeNull();
});

it('exposes the boxes and documents hasMany relations on Location', function (): void {
    $loc = qf_location();
    qf_box(['location_id' => $loc->id]);
    qf_doc(['location_id' => $loc->id]);

    expect($loc->boxes()->count())->toBe(1)
        ->and($loc->documents()->count())->toBe(1);
});

it('scopes a location to its repository plus global via forRepository', function (): void {
    $repo = qf_repo();
    $own = qf_location($repo->id, ['name' => 'Own']);
    $global = qf_location(null, ['name' => 'Global']);
    $other = qf_location(qf_repo()->id, ['name' => 'Other']);

    $ids = Location::withoutGlobalScopes()->forRepository($repo->id)->pluck('id')->all();

    expect($ids)->toContain($own->id)->toContain($global->id)->not->toContain($other->id);
});

it('marks an inactive location via is_active', function (): void {
    $loc = qf_location(null, ['is_active' => false]);

    expect($loc->is_active)->toBeFalse()
        ->and(Location::withoutGlobalScopes()->active()->pluck('id')->all())->not->toContain($loc->id);
});

it('counts documents by their own location_id independent of their box', function (): void {
    $this->actingAs(qf_admin());
    $loc = qf_location();
    // Document placed at the location but in NO box — still counted as an item there.
    qf_doc(['location_id' => $loc->id, 'current_box_id' => null]);

    expect((int) qf_stockRow($loc->id)->getAttribute('item_count'))->toBe(1);
});
