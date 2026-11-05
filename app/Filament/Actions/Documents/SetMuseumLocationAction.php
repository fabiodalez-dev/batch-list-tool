<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Filament\Support\SearchableSelects;
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
 * out of reach, through the scoped picker so that it lists the user's
 * repositories only.
 *
 * Client 2026-11-05: "Location to change to showcase, Museum reference code,
 * Custody Status – Not in Box/Mounted". The document has physically left its
 * box, so it is taken out of it, with the move in the chain of custody.
 */
final class SetMuseumLocationAction
{
    private const array MUSEUM_LOCATION_TYPES = ['museum', 'showcase'];

    private const array CUSTODY_STATUSES = ['not_in_box', 'mounted_no_box'];

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
            SearchableSelects::locationPicker(
                'to_location_id',
                fn ($q) => $q->whereIn('type', self::MUSEUM_LOCATION_TYPES)->where('is_active', true),
            )
                ->label('Museum / showcase')
                ->required(),

            Forms\Components\TextInput::make('museum_reference')
                ->label('Museum reference code')
                ->helperText('Free-text identifier the museum uses for this item on display (e.g. "NRA-EXH-2026-014").')
                ->maxLength(191)
                ->required(),

            Forms\Components\Select::make('custody_status')
                ->label('Custody Status')
                ->options(fn (): array => self::custodyOptions())
                ->required(),

            Forms\Components\Textarea::make('notes')
                ->label('Notes (optional)')
                ->helperText('Appended to the existing document notes with a timestamp prefix; existing notes are preserved.')
                ->rows(2)
                ->maxLength(500),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function custodyOptions(): array
    {
        return array_intersect_key(Document::custodyLabels(), array_flip(self::CUSTODY_STATUSES));
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
        $status = (string) ($data['custody_status'] ?? '');

        // Server-side guard against forms that bypass the Filament required()
        // (e.g. crafted POSTs). The reference is operator-facing metadata —
        // an empty string would be invisible in the audit log diff.
        if ($reference === '') {
            Notification::make()
                ->title('Museum reference is required')
                ->danger()->send();

            return;
        }

        if (! array_key_exists($status, self::custodyOptions())) {
            Notification::make()
                ->title('Custody status must be Not in box or Mounted')
                ->danger()->send();

            return;
        }

        /** @var Location|null $location */
        $location = Location::withoutGlobalScopes()->find($locationId);
        if ($location === null || $location->trashed()
            || ! (bool) $location->is_active
            || ! in_array($location->type, self::MUSEUM_LOCATION_TYPES, true)) {
            Notification::make()
                ->title('Cannot send — target is not an active museum / showcase location')
                ->danger()->send();

            return;
        }

        $result = ActionSupport::performBulk(
            $records,
            function (Document $doc) use ($location, $reference, $notes, $status): void {
                ActionSupport::assertLocationFits($location, $doc);

                $from = $doc->current_box_id;
                $doc->location_id = $location->getKey();
                $doc->museum_reference = $reference;
                $doc->museum_location = $location->code;
                $doc->custody_status = $status;
                $doc->current_box_id = null;
                if ($notes !== '') {
                    $prefix = '[' . now()->format('Y-m-d') . ' museum] ';
                    $doc->notes = $doc->notes
                        ? $doc->notes . "\n" . $prefix . $notes
                        : $prefix . $notes;
                }
                $doc->save();

                if ($from !== null) {
                    ActionSupport::recordLeftBox($doc, (int) $from, "Sent to museum: {$location->name}");
                }
            },
        );

        ActionSupport::notifyBulkResult(
            $result,
            successVerb: "sent to '{$location->name}' with reference '{$reference}'",
            failedTitle: 'Museum location not applied',
        );
    }
}
