<?php

declare(strict_types=1);

use App\Support\BulkImport\DateRangeNormalizer;
use App\Support\BulkImport\SpreadsheetParsers;

/**
 * Wave F1 — DateRangeNormalizer: rich free-text → year-range extraction.
 *
 * Tests cover ALL formats documented in the class docblock and the spec:
 *
 * Group 1 — Single year / plain ranges (hyphen, en-dash, "to")
 * Group 2 — Month-name ranges ("Apr 1745 – Sep 1768", full month names)
 * Group 3 — Circa / decade / century expressions
 * Group 4 — Edge cases: junk/undated, reversed range, multi-year scan,
 *            SpreadsheetParsers backward-compatibility
 */

// ===========================================================================
// Group 1 — Single year and plain ranges
// ===========================================================================

it('F1-Single.1: bare year returns start = end', function (): void {
    $r = DateRangeNormalizer::extractYearRange('1870');
    expect($r['year_start'])->toBe(1870);
    expect($r['year_end'])->toBe(1870);
});

it('F1-Single.2: hyphen range returns correct start and end', function (): void {
    $r = DateRangeNormalizer::extractYearRange('1745-1768');
    expect($r['year_start'])->toBe(1745);
    expect($r['year_end'])->toBe(1768);
});

it('F1-Single.3: en-dash range is handled', function (): void {
    $r = DateRangeNormalizer::extractYearRange('1745–1768');
    expect($r['year_start'])->toBe(1745);
    expect($r['year_end'])->toBe(1768);
});

it('F1-Single.4: "to" keyword range is handled', function (): void {
    $r = DateRangeNormalizer::extractYearRange('1745 to 1768');
    expect($r['year_start'])->toBe(1745);
    expect($r['year_end'])->toBe(1768);
});

// ===========================================================================
// Group 2 — Month-name ranges (abbreviated and full)
// ===========================================================================

it('F1-Month.1: abbreviated month range extracts years only', function (): void {
    $r = DateRangeNormalizer::extractYearRange('Apr 1745 - Sep 1768');
    expect($r['year_start'])->toBe(1745);
    expect($r['year_end'])->toBe(1768);
});

it('F1-Month.2: Jun 1997 - Nov 1998 extracts correct years', function (): void {
    $r = DateRangeNormalizer::extractYearRange('Jun 1997 - Nov 1998');
    expect($r['year_start'])->toBe(1997);
    expect($r['year_end'])->toBe(1998);
});

it('F1-Month.3: full month names with en-dash are handled', function (): void {
    $r = DateRangeNormalizer::extractYearRange('April 1745 – September 1768');
    expect($r['year_start'])->toBe(1745);
    expect($r['year_end'])->toBe(1768);
});

it('F1-Month.4: full month name single date returns start = end', function (): void {
    $r = DateRangeNormalizer::extractYearRange('March 1820');
    expect($r['year_start'])->toBe(1820);
    expect($r['year_end'])->toBe(1820);
});

// ===========================================================================
// Group 3 — Circa, decade, century
// ===========================================================================

it('F1-Circa.1: "c. 1700" returns 1700/1700', function (): void {
    $r = DateRangeNormalizer::extractYearRange('c. 1700');
    expect($r['year_start'])->toBe(1700);
    expect($r['year_end'])->toBe(1700);
});

it('F1-Circa.2: "circa 1700" returns 1700/1700', function (): void {
    $r = DateRangeNormalizer::extractYearRange('circa 1700');
    expect($r['year_start'])->toBe(1700);
    expect($r['year_end'])->toBe(1700);
});

it('F1-Circa.3: "1700 ca" suffix form returns 1700/1700', function (): void {
    $r = DateRangeNormalizer::extractYearRange('1700 ca');
    expect($r['year_start'])->toBe(1700);
    expect($r['year_end'])->toBe(1700);
});

it('F1-Circa.4: "ca 1700" prefix form returns 1700/1700', function (): void {
    $r = DateRangeNormalizer::extractYearRange('ca 1700');
    expect($r['year_start'])->toBe(1700);
    expect($r['year_end'])->toBe(1700);
});

it('F1-Decade.1: "1700s" expands to 1700-1709', function (): void {
    $r = DateRangeNormalizer::extractYearRange('1700s');
    expect($r['year_start'])->toBe(1700);
    expect($r['year_end'])->toBe(1709);
});

it('F1-Decade.2: "1850s" expands to 1850-1859', function (): void {
    $r = DateRangeNormalizer::extractYearRange('1850s');
    expect($r['year_start'])->toBe(1850);
    expect($r['year_end'])->toBe(1859);
});

it('F1-Century.1: "18th century" maps to 1700-1799', function (): void {
    $r = DateRangeNormalizer::extractYearRange('18th century');
    expect($r['year_start'])->toBe(1700);
    expect($r['year_end'])->toBe(1799);
});

it('F1-Century.2: "17th c." maps to 1600-1699', function (): void {
    $r = DateRangeNormalizer::extractYearRange('17th c.');
    expect($r['year_start'])->toBe(1600);
    expect($r['year_end'])->toBe(1699);
});

it('F1-Century.3: Roman-numeral "sec. XVIII" maps to 1700-1799', function (): void {
    $r = DateRangeNormalizer::extractYearRange('sec. XVIII');
    expect($r['year_start'])->toBe(1700);
    expect($r['year_end'])->toBe(1799);
});

it('F1-Century.4: bare Roman "XVII" maps to 1600-1699', function (): void {
    $r = DateRangeNormalizer::extractYearRange('XVII');
    expect($r['year_start'])->toBe(1600);
    expect($r['year_end'])->toBe(1699);
});

// ===========================================================================
// Group 4 — Edge cases: junk/undated, reversed range, multi-year, BC compat
// ===========================================================================

it('F1-Edge.1: "n.d." returns null/null', function (): void {
    $r = DateRangeNormalizer::extractYearRange('n.d.');
    expect($r['year_start'])->toBeNull();
    expect($r['year_end'])->toBeNull();
});

it('F1-Edge.2: "s.d." returns null/null', function (): void {
    $r = DateRangeNormalizer::extractYearRange('s.d.');
    expect($r['year_start'])->toBeNull();
    expect($r['year_end'])->toBeNull();
});

it('F1-Edge.3: "undated" returns null/null', function (): void {
    $r = DateRangeNormalizer::extractYearRange('undated');
    expect($r['year_start'])->toBeNull();
    expect($r['year_end'])->toBeNull();
});

it('F1-Edge.4: empty string returns null/null', function (): void {
    $r = DateRangeNormalizer::extractYearRange('');
    expect($r['year_start'])->toBeNull();
    expect($r['year_end'])->toBeNull();
});

it('F1-Edge.5: non-date text returns null/null', function (): void {
    $r = DateRangeNormalizer::extractYearRange('see original register');
    expect($r['year_start'])->toBeNull();
    expect($r['year_end'])->toBeNull();
});

it('F1-Edge.6: reversed range is normalised to start <= end', function (): void {
    $r = DateRangeNormalizer::extractYearRange('1768-1745');
    expect($r['year_start'])->toBe(1745);
    expect($r['year_end'])->toBe(1768);
});

it('F1-Edge.7: multi-year list returns min and max', function (): void {
    $r = DateRangeNormalizer::extractYearRange('1745, 1750, 1768');
    expect($r['year_start'])->toBe(1745);
    expect($r['year_end'])->toBe(1768);
});

it('F1-Real.1: Excel serial date number is converted to its year (real sample data)', function (): void {
    // Date-formatted cells in the NRA spreadsheets arrive as 5-digit serials.
    $r = DateRangeNormalizer::extractYearRange('33239.0');
    expect($r['year_start'])->toBe(1991);
    expect($r['year_end'])->toBe(1991);

    $r2 = DateRangeNormalizer::extractYearRange('44927');
    expect($r2['year_start'])->toBe(2023);
    expect($r2['year_end'])->toBe(2023);
});

it('F1-Real.2: a 4-digit value stays a year and is never read as an Excel serial', function (): void {
    $r = DateRangeNormalizer::extractYearRange('1990');
    expect($r['year_start'])->toBe(1990);
    expect($r['year_end'])->toBe(1990);
});

it('F1-Real.3: multi-range list with gaps spans the overall min..max', function (): void {
    // Real Authorities sample: "1934-1936; 1938-1942; 1947".
    $r = DateRangeNormalizer::extractYearRange('1934-1936; 1938-1942; 1947');
    expect($r['year_start'])->toBe(1934);
    expect($r['year_end'])->toBe(1947);
});

it('F1-Real.4: month-name ranges from the real Dates column keep the years', function (): void {
    expect(DateRangeNormalizer::extractYearRange('Apr - Jun 1963'))
        ->toBe(['year_start' => 1963, 'year_end' => 1963]);
    expect(DateRangeNormalizer::extractYearRange('Jan 1965 - Dec 1966'))
        ->toBe(['year_start' => 1965, 'year_end' => 1966]);
    expect(DateRangeNormalizer::extractYearRange('Sep 1705 - Feb 1706'))
        ->toBe(['year_start' => 1705, 'year_end' => 1706]);
});

it('F1-Real.5: Roman-numeral centuries are recognised case-insensitively', function (): void {
    expect(DateRangeNormalizer::extractYearRange('sec. xviii'))
        ->toBe(['year_start' => 1700, 'year_end' => 1799]);
    expect(DateRangeNormalizer::extractYearRange('XVII'))
        ->toBe(['year_start' => 1600, 'year_end' => 1699]);
});

it('F1-Real.6: out-of-range ordinal centuries do not produce a nonsensical span', function (): void {
    expect(DateRangeNormalizer::extractYearRange('0th century'))
        ->toBe(['year_start' => null, 'year_end' => null]);
    expect(DateRangeNormalizer::extractYearRange('99th century'))
        ->toBe(['year_start' => null, 'year_end' => null]);
});

it('F1-Compat.1: SpreadsheetParsers::parseYearRange returns tuple shape delegating to normalizer', function (): void {
    [$start, $end] = SpreadsheetParsers::parseYearRange('1745–1768');
    expect($start)->toBe(1745);
    expect($end)->toBe(1768);
});

it('F1-Compat.2: SpreadsheetParsers::parseYearRange null input returns null/null tuple', function (): void {
    [$start, $end] = SpreadsheetParsers::parseYearRange(null);
    expect($start)->toBeNull();
    expect($end)->toBeNull();
});

it('F1-Compat.3: SpreadsheetParsers::parseYearRange plain YYYY still works', function (): void {
    [$start, $end] = SpreadsheetParsers::parseYearRange('1607');
    expect($start)->toBe(1607);
    expect($end)->toBe(1607);
});

it('F1-Compat.4: SpreadsheetParsers::parseYearRange YYYY-YYYY still works', function (): void {
    [$start, $end] = SpreadsheetParsers::parseYearRange('1607-1629');
    expect($start)->toBe(1607);
    expect($end)->toBe(1629);
});
