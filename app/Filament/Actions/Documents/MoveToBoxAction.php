<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Filament\Support\SearchableSelects;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxMovement;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Action #1 — Move document(s) to a target Box.
 *
 * Validates that the target Box is assignable (not soft-deleted, not
 * PERM_OUT) and writes a {@see BoxMovement} row for each document so the
 * physical chain of custody is preserved (RFQ §3.1.5).
 *
 * Per-row atomicity (review C-2): each row runs inside its own
 * `DB::transaction()` via {@see ActionSupport::performBulk()}; a failure
 * partway through a row's writes (document save + BoxMovement insert) rolls
 * back that row only — previously-successful rows are preserved. The
 * operator gets a partial-success notification listing the failures.
 *
 * Multi-tenant safety (review C-3): the target box must belong to a Batch
 * in the same repository as the document. Otherwise the action refuses
 * the row (no cross-tenant writes from privileged users picking a foreign
 * target via the Select).
 *
 * Invariant safety (review H-5): the target box must have a non-null
 * `batch_id`. Assigning a document to an orphan box would break the
 * `documents.batch_id ↔ documents.currentBox.batch_id` invariant.
 *
 * Both the single-record and bulk variants share the same form (target box
 * + optional reason) and the same writer body — only the way `records` is
 * sourced differs.
 */
final class MoveToBoxAction
{
    public static function make(string $name = 'moveToBox'): Action
    {
        return Action::make($name)
            ->label('Move to box')
            ->icon('heroicon-o-archive-box-arrow-down')
            ->color('primary')
            ->modalHeading('Move document to a different box')
            ->form(self::form())
            ->action(function (Document $record, array $data): void {
                self::perform(ActionSupport::asCollection($record), $data);
            })
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    public static function bulk(string $name = 'bulkMoveToBox'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Move to box')
            ->icon('heroicon-o-archive-box-arrow-down')
            ->color('primary')
            ->modalHeading('Move selected documents to a different box')
            ->form(self::form())
            ->action(function (EloquentCollection $records, array $data): void {
                self::perform($records, $data);
            })
            ->deselectRecordsAfterCompletion()
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    /**
     * @return array<int, Component>
     */
    private static function form(): array
    {
        return [
            SearchableSelects::boxFiltered(
                'to_box_id',
                'currentBox',
                fn ($q) => $q->whereNull('destroyed_at')->where('barcode_status', '!=', 'PERM_OUT'),
            )
                ->label('Target box')
                ->required(),
            Textarea::make('reason')
                ->label('Reason (optional)')
                ->maxLength(255)
                ->rows(2)
                ->placeholder('Why is this document being moved?'),
        ];
    }

    /**
     * @param EloquentCollection<int, Document> $records
     * @param array<string, mixed> $data
     */
    private static function perform(EloquentCollection $records, array $data): void
    {
        $targetBoxId = (int) ($data['to_box_id'] ?? 0);
        $reason = $data['reason'] ?? null;

        /** @var Box|null $targetBox */
        $targetBox = Box::query()->find($targetBoxId);
        if ($targetBox === null) {
            Notification::make()
                ->title('Cannot move — target box not found')
                ->body('The selected box was deleted or is no longer accessible.')
                ->danger()
                ->send();

            return;
        }

        if ($targetBox->trashed() || $targetBox->barcode_status === 'PERM_OUT') {
            Notification::make()
                ->title('Cannot move — target box is not assignable')
                ->body('The selected box is destroyed or permanently transferred out.')
                ->danger()
                ->send();

            return;
        }

        // H-5: an orphan box (no batch) would silently break the
        // documents.batch_id ↔ boxes.batch_id invariant. Refuse explicitly.
        if ($targetBox->batch_id === null) {
            Notification::make()
                ->title('Cannot move — target box has no batch assignment')
                ->body('Assign a batch to the box first, then retry. Documents must always inherit the box\'s batch.')
                ->danger()
                ->send();

            return;
        }

        // Resolve the box's owning batch via the typed Batch model so
        // PHPStan can see the `repository_id` attribute (the BelongsTo
        // accessor returns Model|null, which level-6 rejects).
        $targetBoxBatch = Batch::withoutGlobalScopes()->find($targetBox->batch_id);
        $targetBoxRepoId = $targetBoxBatch?->repository_id;

        $result = ActionSupport::performBulk(
            $records,
            function (Document $doc) use ($targetBox, $targetBoxRepoId, $reason): void {
                // C-3: per-row tenant gate. Privileged users (super_admin,
                // admin) bypass the Box Select's RepositoryScope, so we MUST
                // re-check here. SetLocationAction / MoveToWillsAction
                // enforce the same invariant.
                if ($targetBoxRepoId !== null
                    && (int) $targetBoxRepoId !== (int) $doc->repository_id) {
                    throw new \DomainException(
                        'target box belongs to a different repository'
                    );
                }

                $from = $doc->current_box_id;
                $doc->current_box_id = $targetBox->getKey();
                // H-5 (covered above): targetBox->batch_id is guaranteed
                // non-null at this point.
                $doc->batch_id = $targetBox->batch_id;
                $doc->save();

                BoxMovement::create([
                    'document_id' => $doc->getKey(),
                    'repository_id' => $targetBoxRepoId,
                    'from_box_id' => $from,
                    'to_box_id' => $targetBox->getKey(),
                    'movement_date' => now(),
                    'reason' => $reason,
                    'user_id' => auth()->id(),
                ]);
            },
        );

        ActionSupport::notifyBulkResult(
            $result,
            successVerb: "moved to Box {$targetBox->box_number}",
            failedTitle: 'Move failed',
        );
    }
}
