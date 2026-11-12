<?php

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;

/**
 * Client 2026-10-30: "Can you also check that dates are in the format of
 * dd-mm-yyyy throughout the whole system?"
 *
 * The one place the display formats live. Filament's tables, view pages and
 * date pickers take them as defaults (PresentationServiceProvider); code that
 * formats a date by hand for a person to read uses these helpers.
 *
 * Storage and the import/export templates stay ISO (Y-m-d): those files go
 * back through the importer, and ISO is the one form no spreadsheet can
 * read day-first or month-first by mistake.
 */
final class DisplayDate
{
    public const string DATE = 'd-m-Y';

    public const string DATE_TIME = 'd-m-Y H:i';

    public const string DATE_TIME_SECONDS = 'd-m-Y H:i:s';

    public static function date(mixed $value): ?string
    {
        return self::format($value, self::DATE);
    }

    public static function dateTime(mixed $value): ?string
    {
        return self::format($value, self::DATE_TIME);
    }

    private static function format(mixed $value, string $format): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format($format);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format($format);
        } catch (\Throwable) {
            return $value;
        }
    }
}
