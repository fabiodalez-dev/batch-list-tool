<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Models\Document;
use App\Models\Location;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * RFQ Appendix-2 §vi — guided action to mark a document as being on
 * museum display: set a museum Location (`type IN (showcase, museum)`)
 * AND stamp the free-text `museum_reference` in a single confirmed
 * step. Previously these were two separate edits in the form, which
 * was easy to do half-right.
 *
 * The modal limits the Location dropdown to entries of type museum or
 * showcase to keep mistakes (selecting a generic "Archive" location)
 * out of reach.
 */
final class SetMuseumLocationAction
{
    private const MUSEUM_LOCATION_TYPES = ['museum', 'showcase'];

    public static function make(string $name = 'setMuseumLocation'): Action
    {
        return Action::make($name)
            ->label('Send to museum')
            ->icon('heroicon-o-building-library')
            ->color('warning')
            ->modalHeading('Mark this document as on museum display')
            ->modalDescription('Pin the document to a museum / showcase location and record the museum reference code in one step.')
            ->form(self::form())
            ->action(function (Document $record, array $data): void {
                self::perform(ActionSupport::asCollection($record), $data);
            })
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    public static function bulk(string $name = 'bulkSetMuseumLocation'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Send to museum')
            ->icon('heroicon-o-building-library')
            ->color('warning')
            ->modalHeading('Mark selected documents as on museum display')
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
            Forms\Components\Select::make('to_location_id')
                ->label('Museum / showcase')
                ->options(fn (): array => Location::query()
                    ->whereIn('type', self::MUSEUM_LOCATION_TYPES)
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->required(),

            Forms\Components\TextInput::make('museum_reference')
                ->label('Museum reference code')
                ->helperText('Free-text identifier the museum uses for this item on display (e.g. "NRA-EXH-2026-014").')
                ->maxLength(191)
                ->required(),

            Forms\Components\Textarea::make('notes')
                ->label('Notes (optional)')
                ->helperText('Appended to the existing document notes with a timestamp prefix; existing notes are preserved.')
                ->rows(2)
                ->maxLength(500),
        ];
    }

    /**
     * @param EloquentCollection<int, Document> $records
     * @param array<string, mixed> $data
     */
    private static function perform(EloquentCollection $records, array $data): void
    {
        $locationId = (int) ($data['to_location_id'] ?? 0);
        $reference = trim((string) ($data['museum_reference'] ?? ''));
        $notes = trim((string) ($data['notes'] ?? ''));

        /** @var Location|null $location */
        $location = Location::withoutGlobalScopes()->find($locationId);
        if ($location === null || $location->trashed()
            || ! in_array($location->type, self::MUSEUM_LOCATION_TYPES, true)) {
            Notification::make()
                ->title('Cannot send — target is not a museum / showcase location')
                ->danger()->send();

            return;
        }

        $result = ActionSupport::performBulk(
            $records,
            function (Document $doc) use ($location, $reference, $notes): void {
                if ($location->repository_id !== null
                    && (int) $location->repository_id !== (int) $doc->repository_id) {
                    throw new \DomainException(
                        'museum location belongs to a different repository'
                    );
                }

                $doc->location_id = $location->getKey();
                $doc->museum_reference = $reference;
                if ($notes !== '') {
                    $prefix = '[' . now()->format('Y-m-d') . ' museum] ';
                    $doc->notes = $doc->notes
                        ? $doc->notes . "\n" . $prefix . $notes
                        : $prefix . $notes;
                }
                $doc->save();
            },
        );

        ActionSupport::notifyBulkResult(
            $result,
            successVerb: "sent to '{$location->name}' with reference '{$reference}'",
            failedTitle: 'Museum location not applied',
        );
    }
}
