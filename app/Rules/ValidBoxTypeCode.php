<?php

declare(strict_types=1);

namespace App\Rules;

use App\Filament\Pages\ImportWizard;
use App\Models\Box;
use App\Models\Lookup\BoxType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a box_type against the ACTIVE Box Types lookup codes (so an operator
 * who adds a new type — e.g. MUS — under Box Types can import it), UNIONed with
 * the canonical {@see Box::TYPES} as a fallback for bootstraps where the lookup
 * table is empty (minimal tests).
 *
 * Implemented as a ValidationRule OBJECT rather than a Closure on purpose: the
 * ImportWizard preflight ({@see ImportWizard::validateRows()})
 * only keeps string / array / ValidationRule rules and silently drops Closures,
 * so a Closure rule would be enforced at import time but INVISIBLE in the
 * "N rows would fail validation" preview the operator relies on. A ValidationRule
 * participates in both. Blank is accepted (the box_type column is nullable — the
 * "Unknown"/"NULL" catch-all boxes carry no type).
 */
class ValidBoxTypeCode implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $code = strtoupper(trim((string) $value));

        if ($code === '') {
            return; // nullable
        }

        if (! in_array($code, self::allowedCodes(), true)) {
            $fail('The selected Box type is invalid. Add it under Box Types first, or use one of: '
                . implode(', ', self::allowedCodes()) . '.');
        }
    }

    /**
     * @return list<string>
     */
    public static function allowedCodes(): array
    {
        $codes = Box::TYPES;

        try {
            $codes = array_merge($codes, array_keys(BoxType::options()));
        } catch (\Throwable) {
            // Lookup table unavailable — fall back to the canonical set.
        }

        return array_values(array_unique(array_map(
            static fn ($c): string => strtoupper((string) $c),
            $codes,
        )));
    }
}
