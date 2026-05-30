<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Action #16 — Send document(s) to disinfestation.
 *
 * Opens the "currently in disinfestation" workflow window for the selected
 * rows: marks the document as physically out for fumigation and flips the
 * barcode status to OUT so the operator can filter, track, and (when the
 * batch is returned from the chamber) bulk-close the cycle via
 * {@see MarkDisinfestedAction} — which stamps `disinfestation_date`,
 * clears `is_in_disinfestation`, and restores `barcode_status = 'IN'`.
 *
 * Idempotent per row: documents already flagged `is_in_disinfestation = true`
 * are silently skipped (no double-send, no spurious audit row, no error).
 *
 * Audit trail mirrors MarkDisinfestedAction: a manual audit row with
 * `event = document.sent_to_disinfestation` records the diff of the two
 * changed columns. The model's own Auditable trail also picks up the column
 * diff, but the explicit event/tag makes the lifecycle queryable.
 */
final class SendToDisinfestationAction
{
    public static function make(string $name = 'sendToDisinfestation'): Action
    {
        return Action::make($name)
            ->label('Send to disinfestation')
            ->icon('heroicon-o-paper-airplane')
            ->color('warning')
            ->modalHeading('Send this document to disinfestation')
            ->modalDescription('Marks the document as physically out for fumigation and sets its barcode status to OUT. Run "Mark disinfested" when the document returns.')
            ->requiresConfirmation()
            ->action(function (Document $record): void {
                self::perform(ActionSupport::asCollection($record));
            })
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    public static function bulk(string $name = 'bulkSendToDisinfestation'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Send to disinfestation')
            ->icon('heroicon-o-paper-airplane')
            ->color('warning')
            ->modalHeading('Send selected documents to disinfestation')
            ->modalDescription('Marks the selected documents as physically out for fumigation and sets their barcode status to OUT. Run "Mark disinfested" when the documents return.')
            ->requiresConfirmation()
            ->action(function (EloquentCollection $records): void {
                self::perform($records);
            })
            ->deselectRecordsAfterCompletion()
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    /**
     * @param EloquentCollection<int, Document> $records
     */
    private static function perform(EloquentCollection $records): void
    {
        if ($records->isEmpty()) {
            Notification::make()->title('No documents selected')->warning()->send();

            return;
        }

        $result = ActionSupport::performBulk(
            $records,
            function (Document $doc): void {
                // Idempotency: a document already in the chamber is silently
                // skipped. We don't surface this as a per-row error because
                // it's the natural outcome of an operator selecting a mixed
                // batch (some already sent, some not) and the safe thing is
                // a no-op. Counted as "ok" because nothing needed doing.
                if ((bool) $doc->is_in_disinfestation === true) {
                    return;
                }

                $oldValues = [
                    'is_in_disinfestation' => (bool) $doc->getOriginal('is_in_disinfestation'),
                    'barcode_status' => $doc->getOriginal('barcode_status'),
                ];

                $doc->is_in_disinfestation = true;

                // Task 7 (B1): the BOX is authoritative for barcode status.
                // Set OUT on the box (mirror hook propagates to its documents);
                // fall back to the document column when there is no box.
                ActionSupport::applyBarcodeStatus($doc, 'OUT');

                ActionSupport::logPivotChange(
                    document: $doc,
                    event: 'document.sent_to_disinfestation',
                    newValues: [
                        'is_in_disinfestation' => true,
                        'barcode_status' => 'OUT',
                    ],
                    oldValues: $oldValues,
                    tags: 'disinfestation,document',
                );

                $doc->save();
            },
        );

        ActionSupport::notifyBulkResult(
            $result,
            successVerb: 'sent to disinfestation',
            failedTitle: 'Send to disinfestation failed',
        );
    }
}
