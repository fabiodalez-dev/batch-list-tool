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
use Illuminate\Support\Facades\DB;

/**
 * Action #13 — Composite "Move to wills".
 *
 * Combines: move to Batch 50 + ensure series is a wills series (RWL or OWL,
 * preferring whatever wills series the documents already use, otherwise
 * defaulting to RWL). The repository-scoped Batch 50 is auto-created if
 * missing (each tenant has its own #50 row).
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

        $ok = 0;
        $errors = [];

        DB::transaction(function () use ($records, &$ok, &$errors): void {
            foreach ($records as $doc) {
                /** @var Document $doc */
                try {
                    // Tenant-scoped Batch 50 lookup (RFQ §3.5.1 — each repo has
                    // its own Batch 50 row).
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
                        if ($rwl !== null) {
                            $doc->series_id = $rwl->getKey();
                        }
                    }

                    // Clear the box pointer — it almost certainly belongs to a
                    // different batch now.
                    $doc->current_box_id = null;

                    $doc->save();
                    $ok++;
                } catch (\Throwable $e) {
                    $errors[] = "#{$doc->identifier}: {$e->getMessage()}";
                }
            }
        });

        if ($errors === [] && $ok > 0) {
            Notification::make()
                ->title("{$ok} document(s) moved to Batch 50 (wills)")
                ->success()->send();

            return;
        }

        if ($ok > 0) {
            Notification::make()
                ->title("Partial: {$ok} moved, " . count($errors) . ' failed')
                ->body(implode("\n", array_slice($errors, 0, 5)))
                ->warning()->send();

            return;
        }

        Notification::make()
            ->title('Move to wills failed')
            ->body(implode("\n", array_slice($errors, 0, 5)) ?: 'No documents updated.')
            ->danger()->send();
    }
}
