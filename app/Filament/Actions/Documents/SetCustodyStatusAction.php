<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Filament\Support\SearchableSelects;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Client 2026-11-05, "Move document/s from a box to different Custody Status: If
 * Not in Box, Conservation Box, Mounted; Not in Box – Location of the Document
 * should also change".
 *
 * The document leaves its box: custody status and location are set, the box is
 * cleared, the move goes in the chain of custody as a {@see BoxMovement} with no
 * target. Its batch stays: it is where the document was catalogued. Moving back
 * into a box is {@see MoveToBoxAction}.
 */
final class SetCustodyStatusAction
{
    public static function make(string $name = 'setCustodyStatus'): Action
    {
        return Action::make($name)
            ->label('Move out of box')
            ->icon('heroicon-o-archive-box-x-mark')
            ->color('primary')
            ->modalHeading('Move this document out of its box')
            ->form(self::form())
            ->action(function (Document $record, array $data): void {
                self::perform(ActionSupport::asCollection($record), $data);
            })
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    public static function bulk(string $name = 'bulkSetCustodyStatus'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Move out of box')
            ->icon('heroicon-o-archive-box-x-mark')
            ->color('primary')
            ->modalHeading('Move selected documents out of their boxes')
            ->form(self::form())
            ->action(function (EloquentCollection $records, array $data): void {
                self::perform($records, $data);
            })
            ->deselectRecordsAfterCompletion()
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    /**
     * @return array<string, string>
     */
    private static function statuses(): array
    {
        return array_diff_key(Document::custodyLabels(), ['in_box' => true]);
    }

    /**
     * @return array<int, Component>
     */
    private static function form(): array
    {
        return [
            Select::make('custody_status')
                ->label('Custody Status')
                ->options(fn (): array => self::statuses())
                ->searchable()
                ->required(),
            SearchableSelects::locationPicker('to_location_id', fn ($q) => $q->where('is_active', true))
                ->label('Location')
                ->required(),
        ];
    }

    /**
     * @param EloquentCollection<int, Document> $records
     * @param array<string, mixed> $data
     */
    private static function perform(EloquentCollection $records, array $data): void
    {
        $status = (string) ($data['custody_status'] ?? '');
        $statuses = self::statuses();
        if (! array_key_exists($status, $statuses)) {
            Notification::make()
                ->title('Cannot move — choose a custody status other than In box')
                ->danger()->send();

            return;
        }

        $location = ActionSupport::findActiveLocation((int) ($data['to_location_id'] ?? 0));
        if ($location === null) {
            Notification::make()
                ->title('Cannot move — location not found or not active')
                ->danger()->send();

            return;
        }

        $result = ActionSupport::performBulk(
            $records,
            function (Document $doc) use ($status, $statuses, $location): void {
                ActionSupport::assertLocationFits($location, $doc);

                $from = $doc->current_box_id;
                $doc->custody_status = $status;
                $doc->location_id = $location->getKey();
                $doc->current_box_id = null;
                $doc->save();

                if ($from !== null) {
                    ActionSupport::recordLeftBox($doc, (int) $from, 'Moved out of box: ' . $statuses[$status]);
                }
            },
        );

        ActionSupport::notifyBulkResult(
            $result,
            successVerb: "moved out of box: {$statuses[$status]}, '{$location->name}'",
            failedTitle: 'Move failed',
        );
    }
}
