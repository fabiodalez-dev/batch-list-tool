<?php

declare(strict_types=1);

namespace App\Filament\Actions\Boxes;

use App\Filament\Support\SearchableSelects;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Series;
use Filament\Actions\BulkAction;
use Illuminate\Support\Collection;

/**
 * Client 2026-11-05: "Change Batch number of multiple boxes". The box moves, and
 * the documents in it with it: a document in a box belongs to the box's batch
 * (Document::syncCustodyFromCurrentBox), archived documents included.
 *
 * Refused per box: a batch of another repository, a type and number the batch
 * already has, and non-wills documents going into Batch 50. The documents move
 * with one UPDATE, which does not run the model's wills guard, so that rule is
 * asked here; an audit row per document keeps the trail the trait would write.
 */
final class ChangeBoxBatchAction
{
    public static function bulk(string $name = 'changeBatch'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Change batch')
            ->icon('heroicon-o-rectangle-stack')
            ->modalHeading('Change the batch of the selected boxes')
            ->modalDescription('The documents in each box move to the new batch with it.')
            ->form([
                SearchableSelects::batch('to_batch_id', 'batch')
                    ->label('New batch')
                    ->required(),
            ])
            ->authorize(fn (): bool => (bool) (auth()->user()?->can('update_box') && auth()->user()->can('update_document')))
            ->fetchSelectedRecords(false)
            ->action(function (Collection $records, array $data): void {
                self::perform($records, $data);
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * @param Collection<int, mixed> $records
     * @param array<string, mixed> $data
     */
    private static function perform(Collection $records, array $data): void
    {
        // Through the scoped query: a batch of a repository the user is not in
        // reads the same as one that does not exist.
        /** @var Batch|null $batch */
        $batch = Batch::query()->whereKey((int) ($data['to_batch_id'] ?? 0))->where('is_active', true)->first();
        if ($batch === null) {
            BoxBulkSupport::stop('Batch not changed: the batch was not found or is not active.');

            return;
        }
        if (in_array((int) $batch->batch_number, Batch::FORBIDDEN_NUMBERS, true)) {
            BoxBulkSupport::stop("Batch not changed: batch {$batch->batch_number} is reserved and cannot be used (RFQ App.1 #1).");

            return;
        }

        $result = BoxBulkSupport::run($records, static function (Box $box) use ($batch): void {
            self::moveBox($box, $batch);
        });

        BoxBulkSupport::notify($result, "moved to batch {$batch->batch_number}", 'Batch not changed');
    }

    private static function moveBox(Box $box, Batch $batch): void
    {
        if ((int) $box->batch_id === (int) $batch->getKey()) {
            throw new \DomainException("Already in batch {$batch->batch_number}.");
        }
        if ((int) $box->effectiveRepositoryId() !== (int) $batch->repository_id) {
            throw new \DomainException('The batch belongs to a different repository than the box.');
        }
        if (Box::identityClash((int) $batch->getKey(), null, $box->box_type, $box->box_number, $box->getKey()) !== null) {
            throw new \DomainException("Batch {$batch->batch_number} already has a box with this type and number.");
        }

        if ((int) $batch->batch_number === Batch::WILLS_BATCH) {
            $wills = Series::withoutGlobalScopes()->where('is_wills_series', true)->pluck('id')->all();
            $notWills = BoxBulkSupport::documentsIn($box)
                ->where(static fn ($q) => $q->whereNull('series_id')->orWhereNotIn('series_id', $wills))
                ->count();
            if ($notWills > 0) {
                throw new \DomainException('Batch ' . Batch::WILLS_BATCH . ' is reserved for wills, and the box holds documents without a wills series.');
            }
        }

        $moved = Document::withoutGlobalScopes()
            ->where('current_box_id', $box->getKey())
            ->where(static fn ($q) => $q->whereNull('batch_id')->orWhere('batch_id', '!=', $batch->getKey()))
            ->toBase()
            ->get(['id', 'batch_id']);

        // Each batch numbers its boxes on its own (Box::buildSortQuery()).
        $box->sort_order = (int) Box::withoutGlobalScopes()->where('batch_id', $batch->getKey())->max('sort_order') + 1;
        $box->batch_id = $batch->getKey();
        $box->save();

        Document::withoutGlobalScopes()
            ->where('current_box_id', $box->getKey())
            ->update(['batch_id' => $batch->getKey()]);

        BoxBulkSupport::auditDocuments(
            $moved->map(static fn (object $document): array => [
                'id' => (int) $document->id,
                'old' => ['batch_id' => $document->batch_id],
                'new' => ['batch_id' => $batch->getKey()],
            ])->all(),
            'document.moved_with_box',
            'batch,document',
        );
    }
}
