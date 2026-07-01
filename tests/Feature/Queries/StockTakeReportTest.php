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
    $method->setAccessible(true);
    /** @var Location $row */
    $row = $method->invoke($page)->where('locations.id', $loc->id)->first();

    expect((int) $row->getAttribute('box_count'))->toBe(2)   // destroyed box excluded
        ->and((int) $row->getAttribute('item_count'))->toBe(3);
});

it('emits detailed box and document stock-take rows with the required identifiers and NRA location', function (): void {
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
    $doc = Document::factory()->create([
        'current_box_id' => $box->id,
        'location_id' => $loc->id,
        'catalogue_identifier' => 'CAT-001',
        'identifier' => 'TMP-001',
        'object_reference_number' => 'COR-001',
    ]);

    $boxRow = qf_stockEntry('box', $box->id);
    $docRow = qf_stockEntry('document', $doc->id);

    expect($boxRow)->not->toBeNull()
        ->and((string) $boxRow->getAttribute('latest_batch_no'))->toBe('27')
        ->and($boxRow->getAttribute('box_no'))->toBe('IS-54')
        ->and($boxRow->getAttribute('in_situ_box_no'))->toBe('IS-54')
        ->and($boxRow->getAttribute('nra_location'))->toBe($loc->name)
        ->and($docRow)->not->toBeNull()
        ->and($docRow->getAttribute('catalogue_identifier'))->toBe('CAT-001')
        ->and($docRow->getAttribute('temporary_identifier'))->toBe('TMP-001')
        ->and($docRow->getAttribute('conservation_object_reference_number'))->toBe('COR-001')
        ->and($docRow->getAttribute('nra_location'))->toBe($loc->name);
});
