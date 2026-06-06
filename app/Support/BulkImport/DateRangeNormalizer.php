<?php

declare(strict_types=1);

namespace App\Support\BulkImport;

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
 *   "n.d." / "s.d." / "undated"    → null / null
 *   ""  / non-date text            → null / null
 *   reversed "1768-1745"           → 1745 / 1768  (normalised so start ≤ end)
 *
 * General fallback: scan all 4-digit years (1000–2099); if ≥ 2 found use
 * min & max; if exactly 1 found use it for both start and end.
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
        // Accepts "1st" through "21st" (and the ND / RD / TH suffix variants).
        if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)\s+c(?:entury|\.)?/iu', $s, $m)) {
            return self::centurySpan((int) $m[1]);
        }

        // ── 5. Roman-numeral century "sec. XVIII" / bare "XVIII" ──────────
        // Only matches Roman numerals that look like centuries (I–XXI).
        if (preg_match('/(?:sec(?:olo)?\.?\s+)?(?<rom>[IVXLC]{2,})\b/u', $s, $m)) {
            $ord = self::fromRoman(strtoupper($m['rom']));
            if ($ord !== null && $ord >= 1 && $ord <= 21) {
                return self::centurySpan($ord);
            }
        }

        // ── 6. Year ranges with separator: YYYY[-–—/to]YYYY ──────────────
        // Also handles optional month/day text around the years:
        //   "Apr 1745 - Sep 1768", "1745 to 1768", "1745/1768"
        if (preg_match(
            '/(\d{4})\s*(?:[-–—\/]|to)\s*(\d{4})/iu',
            $s,
            $m,
        )) {
            return self::pair((int) $m[1], (int) $m[2]);
        }

        // ── 7. Month-Year range: "Apr 1745 – Sep 1768" ───────────────────
        // Month names (abbreviated or full, any locale-safe subset of English)
        // before/after the year; the range separator is captured above, so if
        // we reach here with a pattern like "Apr 1745 - Sep 1768" it already
        // matched in step 6 via the YYYY…YYYY extractor. This step is a safety
        // net for any remaining "Month YYYY" single-side patterns — falls
        // through to the generic year scan below.

        // ── 8. Generic year scan (fallback) ───────────────────────────────
        // Collect all 4-digit years in range [1000, 2099].
        preg_match_all('/\b(\d{4})\b/', $s, $all);
        $years = array_values(array_unique(array_filter(
            array_map('intval', $all[1]),
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
