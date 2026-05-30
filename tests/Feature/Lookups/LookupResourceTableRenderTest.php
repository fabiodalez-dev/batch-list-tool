<?php

declare(strict_types=1);

use App\Filament\Resources\Lookups\BarcodeStatusResource\Pages\ListBarcodeStatuses;
use App\Filament\Resources\Lookups\BoxTypeResource\Pages\ListBoxTypes;
use App\Models\Lookup\BarcodeStatus;
use App\Models\Lookup\BoxType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * C8 — the six lookup resources previously imported the NON-EXISTENT class
 * `Filament\Tables\Actions\Action` (only `Filament\Actions\Action` exists in
 * Filament v5). The broken import only fatals when the table actually builds
 * its row actions, which the canAccess-only tests never did. Rendering the
 * List page exercises the `Action::make('toggle_active')` line end-to-end.
 */
beforeEach(function () {
    bl_seedShieldPermissions();
    $this->admin = bl_actor('admin');
});

it('renders the BarcodeStatus lookup list table (row actions build without a fatal)', function () {
    actingAs($this->admin);

    // assertOk() forces the full table — INCLUDING the row `Action::make()` line
    // that referenced the broken `Filament\Tables\Actions\Action` import — to be
    // built and rendered. A regression (re-introducing that import) fatals here.
    Livewire::test(ListBarcodeStatuses::class)
        ->assertOk()
        ->assertCanSeeTableRecords(BarcodeStatus::all());
});

it('renders the BoxType lookup list table (row actions build without a fatal)', function () {
    actingAs($this->admin);

    Livewire::test(ListBoxTypes::class)
        ->assertOk()
        ->assertCanSeeTableRecords(BoxType::all());
});
