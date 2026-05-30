<?php

declare(strict_types=1);

namespace App\Models\Lookup\Concerns;

/**
 * Shared option-list helpers for the editable lookup models (RFQ §3.1.11).
 *
 * {@see optionsWith()} solves the "edit-an-existing-record-whose-value-is-now-
 * inactive" trap (CodeRabbit C4): a Select fed only with `options()` (active
 * rows) drops a record's CURRENT value when it has since been deactivated, so
 * it is not selectable and saving other fields can silently change / blank it.
 * `optionsWith($current)` merges the record's current code back into the active
 * set so an inactive-but-current value stays visible and saveable on edit.
 *
 * Requires the using model to expose an `options(): array<string,string>`
 * static method and a `code`/`label` schema (all six lookup models do).
 */
trait HasLookupOptions
{
    /**
     * Active options PLUS the given current value (even if it is inactive),
     * so an edit form never loses a record's stored value. A null/empty
     * current value is a no-op (returns just the active set).
     *
     * @return array<string,string> code => label
     */
    public static function optionsWith(?string $current): array
    {
        $options = static::options();

        if ($current === null || $current === '' || array_key_exists($current, $options)) {
            return $options;
        }

        // The current value is not in the active set — look up its label so the
        // option reads naturally; fall back to the raw code if the row is gone.
        $label = static::query()->where('code', $current)->value('label');
        $options[$current] = $label !== null ? $label . ' (inactive)' : $current;

        return $options;
    }
}
