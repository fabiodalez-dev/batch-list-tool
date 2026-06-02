<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

use App\Models\CustomFieldDefinition;
use Carbon\Carbon;

/**
 * Shared CSV value-formatting helper for custom fields.
 *
 * Centralises the type→string conversion so all four entity exporters
 * (Document, Batch, Box, Volume) stay in sync with a single implementation
 * instead of four copies of the same match block.
 *
 * Usage:
 *
 *   $cell = CustomFieldCsv::format($def, $typedValue);
 *   // then sanitize as needed: $sanitized = $this->sanitizeCsvCell($cell);
 */
final class CustomFieldCsv
{
    /**
     * Convert a typed custom-field value to a CSV-safe string.
     *
     * The caller is responsible for any further CSV-injection sanitisation
     * (leading "=", "+", "-", "@", TAB, CR) via the resource's
     * sanitizeCsvCell() helper — this method only handles the type→string
     * conversion, not the injection-safety step, so the concerns stay
     * separate and the sanitizer is not applied twice.
     *
     * @param CustomFieldDefinition $def The definition that describes the value.
     * @param mixed $typed The value returned by getTypedValueAttribute()
     *                     on the CustomFieldValue model; null when no
     *                     value is stored for this record.
     */
    public static function format(CustomFieldDefinition $def, mixed $typed): string
    {
        if ($typed === null) {
            return '';
        }

        return match ($def->type) {
            'boolean' => $typed ? '1' : '0',
            'date' => $typed instanceof Carbon ? $typed->toDateString() : (string) $typed,
            'datetime' => $typed instanceof Carbon ? $typed->toDateTimeString() : (string) $typed,
            default => (string) $typed,
        };
    }
}
