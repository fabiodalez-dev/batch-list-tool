<?php

declare(strict_types=1);

namespace App\Support\BulkImport;

use App\Console\Commands\ImportSampleData;
use App\Models\Authority;
use App\Models\Document;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Small, pure helpers for converting raw spreadsheet cell values into the
 * shapes the application uses internally. All methods are static and
 * deliberately tolerant: spreadsheets coming from operators in different
 * locales are inconsistent, and we'd rather best-effort parse than reject.
 *
 * These mirror the helpers in {@see ImportSampleData}
 * so that the artisan command and the Filament UI produce identical rows
 * for the same input — important for round-tripping tests and for parity
 * with the existing test fixtures.
 */
final class SpreadsheetParsers
{
    /**
     * Parse a free-text date string into a (start, end) integer-year pair.
     * Returns `[null, null]` on failure.
     *
     * Delegates to {@see DateRangeNormalizer::extractYearRange()} which
     * handles a rich set of formats (ranges, circa, decades, centuries, etc.)
     * beyond the original simple YYYY / YYYY-YYYY patterns. All existing callers
     * transparently receive the richer behaviour.
     *
     * Used for {@see Authority}::practice_dates_start/end and
     * {@see Document}::dates_year_start/end.
     *
     * @return array{0: int|null, 1: int|null}
     */
    public static function parseYearRange(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [null, null];
        }

        $result = DateRangeNormalizer::extractYearRange($value);

        return [$result['year_start'], $result['year_end']];
    }

    /**
     * Parse any cell into a Y-m-d date string, or null on failure.
     *
     * Excel stores dates as a serial number (days since 1900-01-00) — when
     * PhpSpreadsheet's `setReadDataOnly(true)` is on (our import path), the
     * cell arrives as a float. We round-trip via ExcelDate::excelToDateTimeObject
     * to get back to a real DateTime. For non-numeric values we fall back to
     * `strtotime()` which handles "2026-05-26", "26/05/2026", "May 26 2026"
     * and so on.
     */
    public static function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        $str = trim((string) $value);

        // Numeric day/month/year with /, . or - separators. PHP's strtotime()
        // reads "/" as the US month-first order, which silently DROPS European
        // dates such as "31/05/2023" (the format in the NRA sheets). Parse
        // these explicitly, preferring the day-first order used in Malta/Europe
        // and only using month-first when the first part cannot be a day.
        if (preg_match('#^(\d{1,4})[/.\-](\d{1,2})[/.\-](\d{1,4})$#', $str, $m)) {
            $a = (int) $m[1];
            $b = (int) $m[2];
            $c = (int) $m[3];
            $year = 0;
            $month = 0;
            $day = 0;
            if (strlen($m[1]) === 4) {            // YYYY-MM-DD (any separator)
                $year = $a;
                $month = $b;
                $day = $c;
            } elseif (strlen($m[3]) === 4) {      // DD-MM-YYYY / MM-DD-YYYY
                $year = $c;
                if ($a > 12) {                    // first part must be the day
                    $day = $a;
                    $month = $b;
                } elseif ($b > 12) {              // second part can't be a month
                    $month = $a;
                    $day = $b;
                } else {                          // ambiguous → European day-first
                    $day = $a;
                    $month = $b;
                }
            }
            if ($year > 0 && checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        $ts = strtotime($str);

        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    /**
     * Parse a cell into an integer or null. Accepts pure numbers ("1.0"
     * from xlsx) and "1.0 Old Storage" strings (extract first run of digits).
     */
    public static function parseInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (preg_match('/^\s*(\d+)/', (string) $value, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Loose boolean parser — accepts the locale-mixed truthy set we see in
     * the legacy "Torre" / "Digitised" columns (1, yes, y, true, t, si, sì).
     */
    public static function parseBool(mixed $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        $s = strtolower(trim((string) $value));

        return in_array($s, ['1', 'yes', 'y', 'true', 't', 'si', 'sì'], true);
    }

    /**
     * Trim and lowercase entity-type tokens to the canonical PERSON /
     * INSTITUTION codes used by `authorities.entity_type`. Unknown values
     * default to INSTITUTION (corporate notaries / churches were the only
     * non-PERSON entities in the legacy POC).
     */
    public static function normaliseEntityType(?string $value): string
    {
        if ($value === null) {
            return 'PERSON';
        }
        $s = strtoupper(trim($value));

        return $s === 'PERSON' ? 'PERSON' : 'INSTITUTION';
    }
}
