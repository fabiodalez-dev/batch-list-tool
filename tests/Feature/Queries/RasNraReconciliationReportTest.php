<?php

declare(strict_types=1);

use App\Filament\Pages\Reports\RasNraReconciliationReport;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Q3 (NAF Queries) — the reconciliation report lists RAS-originated documents
 * (any RAS-origin column set) and hides documents with no RAS provenance.
 */
uses(RefreshDatabase::class);

function reconAdmin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('super_admin');

    return $u;
}

it('renders the reconciliation report for a report-viewer', function (): void {
    $this->actingAs(reconAdmin());

    Livewire::test(RasNraReconciliationReport::class)->assertOk();
});

it('lists RAS-originated documents and hides documents without RAS provenance', function (): void {
    $this->actingAs(reconAdmin());

    $withRas = Document::factory()->create(['ras_batch_1' => '19', 'ras_box_1' => '98', 'barcode_in' => 'AA18049']);
    $withBarcodeRas = Document::factory()->create(['ras_batch_1' => null, 'ras_box_1' => null, 'barcode_ras_1' => 'RB-1']);
    $noRas = Document::factory()->create(['ras_batch_1' => null, 'ras_box_1' => null, 'barcode_ras_1' => null]);

    Livewire::test(RasNraReconciliationReport::class)
        ->assertCanSeeTableRecords([$withRas, $withBarcodeRas])
        ->assertCanNotSeeTableRecords([$noRas]);
});
