<?php

declare(strict_types=1);

use App\Models\Box;
use App\Models\Document;
use App\Support\Reports\RasReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Q3 (NAF Queries) — the reconciliation key = RAS Batch + RAS Box + the latest
 * Barcode IN; a row is reconcilable only when all three are present.
 */
uses(RefreshDatabase::class);

it('reads the latest Barcode IN from the document, then the current box', function (): void {
    $own = Document::factory()->create(['barcode_in' => 'AA18049']);
    expect(RasReconciliation::latestBarcodeIn($own))->toBe('AA18049');

    $box = Box::factory()->create(['barcode' => 'AC39451', 'barcode_status' => 'IN']);
    $viaBox = Document::factory()->create(['barcode_in' => null, 'current_box_id' => $box->id]);
    expect(RasReconciliation::latestBarcodeIn($viaBox))->toBe('AC39451');

    $none = Document::factory()->create(['barcode_in' => null, 'current_box_id' => null]);
    expect(RasReconciliation::latestBarcodeIn($none))->toBeNull();
});

it('does not treat an OUT box barcode as a Barcode IN', function (): void {
    $box = Box::factory()->create(['barcode' => 'ZZ00001', 'barcode_status' => 'OUT']);
    $doc = Document::factory()->create(['barcode_in' => null, 'current_box_id' => $box->id]);

    expect(RasReconciliation::latestBarcodeIn($doc))->toBeNull();
});

it('builds the RAS reconciliation key', function (): void {
    $doc = Document::factory()->create([
        'ras_batch_1' => '19',
        'ras_box_1' => '98',
        'barcode_in' => 'AA18049',
    ]);

    expect(RasReconciliation::key($doc))->toBe([
        'batch' => '19',
        'box' => '98',
        'barcode_in' => 'AA18049',
    ]);
});

it('is reconcilable only when batch, box and barcode IN are all present', function (): void {
    $full = Document::factory()->create(['ras_batch_1' => '19', 'ras_box_1' => '98', 'barcode_in' => 'AA18049']);
    $missingBarcode = Document::factory()->create(['ras_batch_1' => '19', 'ras_box_1' => '98', 'barcode_in' => null, 'current_box_id' => null]);
    $missingBox = Document::factory()->create(['ras_batch_1' => '19', 'ras_box_1' => null, 'barcode_in' => 'AA18049']);

    expect(RasReconciliation::isReconcilable($full))->toBeTrue()
        ->and(RasReconciliation::isReconcilable($missingBarcode))->toBeFalse()
        ->and(RasReconciliation::isReconcilable($missingBox))->toBeFalse();
});
