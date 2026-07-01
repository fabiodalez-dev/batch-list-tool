<?php

declare(strict_types=1);

use App\Filament\Pages\Reports\RasNraReconciliationReport;
use App\Support\Reports\RasReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Fields touched by the NAF document — Document RAS provenance / reconciliation:
 * documents.ras_batch_1, ras_box_1, barcode_ras_1, barcode_in, and the current
 * box's barcode_status. Uses the reusable qf_* builders.
 */
uses(RefreshDatabase::class);

it('reads the latest Barcode IN from documents.barcode_in first', function (): void {
    expect(RasReconciliation::latestBarcodeIn(qf_doc(['barcode_in' => 'AA18049'])))->toBe('AA18049');
});

it('uses Barcode IN #2 as the latest document barcode when present', function (): void {
    expect(RasReconciliation::latestBarcodeIn(qf_doc([
        'barcode_in' => 'AA18049',
        'barcode_in_2' => 'AA99999',
    ])))->toBe('AA99999');
});

it('falls back to the current box barcode when the box is IN', function (): void {
    $box = qf_box(['barcode' => 'AC39451', 'barcode_status' => 'IN']);
    $doc = qf_doc(['barcode_in' => null, 'current_box_id' => $box->id]);

    expect(RasReconciliation::latestBarcodeIn($doc))->toBe('AC39451');
});

it('does not treat an OUT or PERM_OUT box barcode as a Barcode IN', function (): void {
    $out = qf_box(['barcode' => 'ZZ1', 'barcode_status' => 'OUT']);
    $perm = qf_box(['barcode' => 'ZZ2', 'barcode_status' => 'PERM_OUT', 'disinfestation_date' => now(), 'location_id' => qf_location()->id]);

    expect(RasReconciliation::latestBarcodeIn(qf_doc(['barcode_in' => null, 'current_box_id' => $out->id])))->toBeNull()
        ->and(RasReconciliation::latestBarcodeIn(qf_doc(['barcode_in' => null, 'current_box_id' => $perm->id])))->toBeNull();
});

it('trims and treats a blank Barcode IN as null', function (): void {
    expect(RasReconciliation::latestBarcodeIn(qf_doc(['barcode_in' => '  '])))->toBeNull()
        ->and(RasReconciliation::latestBarcodeIn(qf_doc(['barcode_in' => '  AB1  '])))->toBe('AB1');
});

it('builds the RAS reconciliation key from the latest RAS pair and barcode IN', function (): void {
    $doc = qf_doc([
        'ras_batch_1' => '19',
        'ras_box_1' => '98',
        'ras_batch_2' => '20',
        'ras_box_2' => '99',
        'barcode_in' => 'AA18049',
        'barcode_in_2' => 'AA99999',
    ]);

    expect(RasReconciliation::key($doc))->toBe(['batch' => '20', 'box' => '99', 'barcode_in' => 'AA99999']);
});

it('is reconcilable only when batch, box and a barcode IN are all present', function (): void {
    expect(RasReconciliation::isReconcilable(qf_doc(['ras_batch_1' => '19', 'ras_box_1' => '98', 'barcode_in' => 'AA1'])))->toBeTrue()
        ->and(RasReconciliation::isReconcilable(qf_doc(['ras_batch_1' => '19', 'ras_box_1' => null, 'barcode_in' => 'AA1'])))->toBeFalse()
        ->and(RasReconciliation::isReconcilable(qf_doc(['ras_batch_1' => '19', 'ras_box_1' => '98', 'barcode_in' => null, 'current_box_id' => null])))->toBeFalse();
});

it('lists documents with any RAS-origin column and hides those without', function (): void {
    $this->actingAs(qf_admin());
    $withBatch = qf_doc(['ras_batch_1' => '19', 'ras_box_1' => '98', 'barcode_in' => 'AA1']);
    $withBarcodeRas = qf_doc(['barcode_ras_1' => 'RB-1']);
    $withSecondPair = qf_doc(['ras_batch_2' => '20', 'ras_box_2' => '99', 'barcode_in_2' => 'AA2']);
    $none = qf_doc(['ras_batch_1' => null, 'ras_box_1' => null, 'barcode_ras_1' => null]);

    Livewire::test(RasNraReconciliationReport::class)
        ->assertCanSeeTableRecords([$withBatch, $withBarcodeRas, $withSecondPair])
        ->assertCanNotSeeTableRecords([$none]);
});

it('partitions the reconcilable filter into full-key vs missing-key rows', function (): void {
    $this->actingAs(qf_admin());
    $full = qf_doc(['ras_batch_1' => '19', 'ras_box_1' => '98', 'barcode_in' => 'AA1']);
    $partial = qf_doc(['ras_batch_1' => '19', 'ras_box_1' => null, 'barcode_ras_1' => 'RB-1']);

    Livewire::test(RasNraReconciliationReport::class)
        ->filterTable('reconcilable', true)
        ->assertCanSeeTableRecords([$full])
        ->assertCanNotSeeTableRecords([$partial]);

    Livewire::test(RasNraReconciliationReport::class)
        ->filterTable('reconcilable', false)
        ->assertCanSeeTableRecords([$partial])
        ->assertCanNotSeeTableRecords([$full]);
});

it('renders the reconciliation report for a report-viewer', function (): void {
    $this->actingAs(qf_admin());

    Livewire::test(RasNraReconciliationReport::class)->assertOk();
});

it('keeps ras_box_1 as a searchable string (not a numeric FK)', function (): void {
    // The RAS columns are legacy free-text, not FKs — an alphanumeric value persists verbatim.
    $doc = qf_doc(['ras_batch_1' => '19A', 'ras_box_1' => 'B-98']);

    expect($doc->fresh()->ras_batch_1)->toBe('19A')
        ->and($doc->fresh()->ras_box_1)->toBe('B-98');
});
