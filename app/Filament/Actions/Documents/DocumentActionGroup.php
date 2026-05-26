<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;

/**
 * Aggregates the 15 Document power-actions into ready-to-spread arrays for
 * the page-level mount points (ListDocuments header / bulkActions,
 * EditDocument header, ViewDocument header).
 *
 * Centralising the wiring here keeps the page classes terse and prevents
 * drift: when a new action is added, the resource files only declare
 * "use DocumentActionGroup" once, not 15 use statements.
 */
final class DocumentActionGroup
{
    /**
     * Single-record header actions used by EditDocument / ViewDocument.
     *
     * Order is tuned for the operator workflow: most-common actions first
     * (move-to-box / move-to-batch) and destructive ones (PERM_OUT,
     * identifier change) last.
     *
     * @return array<int, Action>
     */
    public static function singleHeaderActions(): array
    {
        return [
            MoveToBoxAction::make(),
            MoveToBatchAction::make(),
            SetLocationAction::make(),
            MarkDisinfestedAction::make(),
            SetSeriesAction::make(),
            UpdateDocumentTypeAction::make(),
            AssignAuthorityAction::make(),
            ReplaceAuthorityAction::make(),
            DetachAuthorityAction::make(),
            AddFlagAction::make(),
            MoveToWillsAction::make(),
            UpdateIdentifierAction::make(),
            MarkPermOutAction::make(),
        ];
    }

    /**
     * Bulk actions for the ListDocuments page table.
     *
     * @return array<int, BulkAction>
     */
    public static function bulkActions(): array
    {
        return [
            MoveToBoxAction::bulk(),
            MoveToBatchAction::bulk(),
            SetLocationAction::bulk(),
            MarkDisinfestedAction::bulk(),
            SetSeriesAction::bulk(),
            UpdateDocumentTypeAction::bulk(),
            AssignAuthorityAction::bulk(),
            ReplaceAuthorityAction::bulk(),
            DetachAuthorityAction::bulk(),
            AddFlagAction::bulk(),
            MoveToWillsAction::bulk(),
            MarkPermOutAction::bulk(),
            ExportSelectedAction::bulk(),
            MoveToRepositoryAction::bulk(),
        ];
    }
}
