<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Models\Box;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Action #6 — Mark document(s) as PERM_OUT (permanently transferred out).
 *
 * RFQ App.1 #5 — a document cannot be marked PERM_OUT unless its
 * `disinfestation_date` is already set.
 *
 * Strict bulk semantics (review H-2): the modal warns "Each document must
 * already have a disinfestation date" and operators expect an all-or-
 * nothing precondition check. We pre-validate ALL selected rows; if ANY
 * row lacks `disinfestation_date`, the whole bulk is aborted with a
 * danger notification listing the offending identifiers. The operator
 * fixes the missing disinfestations (via Action #5) and retries.
 *
 * Column write (review H-1): `documents.barcode_status` is added by the
 * 2026_05_28_140100 migration and writes 'PERM_OUT' onto the column AND
 * an audit row tagged `permout_marked`. The list view's barcode_status
 * filter / column now reflects the PERM_OUT state immediately, instead
 * of the previous audit-trail-only behaviour that left the document
 * visually unchanged.
 */
final class MarkPermOutAction
{
    public static function make(string $name = 'markPermOut'): Action
    {
        return Action::make($name)
            ->label('Mark PERM_OUT')
            ->icon('heroicon-o-archive-box-x-mark')
            ->color('danger')
            ->modalHeading('Permanently transfer out')
            ->modalDescription('This is permanent. The document must already have a disinfestation date.')
            ->requiresConfirmation()
            ->action(function (Document $record): void {
                self::perform(ActionSupport::asCollection($record));
            })
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    public static function bulk(string $name = 'bulkMarkPermOut'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Mark PERM_OUT')
            ->icon('heroicon-o-archive-box-x-mark')
            ->color('danger')
            ->modalHeading('Permanently transfer out selected documents')
            ->modalDescription('This is permanent. ALL selected documents must already have a disinfestation date — if any do not, the whole bulk operation is aborted.')
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

        // H-2: strict precondition. Filter rows that lack the prerequisite
        // disinfestation_date; if any are found, abort the whole bulk.
        $missing = $records->filter(fn (Document $d): bool => $d->disinfestation_date === null);
        if ($missing->isNotEmpty()) {
            $identifiers = $missing->take(5)->pluck('identifier')->implode(', ');
            $extra = $missing->count() > 5 ? ' (… and ' . ($missing->count() - 5) . ' more)' : '';
            Notification::make()
                ->title("Aborted: {$missing->count()} document(s) lack a disinfestation date")
                ->body("Mark them disinfested first, then retry.\nOffending: {$identifiers}{$extra}")
                ->danger()
                ->send();

            return;
        }

        $result = ActionSupport::performBulk(
            $records,
            function (Document $doc): void {
                $previousStatus = $doc->getOriginal('barcode_status');

                // Task 7 (B1): the BOX is authoritative for barcode status.
                // PERM_OUT therefore lands on the box (mirror hook propagates
                // it to every document in the box). A1.2 at box: a box can only
                // be PERM_OUT if it carries a disinfestation_date — so if the
                // box lacks one, seed it from the document's date (already
                // validated non-null by the precondition above) before
                // flipping the status. Falls back to the document column when
                // the document has no current box.
                $box = $doc->current_box_id !== null
                    ? Box::query()->find($doc->current_box_id)
                    : null;
                if ($box instanceof Box) {
                    if ($box->disinfestation_date === null) {
                        $box->disinfestation_date = $doc->disinfestation_date;
                    }
                    if ($box->barcode_status !== 'PERM_OUT') {
                        $box->barcode_status = 'PERM_OUT';
                    }
                    $box->save();
                } else {
                    // No current box — write the document column directly
                    // (the document-level A1.2 guard still applies on save()).
                    $doc->setAttribute('barcode_status', 'PERM_OUT');
                }

                // Explicit audit row in addition to the model's column-diff
                // Auditable trail — gives a more descriptive `event`
                // ('permout_marked') and a queryable tag for filtering.
                ActionSupport::logPivotChange(
                    document: $doc,
                    event: 'permout_marked',
                    newValues: ['barcode_status' => 'PERM_OUT'],
                    oldValues: ['barcode_status' => $previousStatus],
                    tags: 'permout,document',
                );

                $doc->save();
            },
        );

        ActionSupport::notifyBulkResult(
            $result,
            successVerb: 'marked PERM_OUT',
            failedTitle: 'Mark PERM_OUT failed',
        );
    }
}
