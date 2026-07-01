<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Models\Box;
use App\Models\Document;

/**
 * NAF Queries Q3 — RAS ↔ NRA reconciliation key.
 *
 * Client answer: "We need to extract the RAS Batch, RAS Box, Barcode IN — the
 * latest one. The combination of the Batch, Box and latest Barcode IN is what
 * we need for reconciliation."
 *
 * A document carries its RAS origin in the legacy columns `ras_batch_1`,
 * `ras_box_1` and its scanned-in barcode in `barcode_in` (falling back to the
 * current box's barcode while that box is IN). This helper reads that key
 * consistently so the reconciliation report and any future consumer agree, and
 * so the extraction logic is unit-testable without a report page.
 */
final class RasReconciliation
{
    /**
     * The latest "Barcode IN" for a document: its own `barcode_in`, else the
     * current box's barcode while that box is scanned IN.
     */
    public static function latestBarcodeIn(Document $document): ?string
    {
        $own = self::clean($document->barcode_in);
        if ($own !== null) {
            return $own;
        }

        $box = $document->relationLoaded('currentBox') ? $document->currentBox : $document->currentBox()->first();
        if ($box instanceof Box && $box->barcode_status === 'IN') {
            return self::clean($box->barcode);
        }

        return null;
    }

    /**
     * The reconciliation key: RAS Batch, RAS Box and the latest Barcode IN.
     *
     * @return array{batch: ?string, box: ?string, barcode_in: ?string}
     */
    public static function key(Document $document): array
    {
        return [
            'batch' => self::clean($document->ras_batch_1),
            'box' => self::clean($document->ras_box_1),
            'barcode_in' => self::latestBarcodeIn($document),
        ];
    }

    /**
     * A document is reconcilable only when all three parts of the key are
     * present; otherwise the row must be flagged for manual attention.
     */
    public static function isReconcilable(Document $document): bool
    {
        $key = self::key($document);

        return $key['batch'] !== null && $key['box'] !== null && $key['barcode_in'] !== null;
    }

    private static function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
