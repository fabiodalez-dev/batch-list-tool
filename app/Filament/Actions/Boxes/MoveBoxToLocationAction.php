<?php

declare(strict_types=1);

namespace App\Filament\Actions\Boxes;

use App\Filament\Support\SearchableSelects;
use App\Models\Box;
use App\Models\Location;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * RFQ §3.1.6 — Move Box to a different Location.
 *
 * Records the location change via the owen-it Auditable trait (location_id is
 * in Box::$fillable and not excluded, so every save that changes it produces
 * an `updated` audit row automatically — no manual audit write needed).
 *
 * An optional free-text reason is appended to box.notes so the operator's
 * comment survives long after any audit retention window, while the precise
 * field-level change (old/new location_id) lives in the audits table.
 *
 * Authorization: mirrors the `update_box` Shield permission used throughout
 * BoxResource. Destroying a location assignment is a subset of box editing,
 * so reusing the same gate keeps the permission surface small.
 */
final class MoveBoxToLocationAction
{
    public static function make(string $name = 'moveBoxToLocation'): Action
    {
        return Action::make($name)
            ->label('Move to location')
            ->icon('heroicon-o-map-pin')
            ->color('primary')
            ->modalHeading('Move box to a different location')
            ->modalDescription('Select the target location. The change will be recorded in the audit trail.')
            ->form([
                SearchableSelects::location(
                    'to_location_id',
                    fn ($q) => $q->where('is_active', true),
                )
                    ->label('Target location')
                    ->required(),
                Textarea::make('reason')
                    ->label('Reason (optional)')
                    ->maxLength(500)
                    ->rows(2)
                    ->placeholder('Why is this box being moved?'),
            ])
            ->authorize(fn (): bool => (bool) (auth()->user()?->can('update_box') ?? false))
            ->action(function (Box $record, array $data): void {
                $locationId = (int) ($data['to_location_id'] ?? 0);

                /** @var Location|null $location */
                $location = Location::withoutGlobalScopes()->find($locationId);

                if ($location === null || $location->trashed()) {
                    Notification::make()
                        ->title('Cannot move — target location not found or deleted')
                        ->danger()
                        ->send();

                    return;
                }

                $reason = isset($data['reason']) && is_string($data['reason'])
                    ? trim($data['reason'])
                    : null;
                $reason = ($reason === '' ? null : $reason);

                // Persist the location change — owen-it picks up the dirty
                // location_id and writes an `updated` audit row automatically.
                $record->location_id = $location->getKey();

                if ($reason !== null) {
                    // Prepend reason to notes so the context is human-readable
                    // in the box view without consulting the audit log.
                    $existing = $record->notes ? rtrim($record->notes) . "\n" : '';
                    $record->notes = $existing . '[Move] ' . $reason;
                }

                $record->save();

                Notification::make()
                    ->title('Box moved to ' . $location->name)
                    ->body('Location updated and recorded in the audit trail.')
                    ->success()
                    ->send();
            });
    }
}
