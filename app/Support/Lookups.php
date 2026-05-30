<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * RFQ §3.1.11 (part 2 of 3) — runtime validation against the editable lookup
 * tables (App\Models\Lookup\*). The lookup tables replace the hard-coded PHP
 * const arrays as the source of truth for the allowed set; this helper lets
 * the model `saving` guards reject any value that is not an ACTIVE code in the
 * relevant lookup.
 *
 * Principle: expand, never restrict. A null/empty value is always accepted
 * (the column nullability / required-rules govern presence separately).
 */
class Lookups
{
    /**
     * Throw if $value is non-null and not an ACTIVE code in the given lookup model.
     *
     * @param class-string<Model> $modelClass
     */
    public static function assertActive(string $modelClass, string $field, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $exists = $modelClass::query()
            ->where('code', $value)
            ->where('is_active', true)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                $field => "Value '{$value}' is not an active {$field} option.",
            ]);
        }
    }
}
