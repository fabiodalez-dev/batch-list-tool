<?php

declare(strict_types=1);

namespace App\Support\BulkImport;

use App\Console\Commands\ImportSampleData;
use App\Models\Authority;
use App\Models\Document;
use Illuminate\Support\Carbon;
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
     * Largest Excel serial a free-text date column will be read as a date:
     * 73415, which is 2100-12-31.
     *
     * Excel turns a typed date into a serial, so a genuine one lands in the
     * low tens of thousands (2026 is around 46000). A larger number is
     * something the cataloguer typed by hand — a compact date like
     * "20260729", or the span "1607-1629" written without its dash — and
     * converting those yields 57371-12-27 and 45902-08-16 rather than an
     * error, so the row saves a wrong date and nothing complains.
     */
    public const int MAX_DATE_SERIAL = 73415;

    /**
     * Every "Type of Entity" the system accepts, in its stored spelling.
     *
     * Two vocabularies are live and both stay valid: PERSON / INSTITUTION, which
     * the client's own authority sheets carry ("Person" on all 676 production
     * rows), and Notary / Interventor, which the Creator form offers because the
     * client asked for Notary as the default (feedback 1, 2026-06-06). Which one
     * becomes THE vocabulary is the client's decision, not the importer's.
     *
     * @var list<string>
     */
    public const array ENTITY_TYPES = ['PERSON', 'INSTITUTION', 'Notary', 'Interventor'];

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
     * Read a free-text date cell as an Excel serial, or return null when the
     * value is not plausibly one and should be kept as the operator typed it.
     *
     * A value of 9999 or less is a year ("2026") or part of a span, never a
     * serial — serials that low are dates before 1928, which Excel cannot
     * have produced from a modern typed date.
     */
    public static function freeTextDateSerial(string $state): ?string
    {
        if (! ctype_digit($state)) {
            return null;
        }

        $serial = (int) $state;
        if ($serial <= 9999 || $serial > self::MAX_DATE_SERIAL) {
            return null;
        }

        try {
            return ExcelDate::excelToDateTimeObject($serial)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
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
        // A time after the date ("06/03/2024 00:00", how Excel writes a datetime
        // cell to CSV) is allowed and ignored: without it in the pattern the
        // cell fell through to strtotime() and came back as 3 June.
        if (preg_match('#^(\d{1,4})[/.\-](\d{1,2})[/.\-](\d{1,4})(?:[ T]\d{1,2}:\d{2}(?::\d{2})?)?$#', $str, $m)) {
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
     * A "Destroyed" cell → the moment the box was destroyed, or null.
     *
     * "Yes" (and the other truthy flags) means destroyed, date unknown, so now;
     * a date means destroyed on that date; anything else means not destroyed.
     * The flags are checked FIRST: parseDate('1') would read "1" as the Excel
     * serial 1900-01-01 rather than a yes. Shared by the box sheet's Destroyed
     * column and the documents sheet's per-box Destroyed columns, so one cell
     * means the same thing on both.
     */
    public static function parseDestroyed(?string $value): ?Carbon
    {
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        if (self::isDestroyedYes($s)) {
            return Carbon::now();
        }

        return self::parseDestroyedDate($s);
    }

    /**
     * The date a Destroyed cell gives, or null when it gives none — blank, a
     * plain "Yes", or not a date. A "Yes" carries no date of its own: the
     * caller decides whether "now" is right or an existing date must stay.
     */
    public static function parseDestroyedDate(?string $value): ?Carbon
    {
        $s = trim((string) $value);
        if ($s === '' || self::isDestroyedYes($s)) {
            return null;
        }
        $date = self::parseDate($s);

        return $date === null ? null : Carbon::parse($date);
    }

    /**
     * Map a "Type of Entity" cell onto its stored spelling, case-insensitively.
     *
     * Blank stays PERSON, as before. An UNKNOWN value is returned as written so
     * the column's `in:` rule rejects the row with a message the operator can
     * act on. It used to become INSTITUTION without a word: a typo, an ISAAR
     * "Family", or the form's own "Notary" all turned a creator into an
     * institution — and a Notary set in the form came back as INSTITUTION from
     * every export-and-reimport.
     */
    public static function normaliseEntityType(?string $value): string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return 'PERSON';
        }

        foreach (self::ENTITY_TYPES as $type) {
            if (strcasecmp($type, $trimmed) === 0) {
                return $type;
            }
        }

        return $trimmed;
    }

    private static function isDestroyedYes(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), ['yes', 'y', '1', 'true', 'x', 'destroyed'], true);
    }
}
