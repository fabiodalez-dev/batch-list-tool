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
     *   - `null` headers (no data, mapped by nobody) get a guaranteed NON-numeric
     *     unique key (`__col_<pos>`). An integer position — the vendor's fallback —
     *     would collide with a numeric-string header like "1", since PHP stores
     *     the string key "1" under the int key 1.
     *   - Empty-string headers are left as `''` (they carry no data and no
     *     importer maps them; suffixing them would only manufacture odd keys).
     *
     * Idempotent in practice: a sheet whose headers are already distinct is
     * returned unchanged.
     *
     * @param array<int, string|int|float|bool|null> $headers raw header row, in physical column order
     * @return array<int, string> the de-duplicated key for each column position (always a
     *                            string, so no key can numerically collide with another)
     */
    public static function dedupe(array $headers): array
    {
        $list = array_values($headers);

        // Pass 1 — reserve every LITERAL (first-occurrence, non-empty) header up
        // front. A generated "name (n)" suffix for a DUPLICATE must then avoid
        // every literal, INCLUDING a real column literally called "name (n)" that
        // appears LATER: only generated suffixes are ever bumped, never literals.
        // $used is an associative set keyed by an effective-key signature — O(1)
        // lookups (no O(n^2) in_array) AND it accounts for PHP array-key
        // normalisation (see keySignature()).
        $used = [];
        $firstSeenAt = [];
        foreach ($list as $pos => $raw) {
            if ($raw === null) {
                continue;
            }
            $name = is_string($raw) ? $raw : (string) $raw;
            if ($name === '' || array_key_exists($name, $firstSeenAt)) {
                continue;
            }
            $firstSeenAt[$name] = $pos;
            $used[self::keySignature($name)] = true;
        }

        // Pass 2 — assign a distinct effective key to every physical column.
        $occurrence = [];
        /** @var array<int, string> $keys */
        $keys = [];
        foreach ($list as $pos => $raw) {
            if ($raw === null) {
                // A null header carries no import data and is mapped by nobody.
                // Give it a guaranteed NON-numeric, unique key: the vendor's
                // integer-index fallback would collide with a numeric-string
                // header ("1"), because PHP normalises the string key "1" to the
                // int key 1 — silently clobbering the data-bearing column.
                $key = '__col_' . $pos;
                while (isset($used[self::keySignature($key)])) {
                    $key .= '_';
                }
                $used[self::keySignature($key)] = true;
                $keys[$pos] = $key;

                continue;
            }

            $name = is_string($raw) ? $raw : (string) $raw;

            if ($name === '') {
                // Empty headers carry no data — leave them collapsing, as before.
                $keys[$pos] = '';

                continue;
            }

            if ($firstSeenAt[$name] === $pos) {
                // First occurrence keeps the exact header (already reserved) so
                // existing column-map guesses still resolve to the data column.
                $keys[$pos] = $name;

                continue;
            }

            // 2nd+ occurrence — generate "name (n)" avoiding every reserved
            // literal AND every key already generated.
            $n = ($occurrence[$name] ?? 1) + 1;
            do {
                $candidate = $name . ' (' . $n . ')';
                $n++;
            } while (isset($used[self::keySignature($candidate)]));
            $occurrence[$name] = $n - 1;
            $used[self::keySignature($candidate)] = true;
            $keys[$pos] = $candidate;
        }

        return $keys;
    }

    /**
     * Signature that mirrors PHP array-key normalisation: a canonical decimal
     * integer string ("1") is stored by PHP under the int key (1), so "1" and 1
     * must count as the SAME key when a row is assembled. Everything else keeps
     * its own string identity. The `(string) (int)` round-trip guards against
     * out-of-range integer strings (which PHP leaves as strings).
     */
    private static function keySignature(string $key): string
    {
        if ($key !== '' && preg_match('/^-?(0|[1-9]\d*)$/', $key) === 1 && (string) (int) $key === $key) {
            return 'i:' . (int) $key;
        }

        return 's:' . $key;
    }
}
