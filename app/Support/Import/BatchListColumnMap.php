<?php

declare(strict_types=1);

namespace App\Support\Import;

/**
 * Single source of truth for mapping a NAF "Batch List" spreadsheet column to a
 * canonical import field — by **header name** (with aliases), never by position.
 *
 * Why this exists: the NAF batch list is a denormalised, document-level export
 * whose column SET and ORDER change between deliveries (the 04-06-26 sample
 * added Seal Number, four Disinfestation Date columns, Prev-Attributed vs Actual
 * Identifier/Volume, Part Number, Type, Citation Reference). A positional mapping
 * silently drifts; a header-name mapping does not. Both the bulk artisan importer
 * and the Filament Import Wizard (column `guess()`es) read their aliases from
 * here so the two paths can never diverge again.
 *
 * Aliases are matched case-insensitively and trimmed. The FIRST alias listed is
 * the canonical/preferred header. Duplicate physical columns (e.g. several
 * "Barcode (IN)" or four "Disinfestation Date") are resolved positionally within
 * their alias group by {@see resolveAll()}.
 */
final class BatchListColumnMap
{
    /**
     * field key => ordered list of header aliases (case-insensitive).
     *
     * @var array<string, array<int, string>>
     */
    public const FIELDS = [
        // ── Batch / Box (physical) ──────────────────────────────────────────
        'batch_number' => ['RAS Batch 1', 'Batch No', 'Batch Number', 'Batch'],
        'box_number' => ['RAS Box 1', 'Box No', 'Box Number', 'Box'],
        'batch_number_2' => ['RAS Batch 2'],
        'box_number_2' => ['RAS Box 2'],
        'in_situ_box_1' => ['In Situ Box 1'],
        'in_situ_box_2' => ['In Situ Box 2'],
        'in_situ_box_3' => ['In Situ Box 3'],
        'seal_number' => ['Seal Number'],
        'current_box_type' => ['Current Box'],          // value is a box TYPE ("RAS Box")

        // ── Barcodes ────────────────────────────────────────────────────────
        'barcode_in' => ['Barcode (IN)', 'Barcode IN'],

        // ── Disinfestation (the file carries up to 4 per row) ───────────────
        'disinfestation_date' => ['Disinfestation Date'],

        // ── Identifiers / volume ────────────────────────────────────────────
        'catalogue_identifier' => ['Catalogue Identifier', 'Catalouge Identifier'],
        'identifier' => ['Actual Identifier', 'Identifier'],
        'volume_number' => ['Actual Volume', 'Volume No', 'Volume Number', 'Volume'],
        'prev_identifier' => ['Prev Attributed Identifier'],
        'prev_volume' => ['Prev Attibuted Volume', 'Prev Attributed Volume'],
        'part_number' => ['Part Number', 'Part No', 'Part'],

        // ── Locations (legacy text) ─────────────────────────────────────────
        'nra_location' => ['NRA Location'],
        'museum_location' => ['Museum Location'],

        // ── Descriptive ─────────────────────────────────────────────────────
        'practice' => ['Practice'],
        'creator' => ['Creator', 'Notary'],             // authority NAME (free text)
        'dates' => ['Dates'],
        'deeds' => ['Deeds'],
        'document_type' => ['Document Type'],
        'series' => ['Series'],
        'accession_type' => ['Type'],                   // NAF "Type" == Accession Type
        'note' => ['Note', 'Notes', 'Remarks'],
        'digitised' => ['Digitised'],
        'torre' => ['Torre'],
        'accession' => ['Accession', 'Accession No', 'Accession Number'],
        'object_reference_number' => ['Object Reference Number'],
        'tracking' => ['Tracking'],
        'museum_reference' => ['Museum Reference'],
        'citation_reference' => ['Citation Reference'],
    ];

    /**
     * Fields that legitimately appear MORE THAN ONCE in a single sheet and must
     * be collected positionally (every matching column), not just the first.
     *
     * @var array<int, string>
     */
    public const MULTI = ['disinfestation_date', 'barcode_in'];

    /**
     * Resolve a header row to `field => column index` (first match wins).
     * Headers not recognised are ignored; fields with no column are absent.
     *
     * @param array<int, string|null> $headerRow
     * @return array<string, int>
     */
    public static function resolve(array $headerRow): array
    {
        $norm = [];
        foreach ($headerRow as $i => $h) {
            $norm[$i] = self::normalise($h);
        }

        $out = [];
        foreach (self::FIELDS as $field => $aliases) {
            foreach ($aliases as $alias) {
                $a = self::normalise($alias);
                $hit = array_search($a, $norm, true);
                if ($hit !== false) {
                    $out[$field] = (int) $hit;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Like {@see resolve()} but returns ALL matching indices per field (in column
     * order) — needed for the repeated columns in {@see MULTI}.
     *
     * @param array<int, string|null> $headerRow
     * @return array<string, array<int, int>>
     */
    public static function resolveAll(array $headerRow): array
    {
        $norm = [];
        foreach ($headerRow as $i => $h) {
            $norm[$i] = self::normalise($h);
        }

        $out = [];
        foreach (self::FIELDS as $field => $aliases) {
            $aliasSet = array_map([self::class, 'normalise'], $aliases);
            $cols = [];
            foreach ($norm as $i => $h) {
                if ($h !== '' && in_array($h, $aliasSet, true)) {
                    $cols[] = (int) $i;
                }
            }
            if ($cols !== []) {
                $out[$field] = $cols;
            }
        }

        return $out;
    }

    /**
     * Aliases for one field — consumed by the Filament importer's column
     * `guess()` lists so the Wizard and the bulk path share the same vocabulary.
     *
     * @return array<int, string>
     */
    public static function aliases(string $field): array
    {
        return self::FIELDS[$field] ?? [];
    }

    private static function normalise(?string $h): string
    {
        return mb_strtolower(trim((string) $h));
    }
}
