<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Models\Batch;
use App\Models\Document;
use App\Models\Series;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Action #13 — Composite "Move to wills".
 *
 * Combines: move to Batch 50 + ensure series is a wills series (RWL or OWL,
 * preferring whatever wills series the documents already use, otherwise
 * defaulting to RWL). The repository-scoped Batch 50 is auto-created if
 * missing (each tenant has its own #50 row).
 *
 * Schema dependency (review C-1): the per-tenant Batch 50 lookup requires
 * `(batch_number, repository_id)` to be unique-per-repo, not globally
 * unique. The 2026_05_28_140000 migration converts the constraint.
 *
 * RFQ App.1 #2 — Batch 50 is exclusively for wills.
 */
final class MoveToWillsAction
{
    public static function make(string $name = 'moveToWills'): Action
    {
        return Action::make($name)
            ->label('Move to wills (Batch 50)')
            ->icon('heroicon-o-scale')
            ->color('warning')
            ->modalHeading('Move this document to Batch 50 (wills)')
            ->modalDescription('Reassigns to Batch 50 and ensures the series is a wills series (RWL).')
            ->requiresConfirmation()
            ->action(function (Document $record): void {
                self::perform(ActionSupport::asCollection($record));
            })
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    public static function bulk(string $name = 'bulkMoveToWills'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Move to wills (Batch 50)')
            ->icon('heroicon-o-scale')
            ->color('warning')
            ->modalHeading('Move selected documents to Batch 50 (wills)')
            ->modalDescription('Reassigns to Batch 50 and ensures the series is a wills series (RWL).')
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
                // Tenant-scoped Batch 50 lookup (RFQ §3.5.1 — each repo has
                // its own Batch 50 row). The schema constraint is the
                // composite unique `(batch_number, repository_id)` — see
                // migration 2026_05_28_140000.
                $batch = Batch::withoutGlobalScopes()
                    ->where('batch_number', Batch::WILLS_BATCH)
                    ->where('repository_id', $doc->repository_id)
                    ->first();

                if ($batch === null) {
                    // Auto-create the wills batch for this tenant.
                    $batch = Batch::withoutGlobalScopes()->create([
                        'batch_number' => Batch::WILLS_BATCH,
                        'type' => 'NOTARY_ACCESSION',
                        'description' => 'Wills (auto-created by Move to wills action)',
                        'repository_id' => $doc->repository_id,
                        'is_active' => true,
                    ]);
                }

                $doc->batch_id = $batch->getKey();

                // Ensure series is a wills series.
                $currentSeries = $doc->series_id !== null
                    ? Series::query()->find($doc->series_id)
                    : null;
                $isWillsSeries = $currentSeries !== null
                    && ((bool) ($currentSeries->is_wills_series ?? false)
                        || str_starts_with(strtoupper((string) $currentSeries->code), 'RWL')
                        || str_starts_with(strtoupper((string) $currentSeries->code), 'OWL'));
                if (! $isWillsSeries) {
                    $rwl = Series::query()
                        ->where('code', 'like', 'RWL%')
                        ->orWhere('is_wills_series', true)
                        ->orderBy('code')
                        ->first();
                    // Guarantee the invariant this action exists to enforce
                    // (Batch 50 = wills only, RFQ App.1 #2): if the catalogue
                    // has no wills series yet, provision the canonical RWL one
                    // — mirrors the auto-create of Batch 50 above. Without this
                    // the document would land in Batch 50 as a non-wills doc,
                    // which the Document model guard correctly rejects.
                    if ($rwl === null) {
                        $rwl = Series::query()->firstOrCreate(
                            ['code' => 'RWL'],
                            [
                                'title' => 'Registers Private Practice Public Wills',
                                'is_wills_series' => true,
                                'is_active' => true,
                            ],
                        );
                    }
                    $doc->series_id = $rwl->getKey();
                }

                // Clear the box pointer — it almost certainly belongs to a
                // different batch now.
                $doc->current_box_id = null;

                $doc->save();
            },
        );

        ActionSupport::notifyBulkResult(
            $result,
            successVerb: 'moved to Batch 50 (wills)',
            failedTitle: 'Move to wills failed',
        );
    }
}
