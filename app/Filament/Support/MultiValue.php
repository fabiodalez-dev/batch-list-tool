<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * Client 2026-11-05: "Multiple of each may exist". Several values kept in one
 * text column, joined by "; ", so imports, exports, list columns and filters
 * go on reading the column as they always did.
 */
final class MultiValue
{
    /**
     * @return list<string>
     */
    public static function split(mixed $value): array
    {
        $values = [];
        foreach (is_array($value) ? $value : [$value] as $part) {
            foreach (explode(';', (string) $part) as $piece) {
                if (($piece = trim($piece)) !== '') {
                    $values[] = $piece;
                }
            }
        }

        return $values;
    }

    public static function join(mixed $value): ?string
    {
        $values = self::split($value);

        return $values === [] ? null : implode('; ', $values);
    }
}
