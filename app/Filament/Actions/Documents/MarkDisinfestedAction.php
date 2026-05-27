<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

/**
 * Action #5 — Mark document(s) as disinfested with an explicit date.
 *
 * The DatePicker defaults to today() and refuses future dates (an archive
 * cannot pre-record a disinfestation that has not happened yet). This is a
 * prerequisite for Action #6 (PERM_OUT) per RFQ App.1 #5.
 *
 * Defence-in-depth (review M-7): the form-level `->maxDate(now())` is
 * enforced by Filament's validation pipeline, but {@see self::perform()}
 * re-checks server-side so programmatic invocations (test helpers, queued
 * jobs, future internal callers) cannot stamp a future date.
 */
final class MarkDisinfestedAction
{
    public static function make(string $name = 'markDisinfested'): Action
    {
        return Action::make($name)
            ->label('Mark disinfested')
            ->icon('heroicon-o-shield-check')
            ->color('success')
            ->modalHeading('Mark this document as disinfested')
            ->form(self::form())
            ->action(function (Document $record, array $data): void {
                self::perform(ActionSupport::asCollection($record), $data);
            })
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    public static function bulk(string $name = 'bulkMarkDisinfested'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Mark disinfested')
            ->icon('heroicon-o-shield-check')
            ->color('success')
            ->modalHeading('Mark selected documents as disinfested')
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
            DatePicker::make('disinfestation_date')
                ->label('Disinfestation date')
                ->required()
                ->default(now()->toDateString())
                ->maxDate(now())
                ->helperText('No future dates — record the actual fumigation date.'),
        ];
    }

    /**
     * @param EloquentCollection<int, Document> $records
     * @param array<string, mixed> $data
     */
    private static function perform(EloquentCollection $records, array $data): void
    {
        $date = $data['disinfestation_date'] ?? null;
        if ($date === null) {
            Notification::make()
                ->title('Disinfestation date is required')
                ->danger()->send();

            return;
        }

        // Defence-in-depth re-check beyond the form-level maxDate guard.
        if (Carbon::parse($date)->isFuture()) {
            Notification::make()
                ->title('Disinfestation date cannot be in the future')
                ->danger()->send();

            return;
        }

        $result = ActionSupport::performBulk(
            $records,
            function (Document $doc) use ($date): void {
                // Capture old values BEFORE writing — they go into the audit
                // diff so the "Mark disinfested" event is queryable end-to-end
                // (date stamped, in-flight flag cleared, barcode back to IN).
                $oldValues = [
                    'disinfestation_date' => optional($doc->getOriginal('disinfestation_date'))->toString() ?? $doc->getOriginal('disinfestation_date'),
                    'is_in_disinfestation' => (bool) $doc->getOriginal('is_in_disinfestation'),
                    'barcode_status' => $doc->getOriginal('barcode_status'),
                ];

                $doc->disinfestation_date = $date;
                // The bulk workflow ("Send to disinfestation" → fumigation →
                // "Mark disinfested") expects this action to be the closing
                // bookend: clear the in-flight flag and return barcode to IN.
                $doc->is_in_disinfestation = false;
                $doc->barcode_status = 'IN';

                ActionSupport::logPivotChange(
                    document: $doc,
                    event: 'document.marked_disinfested',
                    newValues: [
                        'disinfestation_date' => $date,
                        'is_in_disinfestation' => false,
                        'barcode_status' => 'IN',
                    ],
                    oldValues: $oldValues,
                    tags: 'disinfestation,document',
                );

                $doc->save();
            },
        );

        ActionSupport::notifyBulkResult(
            $result,
            successVerb: "marked disinfested on {$date}",
            failedTitle: 'Failed to mark disinfested',
        );
    }
}
