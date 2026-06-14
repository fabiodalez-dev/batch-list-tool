<?php

declare(strict_types=1);

namespace App\Support\BulkImport;

use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Rich free-text → year-range extractor.
 *
 * Parses the many date formats found in NRA spreadsheets and returns a
 * [year_start => ?int, year_end => ?int] pair. The raw text is NEVER
 * mutated here — this class only derives numeric years.
 *
 * Supported formats (case-insensitive, trims punctuation/whitespace):
 *   "1870"                         → 1870 / 1870
 *   "1745-1768" / "1745–1768"      → 1745 / 1768  (hyphen or en/em-dash)
 *   "1745 to 1768"                 → 1745 / 1768
 *   "Apr 1745 - Sep 1768"          → 1745 / 1768  (month names ignored)
 *   "April 1745 – September 1768"  → 1745 / 1768
 *   "c. 1700" / "circa 1700"       → 1700 / 1700
 *   "1700 ca" / "ca 1700"          → 1700 / 1700
 *   "1700s"                        → 1700 / 1709  (decade)
 *   "18th century" / "18th c."     → 1700 / 1799
 *   "17th c."                      → 1600 / 1699
 *   "sec. XVIII" / "XVIII"         → 1700 / 1799  (Roman-numeral century)
 *   "1745, 1750, 1768"             → 1745 / 1768  (multi-year: min / max)
 *   "1934-1936; 1938-1942; 1947"   → 1934 / 1947  (multi-range: min / max)
 *   "33239" / "44927.0"            → 1991 / 2023  (Excel serial date number)
 *   "n.d." / "s.d." / "undated"    → null / null
 *   ""  / non-date text            → null / null
 *   reversed "1768-1745"           → 1745 / 1768  (normalised so start ≤ end)
 *
 * Main strategy: after the special cases above, scan all 4-digit years
 * (1000–2099); if ≥ 2 found use min & max; if exactly 1 found use it for both
 * start and end; if none, return null / null.
 */
final class DateRangeNormalizer
{
    /**
     * @return array{year_start: int|null, year_end: int|null}
     */
    public static function extractYearRange(string $raw): array
    {
        $none = ['year_start' => null, 'year_end' => null];

        $s = trim($raw);
        if ($s === '') {
            return $none;
        }

        // ── 1. Explicit "undated" sentinels ────────────────────────────────
        if (preg_match('/^\s*(?:n\.?\s*d\.?|s\.?\s*d\.?|undated|sine\s+die)\s*$/iu', $s)) {
            return $none;
        }

        // ── 1b. Excel serial date ─────────────────────────────────────────
        // A date-formatted cell read with setReadDataOnly arrives as a bare
        // serial number (e.g. "33239" or "44927.0"). Only 5-digit serials are
        // treated this way — 10000 ≈ 1927, 60000 ≈ 2064 — so plain 4-digit
        // years (handled below) are never mistaken for a serial.
        if (preg_match('/^(\d{5})(?:\.0+)?$/', $s, $m)) {
            $serial = (int) $m[1];
            if ($serial >= 10000 && $serial <= 60000) {
                try {
                    $year = (int) ExcelDate::excelToDateTimeObject((float) $serial)->format('Y');

                    return self::pair($year, $year);
                } catch (\Throwable) {
                    // Not a usable serial — fall through to the generic scan.
                }
            }
        }

        // ── 2. Circa prefix/suffix ("c. 1700", "circa 1700", "1700 ca") ──
        if (preg_match('/(?:^|\s)(?:c\.?|ca\.?|circa)\s*(\d{4})(?:\s|$)/iu', $s, $m)) {
            return self::pair((int) $m[1], (int) $m[1]);
        }
        if (preg_match('/(\d{4})\s*(?:c\.?|ca\.?|circa)\s*(?:$|\W)/iu', $s, $m)) {
            return self::pair((int) $m[1], (int) $m[1]);
        }

        // ── 3. Decade "1700s" ─────────────────────────────────────────────
        // Match "1700s" but NOT "17008" or "17005abc" — only "s" with word-end.
        if (preg_match('/\b(\d{3}0)s\b/iu', $s, $m)) {
            $base = (int) $m[1];

            return self::pair($base, $base + 9);
        }

        // ── 4. Ordinal century "18th century" / "18th c." ─────────────────
        // Accepts "1st" through "21st" only; out-of-range ordinals (e.g. "0th",
        // "99th") fall through rather than producing a nonsensical span.
        if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)\s+c(?:entury|\.)?/iu', $s, $m)) {
            $ord = (int) $m[1];
            if ($ord >= 1 && $ord <= 21) {
                return self::centurySpan($ord);
            }
        }

        // ── 5. Roman-numeral century "sec. XVIII" / bare "XVIII" ──────────
        // Only matches Roman numerals that look like centuries (I–XXI).
        // Case-insensitive so "sec. xviii" / "xvii" are recognised too.
        if (preg_match('/(?:sec(?:olo)?\.?\s+)?(?<rom>[IVXLC]{2,})\b/iu', $s, $m)) {
            $ord = self::fromRoman(strtoupper($m['rom']));
            if ($ord !== null && $ord >= 1 && $ord <= 21) {
                return self::centurySpan($ord);
            }
        }

        // ── 6. Generic year scan (the main strategy) ──────────────────────
        // Collect every 4-digit year in range [1000, 2099] and take min..max.
        // This correctly handles single years, dash/en-dash/"to"/slash ranges,
        // month-name ranges ("Apr 1745 - Sep 1768" → months ignored, years
        // kept), reversed ranges (normalised to start ≤ end), and multi-range
        // lists ("1934-1936; 1938-1942; 1947" → 1934 / 1947).
        preg_match_all('/\b(\d{4})\b/', $s, $all);
        $years = array_values(array_unique(array_filter(
            array_map(intval(...), $all[1]),
            static fn (int $y): bool => $y >= 1000 && $y <= 2099,
        )));

        if (count($years) === 0) {
            return $none;
        }

        $min = min($years);
        $max = max($years);

        return self::pair($min, $max);
    }

    // ── Private helpers ────────────────────────────────────────────────────

    /**
     * Build a pair, normalising so start ≤ end.
     *
     * @return array{year_start: int|null, year_end: int|null}
     */
    private static function pair(int $start, int $end): array
    {
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        return ['year_start' => $start, 'year_end' => $end];
    }

    /**
     * Convert an ordinal century number (1 = 1st c., 18 = 18th c.) to a full
     * year span [start, end].
     *
     * @return array{year_start: int|null, year_end: int|null}
     */
    private static function centurySpan(int $ord): array
    {
        $start = ($ord - 1) * 100;

        return self::pair($start, $start + 99);
    }

    /**
     * Minimal Roman-numeral parser (I–XXI, sufficient for centuries).
     * Returns null for unrecognised strings.
     */
    private static function fromRoman(string $r): ?int
    {
        $map = [
            'M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400,
            'C' => 100,  'XC' => 90,  'L' => 50,  'XL' => 40,
            'X' => 10,   'IX' => 9,   'V' => 5,   'IV' => 4,
            'I' => 1,
        ];

        // Guard: only allow valid Roman characters.
        if (! preg_match('/^[IVXLCDM]+$/i', $r)) {
            return null;
        }

        $result = 0;
        $i = 0;
        $len = strlen($r);
        while ($i < $len) {
            $two = substr($r, $i, 2);
            if (isset($map[$two])) {
                $result += $map[$two];
                $i += 2;
            } elseif (isset($map[$r[$i]])) {
                $result += $map[$r[$i]];
                $i++;
            } else {
                return null; // invalid character
            }
        }

        return $result > 0 ? $result : null;
    }
}
