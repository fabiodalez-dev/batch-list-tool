<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Filament\Support\SearchableSelects;
use App\Models\Document;
use App\Models\Series;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Action #10 — Reclassify document(s) into a different Series.
 *
 * If the new series is a wills series (Series::is_wills_series, or code
 * starting with RWL/OWL) the operator is warned via the notification body
 * that they may also want to move the docs to Batch 50 — but the action
 * stays focused (no implicit batch move), keeping the unit-of-work tight.
 * Use the dedicated "Move to wills" composite action (#13) for the combined
 * intent.
 */
final class SetSeriesAction
{
    public static function make(string $name = 'setSeries'): Action
    {
        return Action::make($name)
            ->label('Set series')
            ->icon('heroicon-o-tag')
            ->color('primary')
            ->modalHeading('Reclassify this document into a different series')
            ->form(self::form())
            ->action(function (Document $record, array $data): void {
                self::perform(ActionSupport::asCollection($record), $data);
            })
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    public static function bulk(string $name = 'bulkSetSeries'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Set series')
            ->icon('heroicon-o-tag')
            ->color('primary')
            ->modalHeading('Reclassify selected documents into a different series')
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
            SearchableSelects::series('to_series_id')
                ->label('Target series')
                ->required(),
        ];
    }

    /**
     * @param EloquentCollection<int, Document> $records
     * @param array<string, mixed> $data
     */
    private static function perform(EloquentCollection $records, array $data): void
    {
        $seriesId = (int) ($data['to_series_id'] ?? 0);

        /** @var Series|null $series */
        $series = Series::query()->find($seriesId);
        if ($series === null || $series->trashed()) {
            Notification::make()
                ->title('Cannot set series — series not found')
                ->danger()->send();

            return;
        }

        $result = ActionSupport::performBulk(
            $records,
            function (Document $doc) use ($series): void {
                $doc->series_id = $series->getKey();
                $doc->save();
            },
        );

        $isWillsSeries = (bool) ($series->is_wills_series ?? false)
            || str_starts_with(strtoupper((string) $series->code), 'RWL')
            || str_starts_with(strtoupper((string) $series->code), 'OWL');

        $willsHint = $isWillsSeries
            ? " — these documents may belong in Batch 50 (wills). Use 'Move to wills' to relocate them."
            : '';

        ActionSupport::notifyBulkResult(
            $result,
            successVerb: "reclassified into '{$series->code}'{$willsHint}",
            failedTitle: 'Reclassification failed',
        );
    }
}
