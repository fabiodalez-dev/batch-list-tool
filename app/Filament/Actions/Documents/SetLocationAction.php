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
use Illuminate\Support\Facades\DB;

/**
 * Action #4 — Pin document(s) to a configurable Location (RFQ §3.1.9).
 *
 * Multi-tenant safety: the target Location must either be repository-scoped
 * to the same repository as each document, OR be a "global" location
 * (`repository_id IS NULL` — typically conservation labs / shared rooms).
 * Documents in mixed repositories get individually validated: documents whose
 * repository_id does not match the location's repository_id are skipped and
 * reported in the partial-success message.
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

        $ok = 0;
        $skipped = 0;
        $errors = [];

        DB::transaction(function () use ($records, $location, &$ok, &$skipped, &$errors): void {
            foreach ($records as $doc) {
                /** @var Document $doc */
                try {
                    // Tenant safety: a repository-scoped location can only be
                    // assigned to documents in the same repository. A global
                    // location (repository_id=null) can be assigned to any doc.
                    if ($location->repository_id !== null
                        && (int) $location->repository_id !== (int) $doc->repository_id) {
                        $skipped++;
                        $errors[] = "#{$doc->identifier}: location belongs to a different repository";
                        continue;
                    }

                    $doc->location_id = $location->getKey();
                    $doc->save();
                    $ok++;
                } catch (\Throwable $e) {
                    $errors[] = "#{$doc->identifier}: {$e->getMessage()}";
                }
            }
        });

        if ($errors === [] && $ok > 0) {
            Notification::make()
                ->title("{$ok} document(s) pinned to '{$location->name}'")
                ->success()->send();

            return;
        }

        if ($ok > 0) {
            Notification::make()
                ->title("Partial: {$ok} updated, " . count($errors) . ' issues')
                ->body(implode("\n", array_slice($errors, 0, 5)))
                ->warning()->send();

            return;
        }

        Notification::make()
            ->title('Location not applied')
            ->body(implode("\n", array_slice($errors, 0, 5)) ?: 'No documents updated.')
            ->danger()->send();
    }
}
