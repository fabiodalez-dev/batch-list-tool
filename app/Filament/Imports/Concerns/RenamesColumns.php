<?php

declare(strict_types=1);

namespace App\Filament\Imports\Concerns;

use App\Support\ColumnLabels\ColumnLabels;
use Filament\Actions\Imports\ImportColumn;

/**
 * Lets a repository's own column names reach the importer.
 *
 * Client 2026-09-24, then 2026-09-25: the cataloguer had renamed Authority
 * columns three times in nine days, each one a code change and a deploy, and
 * then asked for the same on every other template. The names now live in
 * {@see ColumnLabels}; this is how an importer picks them up.
 *
 * Renaming a column has to keep the sheet importable, which is why only the
 * LABEL moves. The field name, the type and the rules stay in code, so a rename
 * cannot change which rows are valid or where a value is stored.
 */
trait RenamesColumns
{
    /**
     * Re-label the columns this repository has renamed.
     *
     * Only a column that has ACTUALLY been renamed is touched. That matters
     * beyond saving work: ColumnLabels::DEFAULTS holds the header as the
     * TEMPLATE spells it, which on the batch, location and volume templates is
     * still a technical name ("batch_number", "parent_name"), while the
     * importer's own label is the readable one ("Batch number"). Assigning the
     * default unconditionally would replace every readable label in the
     * column-mapping dropdown with its technical spelling — a rename nobody
     * asked for.
     *
     * The factory header keeps importing either way, because it is the field
     * name and ImportWizard::guessSingleColumn tries the field name first, ahead
     * of the label and the guess list.
     *
     * @param array<int, ImportColumn> $columns
     * @return array<int, ImportColumn>
     */
    protected static function applyRenameableLabels(string $entityType, array $columns): array
    {
        $factory = ColumnLabels::DEFAULTS[$entityType] ?? [];

        if ($factory === []) {
            return $columns;
        }

        $current = ColumnLabels::all($entityType);

        foreach ($columns as $column) {
            $key = $column->getName();

            if (! array_key_exists($key, $factory)) {
                continue;
            }

            $label = $current[$key] ?? $factory[$key];

            if ($label === $factory[$key]) {
                continue;
            }

            // Setting the LABEL is enough for the renamed header to be
            // recognised: guessSingleColumn tries the field name, the label and
            // the guess list in that order.
            $column->label($label);

            // Filament builds "The :attribute field is required" from
            // Str::lcfirst($label), which turns "Citing Reference Code" into
            // "citing Reference Code" — a column name with its first letter
            // knocked down, in the one message the cataloguer reads most.
            // Naming the attribute explicitly keeps the column's own name.
            $column->validationAttribute($label);
        }

        return $columns;
    }
}
