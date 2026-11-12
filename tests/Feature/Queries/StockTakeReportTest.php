<?php

declare(strict_types=1);

use App\Filament\Pages\Reports\StockTakeReport;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Location;
use App\Models\Scopes\RepositoryScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Q4 (NAF Queries) — stock take detail plus per-location summary counts.
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
    $method = new ReflectionMethod($page, 'summaryQuery');
    /** @var Location $row */
    $row = $method->invoke($page)->where('locations.id', $loc->id)->first();

    expect((int) $row->getAttribute('box_count'))->toBe(2)   // destroyed box excluded
        ->and((int) $row->getAttribute('item_count'))->toBe(3);
});

it('emits box rows, and document rows only for documents kept outside their box on request', function (): void {
    $this->actingAs(stockAdmin());

    $loc = stockLocation();
    $batch = Batch::factory()->create(['batch_number' => 27]);
    $box = Box::factory()->create([
        'batch_id' => $batch->id,
        'box_type' => 'IN_SITU',
        'box_number' => 'IS-54',
        'location_id' => $loc->id,
        'provenance_unknown' => true,
    ]);
    $inBox = Document::factory()->create(['current_box_id' => $box->id, 'catalogue_identifier' => 'CAT-001']);
    $outside = Document::factory()->create(['current_box_id' => $box->id, 'custody_status' => 'not_in_box', 'location_id' => $loc->id, 'catalogue_identifier' => 'CAT-002']);

    $boxRow = qf_stockEntry('box', $box->id);

    expect($boxRow)->not->toBeNull()
        ->and((string) $boxRow->getAttribute('batch_number'))->toBe('27')
        ->and($boxRow->getAttribute('box_number'))->toBe('IS-54')
        ->and($boxRow->getAttribute('location_name'))->toBe($loc->name)
        ->and(qf_stockEntry('document', $outside->id))->toBeNull(); // not asked for

    $page = new StockTakeReport;
    $page->tableFilters = ['include_out_of_box' => ['isActive' => true]];
    $rows = (new ReflectionMethod($page, 'reportQuery'))->invoke($page)->where('stock_type', 'document')->get();

    expect($rows->pluck('source_id')->all())->toBe([$outside->id])
        ->and($rows->first()->getAttribute('document_ref'))->toBe('CAT-002')
        ->and($rows->first()->getAttribute('location_name'))->toBe($loc->name)
        ->and($rows->pluck('source_id')->all())->not->toContain($inBox->id);
});
