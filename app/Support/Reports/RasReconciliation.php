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
 * A document carries its RAS origin in paired legacy columns. The `*_2`
 * values represent the later scan when present, falling back to `*_1`, then
 * to the current IN box barcode only for the latest Barcode IN. This helper reads that key
 * consistently so the reconciliation report and any future consumer agree, and
 * so the extraction logic is unit-testable without a report page.
 */
final class RasReconciliation
{
    /**
     * The latest "Barcode IN" for a document: #2 beats #1, then the current
     * box's barcode while that box is scanned IN.
     */
    public static function latestBarcodeIn(Document $document): ?string
    {
        $own = self::clean($document->barcode_in_2) ?? self::clean($document->barcode_in);
        if ($own !== null) {
            return $own;
        }

        $box = $document->relationLoaded('currentBox') ? $document->currentBox : $document->currentBox()->first();
        if ($box instanceof Box && $box->barcode_status === 'IN') {
            return self::clean($box->barcode);
        }

        return null;
    }

    public static function latestRasBatch(Document $document): ?string
    {
        return self::clean($document->ras_batch_2) ?? self::clean($document->ras_batch_1);
    }

    public static function latestRasBox(Document $document): ?string
    {
        return self::clean($document->ras_box_2) ?? self::clean($document->ras_box_1);
    }

    /**
     * The reconciliation key: RAS Batch, RAS Box and the latest Barcode IN.
     *
     * @return array{batch: ?string, box: ?string, barcode_in: ?string}
     */
    public static function key(Document $document): array
    {
        return [
            'batch' => self::latestRasBatch($document),
            'box' => self::latestRasBox($document),
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
