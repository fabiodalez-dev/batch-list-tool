<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Document;
use App\Support\BulkImport\SpreadsheetParsers;
use App\Support\DisplayDate;
use App\Support\FieldPermissions;
use Carbon\Carbon;

/**
 * Client 2026-11-05: "Dates – include the 3 options – ask the user to select
 * which option he wants". The choice is not stored: it is read back from what
 * the document holds. `dates` is the text every list and export shows, so this
 * is also what keeps it right when one of the options is saved.
 *
 * Opening a document and saving it must never change its dates: a text like
 * "Jun 1997 - Nov 1998" opens as Free text, and comes back as it went in.
 */
final class DocumentDates
{
    public const string YEAR = 'year';

    public const string DATE = 'date';

    public const string TEXT = 'text';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::YEAR => 'Year start / Year end',
            self::DATE => 'Date start / Date end',
            self::TEXT => 'Free text',
        ];
    }

    /**
     * Which option a document opens on. A new document has none: the user is asked.
     */
    public static function optionFor(?Document $document): ?string
    {
        if (! $document instanceof Document || ! $document->exists) {
            return null;
        }
        if ($document->dates_start !== null || $document->dates_end !== null) {
            return self::DATE;
        }
        // Year only when saving it would write back the very same text.
        if (blank($document->dates) || $document->dates === self::yearText($document->dates_year_start, $document->dates_year_end)) {
            return self::YEAR;
        }

        return self::TEXT;
    }

    /**
     * "1738 - 1739", or the single year.
     */
    public static function yearText(mixed $start, mixed $end): ?string
    {
        $years = array_values(array_unique(array_filter([trim((string) $start), trim((string) $end)], 'filled')));

        return $years === [] ? null : implode(' - ', $years);
    }

    /**
     * "05-11-1738 - 06-11-1739", or the single date.
     */
    public static function dateText(mixed $start, mixed $end): ?string
    {
        $dates = array_values(array_unique(array_filter([DisplayDate::date($start), DisplayDate::date($end)])));

        return $dates === [] ? null : implode(' - ', $dates);
    }

    /**
     * Turn the chosen option's fields into what the document stores. With no
     * option chosen the form sent no date field at all, and nothing changes.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function apply(array $data, ?string $option, ?Document $record): array
    {
        // A role that may not write the dates saves none of them, whatever was posted.
        if (! FieldPermissions::canWrite('document', 'dates')) {
            unset($data['dates'], $data['dates_year_start'], $data['dates_year_end'], $data['dates_start'], $data['dates_end']);

            return $data;
        }

        return match ($option) {
            self::YEAR => self::applyYears($data),
            self::DATE => self::applyDates($data, $record),
            self::TEXT => self::applyText($data, $record),
            default => $data,
        };
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function applyYears(array $data): array
    {
        $data['dates'] = self::yearText($data['dates_year_start'] ?? null, $data['dates_year_end'] ?? null);
        $data['dates_start'] = null;
        $data['dates_end'] = null;

        return $data;
    }

    /**
     * The years follow the dates, for the "From (year)" and "To (year)" rules
     * of the documents list.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function applyDates(array $data, ?Document $record): array
    {
        $start = $data['dates_start'] ?? null;
        $end = $data['dates_end'] ?? null;

        // Nothing touched on a document that opened on this option: its text
        // may say more than the two dates do.
        if ($record instanceof Document
            && filled($record->dates)
            && self::optionFor($record) === self::DATE
            && DisplayDate::date($start) === DisplayDate::date($record->dates_start)
            && DisplayDate::date($end) === DisplayDate::date($record->dates_end)) {
            return $data;
        }

        $data['dates'] = self::dateText($start, $end);
        $data['dates_year_start'] = filled($start) ? Carbon::parse($start)->year : null;
        $data['dates_year_end'] = filled($end) ? Carbon::parse($end)->year : null;

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function applyText(array $data, ?Document $record): array
    {
        $text = trim((string) ($data['dates'] ?? ''));
        $data['dates'] = $text !== '' ? $text : null;
        $data['dates_start'] = null;
        $data['dates_end'] = null;

        // The years of an untouched text stay: they may not come from it.
        if (! $record instanceof Document || $text !== trim((string) $record->dates)) {
            [$data['dates_year_start'], $data['dates_year_end']] = SpreadsheetParsers::parseYearRange($text);
        }

        return $data;
    }
}
