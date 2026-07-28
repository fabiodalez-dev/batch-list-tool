<?php

declare(strict_types=1);

namespace App\Support\BulkImport;

use HayderHatem\FilamentExcelImport\Actions\Imports\Jobs\ImportExcel;

/**
 * Bug #4 — duplicate spreadsheet headers collapse to blank.
 *
 * The legacy NAf batch-list layout (both the shipped {@see TemplateGenerator}
 * template AND the client's own ~5MB working file) deliberately REPEATS header
 * strings at different physical columns to preserve multi-step provenance —
 * e.g. "Barcode (IN)" appears at col M and again at col V, "Status 1" at O and
 * X, "Disinfestation Date" three or four times in a row.
 *
 * Every row reader in the pipeline keys each row by its header STRING
 * (`$rowData[$header] = $value`) — the vendor's
 * {@see ImportExcel::readExcelRowsFromFile()}
 * does exactly this, and so does league/csv's header mode in the wizard. When
 * two physical columns share a header name the LAST one overwrites all earlier
 * ones, and in this layout the earlier column is the one that actually carries
 * data — so barcode_in / status_1 / disinfestation_date silently arrive blank.
 *
 * The fix is positional de-duplication: keep the FIRST occurrence's key
 * verbatim (so every existing importer guess / column-map entry still resolves
 * to the data-bearing column) and give each subsequent occurrence a distinct
 * "` (n)`" suffix so it no longer clobbers the first. The suffixed duplicate
 * columns are simply left unmapped (no importer guesses them) — their job in
 * the legacy sheet is provenance the operator reads by eye, not import data.
 *
 * This class is the single source of truth for that scheme; the streaming job
 * ({@see Jobs\DeduplicatingImportExcel}) and the wizard CSV reader both call it
 * so the two production paths de-duplicate identically.
 */
final class SpreadsheetHeaders
{
    /**
     * Reserved row key under which a reader injects the ABSOLUTE source-row
     * position of a row (used by importers that need a stable per-row key across
     * the 100-row chunking the import jobs do). Lives on this domain-neutral
     * reading utility so the generic {@see Jobs\DeduplicatingImportExcel} reader
     * does not have to depend on any one importer's domain class.
     */
    public const SOURCE_ROW_KEY = '__source_row';

    /**
     * Turn a positional list of raw header cell values into a positional list
     * of DISTINCT row keys.
     *
     * Rules (position-preserving — the returned array has the same length and
     * ordering as the input):
     *
     *   - The first occurrence of a non-empty header keeps its exact string,
     *     so `->guess([...])` / column-map lookups still match the first
     *     (data-bearing) physical column.
     *   - The 2nd, 3rd, … occurrence of that same string becomes
     *     `"{header} (2)"`, `"{header} (3)"`, … — distinct keys that survive
     *     row assembly instead of overwriting the first.
     *   - `null` headers mirror the vendor's `$headers[$i] ?? $i` fallback:
     *     they key by their integer position (already unique — no suffix).
     *   - Empty-string headers are left as `''` (they carry no data and no
     *     importer maps them; suffixing them would only manufacture odd keys).
     *
     * Idempotent in practice: a sheet whose headers are already distinct is
     * returned unchanged.
     *
     * @param array<int, string|int|float|bool|null> $headers raw header row, in physical column order
     * @return array<int, string|int> the de-duplicated key for each column position
     */
    public static function dedupe(array $headers): array
    {
        /** @var array<string, int> $seen occurrence count keyed by a stable signature */
        $seen = [];
        /** @var array<int, string|int> $keys */
        $keys = [];

        $position = 0;
        foreach ($headers as $raw) {
            // Mirror the vendor keying: a null header falls back to its numeric
            // column index (which is inherently unique), everything else is the
            // header cast to string.
            if ($raw === null) {
                $keys[$position] = $position;
                $position++;

                continue;
            }

            $name = is_string($raw) ? $raw : (string) $raw;

            if ($name === '') {
                // Empty headers carry no import data — leave them collapsing,
                // exactly as before, rather than inventing " (2)" keys.
                $keys[$position] = '';
                $position++;

                continue;
            }

            $count = ($seen[$name] ?? 0) + 1;
            $candidate = $count === 1 ? $name : $name . ' (' . $count . ')';

            // Guard the docblock's "DISTINCT keys" contract even against a sheet
            // that ALSO carries a literal header already equal to a generated
            // suffix (e.g. a real "Foo (2)" column alongside duplicated "Foo"):
            // keep bumping the counter until the key is genuinely unused, so a
            // suffixed duplicate can never silently clobber a literal column —
            // the exact Bug #4 failure mode this class exists to remove.
            while (in_array($candidate, $keys, true)) {
                $count++;
                $candidate = $name . ' (' . $count . ')';
            }

            $seen[$name] = $count;
            $keys[$position] = $candidate;
            $position++;
        }

        return $keys;
    }
}
