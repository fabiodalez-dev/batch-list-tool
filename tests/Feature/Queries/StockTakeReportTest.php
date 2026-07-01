<?php

declare(strict_types=1);

use App\Filament\Pages\Reports\StockTakeReport;
use App\Models\Box;
use App\Models\Document;
use App\Models\Location;
use App\Models\Scopes\RepositoryScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Q4 (NAF Queries) — stock take per location: box count (destroyed excluded) and
 * item (document) count, grouped by location.
 */
uses(RefreshDatabase::class);

function stockAdmin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('super_admin');

    return $u;
}

function stockLocation(): Location
{
    return Location::withoutGlobalScope(RepositoryScope::class)->create([
        'name' => 'Room ' . substr(uniqid(), -5),
        'type' => 'room',
        'repository_id' => null,
        'is_active' => true,
    ]);
}

it('renders the stock-take report for a report-viewer', function (): void {
    $this->actingAs(stockAdmin());

    Livewire::test(StockTakeReport::class)->assertOk();
});

it('counts boxes (destroyed excluded) and items per location', function (): void {
    $this->actingAs(stockAdmin());

    $loc = stockLocation();

    Box::factory()->create(['location_id' => $loc->id]);
    Box::factory()->create(['location_id' => $loc->id]);
    Box::factory()->create(['location_id' => $loc->id, 'destroyed_at' => now(), 'destroyed_reason' => 'test']);

    Document::factory()->count(3)->create(['location_id' => $loc->id]);

    $page = new StockTakeReport;
    $method = new ReflectionMethod($page, 'reportQuery');
    $method->setAccessible(true);
    /** @var Location $row */
    $row = $method->invoke($page)->where('locations.id', $loc->id)->first();

    expect((int) $row->getAttribute('box_count'))->toBe(2)   // destroyed box excluded
        ->and((int) $row->getAttribute('item_count'))->toBe(3);
});
