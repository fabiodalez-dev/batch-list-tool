<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Filament\Support\SearchableSelects;
use App\Models\Document;
use App\Models\Location;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Action #4 — Pin document(s) to a configurable Location (RFQ §3.1.9).
 *
 * Multi-tenant safety: the target Location must either be repository-scoped
 * to the same repository as each document, OR be a "global" location
 * (`repository_id IS NULL` — typically conservation labs / shared rooms).
 * Documents in mixed repositories get individually validated: documents whose
 * repository_id does not match the location's repository_id are reported in
 * the partial-success message.
 */
final class SetLocationAction
{
    public static function make(string $name = 'setLocation'): Action
    {
        return Action::make($name)
            ->label('Set location')
            ->icon('heroicon-o-map-pin')
            ->color('primary')
            ->modalHeading('Set the location for this document')
            ->form(self::form())
            ->action(function (Document $record, array $data): void {
                self::perform(ActionSupport::asCollection($record), $data);
            })
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    public static function bulk(string $name = 'bulkSetLocation'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Set location')
            ->icon('heroicon-o-map-pin')
            ->color('primary')
            ->modalHeading('Set the location for selected documents')
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
            SearchableSelects::location('to_location_id', fn ($q) => $q->where('is_active', true))
                ->label('Target location')
                ->required(),
        ];
    }

    /**
     * @param EloquentCollection<int, Document> $records
     * @param array<string, mixed> $data
     */
    private static function perform(EloquentCollection $records, array $data): void
    {
        $locationId = (int) ($data['to_location_id'] ?? 0);

        /** @var Location|null $location */
        $location = Location::withoutGlobalScopes()->find($locationId);
        if ($location === null || $location->trashed()) {
            Notification::make()
                ->title('Cannot set — target location not found or deleted')
                ->danger()->send();

            return;
        }

        $result = ActionSupport::performBulk(
            $records,
            function (Document $doc) use ($location): void {
                // Tenant safety: a repository-scoped location can only be
                // assigned to documents in the same repository. A global
                // location (repository_id=null) can be assigned to any doc.
                if ($location->repository_id !== null
                    && (int) $location->repository_id !== (int) $doc->repository_id) {
                    throw new \DomainException(
                        'location belongs to a different repository'
                    );
                }

                $doc->location_id = $location->getKey();
                $doc->save();
            },
        );

        ActionSupport::notifyBulkResult(
            $result,
            successVerb: "pinned to '{$location->name}'",
            failedTitle: 'Location not applied',
        );
    }
}
