<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Actions\Documents\ExportSelectedAction;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Support\FieldPermissions;

/**
 * Shared helper that filters a raw column map through FieldPermissions before
 * any export (CSV) path emits a header row or data row.
 *
 * ## Why a shared concern
 *
 * The Document resource has two independent CSV export paths:
 *   - {@see ListDocuments::exportToCsv()}
 *     — exports the currently-filtered rows.
 *   - {@see ExportSelectedAction::perform()}
 *     — exports the explicitly-selected rows.
 *
 * Both paths emit the same fixed column list. RFQ §3.1.4 requires that the
 * field-permission matrix (configured in config/field_permissions.php and
 * overridable via FieldPermissionOverride rows) is applied consistently
 * across ALL output paths — UI, JSON API, *and* exports. Duplicating the
 * gate logic inline in each exporter would introduce a bypass risk.
 *
 * This trait centralises the single check so the two callers stay DRY and
 * any future export path automatically inherits the gate by mixing in the
 * trait and calling {@see self::visibleExportColumns()}.
 *
 * ## Column map format
 *
 * The helpers accept a flat associative array `['fieldKey' => 'Header label']`
 * where `fieldKey` is the snake_case field name as declared in the Document
 * model's `$fillable` (or a logical alias, e.g. `creator`, `series`,
 * `current_box` for relation columns). The gate is consulted for each
 * `fieldKey` using `FieldPermissions::canRead('document', $fieldKey)`.
 *
 * ## Relation columns
 *
 * Columns that render a related model's value (`series` → `series.code`,
 * `creator` → `authorities[].surname`, etc.) use an alias key rather than the
 * raw FK column name. The field-permission config does not currently have
 * explicit entries for those aliases, so they fall through to the `_default`
 * block which grants read to all four operational roles — i.e., relation
 * columns are never hidden by default. If the operator ever wants to hide
 * `series` from a role they should add an explicit entry for the alias in
 * `config/field_permissions.php`.
 *
 * ## super_admin bypass
 *
 * {@see FieldPermissions::canRead()} already hard-codes the super_admin
 * escape-hatch. No additional logic is needed here.
 */
trait FiltersExportColumns
{
    /**
     * Return only the columns the current user's role is allowed to read.
     *
     * @param array<string, string> $columns ['fieldKey' => 'Header label']
     * @return array<string, string> subset in original order
     */
    private static function visibleExportColumns(array $columns): array
    {
        return array_filter(
            $columns,
            static fn (string $label, string $fieldKey): bool => FieldPermissions::canRead('document', $fieldKey),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
