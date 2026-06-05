<?php

declare(strict_types=1);

use App\Filament\Resources\Lookups\BarcodeStatusResource;
use App\Filament\Resources\Lookups\BarcodeStatusResource\Pages\ListBarcodeStatuses;
use App\Filament\Resources\Lookups\BatchTypeResource;
use App\Filament\Resources\Lookups\BatchTypeResource\Pages\ListBatchTypes;
use App\Filament\Resources\Lookups\BoxTypeResource;
use App\Filament\Resources\Lookups\BoxTypeResource\Pages\ListBoxTypes;
use App\Filament\Resources\Lookups\CurrentBoxTypeResource;
use App\Filament\Resources\Lookups\CurrentBoxTypeResource\Pages\ListCurrentBoxTypes;
use App\Filament\Resources\SeriesResource;
use App\Filament\Resources\SeriesResource\Pages\ListSeries;
use App\Models\Lookup\BarcodeStatus;
use App\Models\Lookup\BatchType;
use App\Models\Lookup\BoxType;
use App\Models\Lookup\CurrentBoxType;
use App\Models\User;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * A3 / Decision 6 & 9 (Wave A Lookups) — label changes and sorting.
 *
 * Covers:
 *  1. "Code" column is labelled "Identifier" on BarcodeStatuses, BoxTypes,
 *     CurrentBoxTypes, BatchTypes, and Series.
 *  2. BatchTypeResource navigation label is "Accession Types".
 *  3. Per-column sorting is enabled on the `code` column of lookup tables.
 *  4. List pages mount without errors for an admin user (smoke tests).
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

// ---------------------------------------------------------------------------
// Helper — admin user that can access all lookup resources.
// ---------------------------------------------------------------------------

function lk_admin(): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('admin');

    return $user;
}

// ===========================================================================
// 1. "Identifier" label on BarcodeStatusResource
// ===========================================================================

it('BarcodeStatusResource: code column label is "Identifier"', function () {
    $this->actingAs(lk_admin());

    /** @var Table $table */
    $table = BarcodeStatusResource::table(
        Table::make(Livewire::test(ListBarcodeStatuses::class)->instance())
    );

    $col = $table->getColumn('code');

    expect($col)->not->toBeNull()
        ->and($col->getLabel())->toBe('Identifier');
});

it('BarcodeStatusResource: code column is sortable', function () {
    $this->actingAs(lk_admin());

    /** @var Table $table */
    $table = BarcodeStatusResource::table(
        Table::make(Livewire::test(ListBarcodeStatuses::class)->instance())
    );

    $col = $table->getColumn('code');

    expect($col)->not->toBeNull()
        ->and($col->isSortable())->toBeTrue();
});

// ===========================================================================
// 2. "Identifier" label on BoxTypeResource
// ===========================================================================

it('BoxTypeResource: code column label is "Identifier"', function () {
    $this->actingAs(lk_admin());

    /** @var Table $table */
    $table = BoxTypeResource::table(
        Table::make(Livewire::test(ListBoxTypes::class)->instance())
    );

    $col = $table->getColumn('code');

    expect($col)->not->toBeNull()
        ->and($col->getLabel())->toBe('Identifier');
});

// ===========================================================================
// 3. "Identifier" label on CurrentBoxTypeResource
// ===========================================================================

it('CurrentBoxTypeResource: code column label is "Identifier"', function () {
    $this->actingAs(lk_admin());

    /** @var Table $table */
    $table = CurrentBoxTypeResource::table(
        Table::make(Livewire::test(ListCurrentBoxTypes::class)->instance())
    );

    $col = $table->getColumn('code');

    expect($col)->not->toBeNull()
        ->and($col->getLabel())->toBe('Identifier');
});

// ===========================================================================
// 4. "Identifier" label on SeriesResource
// ===========================================================================

it('SeriesResource: code column label is "Identifier"', function () {
    $this->actingAs(lk_admin());

    /** @var Table $table */
    $table = SeriesResource::table(
        Table::make(Livewire::test(ListSeries::class)->instance())
    );

    $col = $table->getColumn('code');

    expect($col)->not->toBeNull()
        ->and($col->getLabel())->toBe('Identifier');
});

it('SeriesResource: code column is sortable', function () {
    $this->actingAs(lk_admin());

    /** @var Table $table */
    $table = SeriesResource::table(
        Table::make(Livewire::test(ListSeries::class)->instance())
    );

    $col = $table->getColumn('code');

    expect($col)->not->toBeNull()
        ->and($col->isSortable())->toBeTrue();
});

// ===========================================================================
// 5. BatchTypeResource — navigation label renamed to "Accession Types"
// ===========================================================================

it('BatchTypeResource navigation label is "Accession Types"', function () {
    expect(BatchTypeResource::getNavigationLabel())->toBe('Accession Types');
});

it('BatchTypeResource: code column label is "Identifier"', function () {
    $this->actingAs(lk_admin());

    /** @var Table $table */
    $table = BatchTypeResource::table(
        Table::make(Livewire::test(ListBatchTypes::class)->instance())
    );

    $col = $table->getColumn('code');

    expect($col)->not->toBeNull()
        ->and($col->getLabel())->toBe('Identifier');
});

// ===========================================================================
// 6. List pages mount for admin (smoke tests — no 403 or crash)
// ===========================================================================

it('ListBarcodeStatuses page mounts for admin', function () {
    $this->actingAs(lk_admin());

    // Use a test-specific code that will not collide with seeded data.
    BarcodeStatus::firstOrCreate(['code' => 'TEST_BS'], ['label' => 'Test Status', 'sort_order' => 99, 'is_active' => true]);

    Livewire::test(ListBarcodeStatuses::class)
        ->assertSuccessful();
});

it('ListBoxTypes page mounts for admin', function () {
    $this->actingAs(lk_admin());

    // Use a test-specific code that will not collide with seeded data.
    BoxType::firstOrCreate(['code' => 'TEST_BT'], ['label' => 'Test Box Type', 'sort_order' => 99, 'is_active' => true]);

    Livewire::test(ListBoxTypes::class)
        ->assertSuccessful();
});

it('ListCurrentBoxTypes page mounts for admin', function () {
    $this->actingAs(lk_admin());

    CurrentBoxType::create(['code' => 'SB', 'label' => 'Small Box', 'sort_order' => 1, 'is_active' => true]);

    Livewire::test(ListCurrentBoxTypes::class)
        ->assertSuccessful();
});

it('ListBatchTypes page mounts for admin', function () {
    $this->actingAs(lk_admin());

    BatchType::create(['code' => 'NA', 'label' => 'Notary Accession', 'sort_order' => 1, 'is_active' => true]);

    Livewire::test(ListBatchTypes::class)
        ->assertSuccessful();
});
