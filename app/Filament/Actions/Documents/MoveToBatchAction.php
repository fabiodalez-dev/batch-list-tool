<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Filament\Support\SearchableSelects;
use App\Models\Batch;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Action #2 — Move document(s) to a target Batch.
 *
 * RFQ App.1 #1 forbids batch numbers 33/34/36; the action refuses any target
 * batch whose `batch_number` is in {@see Batch::FORBIDDEN_NUMBERS}.
 *
 * Multi-tenant safety (review C-3): the target Batch must belong to the
 * same repository as the document. Privileged users (super_admin / admin)
 * bypass the Filament Select's RepositoryScope, so we enforce per-row.
 *
 * If the new batch differs from the current box's batch, the `current_box_id`
 * is cleared (the operator must re-assign a box from the new batch via the
 * dedicated "Move to box" action) — leaving a stale box pointer would
 * silently break the documents.batch_id ↔ boxes.batch_id invariant.
 */
final class MoveToBatchAction
{
    public static function make(string $name = 'moveToBatch'): Action
    {
        return Action::make($name)
            ->label('Move to batch')
            ->icon('heroicon-o-rectangle-stack')
            ->color('primary')
            ->modalHeading('Move document to a different batch')
            ->form(self::form())
            ->action(function (Document $record, array $data): void {
                self::perform(ActionSupport::asCollection($record), $data);
            })
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    public static function bulk(string $name = 'bulkMoveToBatch'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Move to batch')
            ->icon('heroicon-o-rectangle-stack')
            ->color('primary')
            ->modalHeading('Move selected documents to a different batch')
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
            SearchableSelects::batch('to_batch_id', 'batch')
                ->label('Target batch')
                ->required(),
            Toggle::make('clear_current_box')
                ->label('Clear current box assignment')
                ->helperText('Box assignment is cleared when the new batch differs from the existing box\'s batch.')
                ->default(true),
        ];
    }

    /**
     * @param EloquentCollection<int, Document> $records
     * @param array<string, mixed> $data
     */
    private static function perform(EloquentCollection $records, array $data): void
    {
        $targetBatchId = (int) ($data['to_batch_id'] ?? 0);
        $clearBox = (bool) ($data['clear_current_box'] ?? true);

        /** @var Batch|null $targetBatch */
        $targetBatch = Batch::withoutGlobalScopes()->find($targetBatchId);
        if ($targetBatch === null) {
            Notification::make()
                ->title('Cannot move — target batch not found')
                ->danger()->send();

            return;
        }

        if (in_array((int) $targetBatch->batch_number, Batch::FORBIDDEN_NUMBERS, true)) {
            Notification::make()
                ->title('Cannot move — batch number is reserved')
                ->body("Batch {$targetBatch->batch_number} is reserved per RFQ App.1 #1 and cannot accept new documents.")
                ->danger()->send();

            return;
        }

        $result = ActionSupport::performBulk(
            $records,
            function (Document $doc) use ($targetBatch, $clearBox): void {
                // C-3: per-row tenant gate.
                throw_if((int) $targetBatch->repository_id !== (int) $doc->repository_id, \DomainException::class, 'target batch belongs to a different repository');

                $doc->batch_id = $targetBatch->getKey();
                if ($clearBox && $doc->current_box_id !== null) {
                    $doc->current_box_id = null;
                }
                $doc->save();
            },
        );

        ActionSupport::notifyBulkResult(
            $result,
            successVerb: "moved to Batch {$targetBatch->batch_number}",
            failedTitle: 'Move failed',
        );
    }
}
