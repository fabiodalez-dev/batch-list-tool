<?php

declare(strict_types=1);

namespace App\Filament\Actions\Boxes;

use App\Models\Box;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * RFQ Appendix 2 §vii — "Mark as destroyed" single-record action.
 *
 * Once every document in a box has a `catalogue_identifier`, the physical
 * artefact is destroyed and thrown away. This action records that business
 * event on the box row (stamps `destroyed_at`, `destroyed_by_user_id`,
 * optional `destroyed_reason`) and is wired into BoxResource as a row
 * action plus a header action on ViewBox.
 *
 * The bulk variant exists at the client's request (2026-11-05: "Destroy multiple
 * boxes together"), which is later than the RFQ's singular wording. Each box
 * still answers canBeDestroyed() on its own and has its own audit row; an
 * ineligible box is skipped and named.
 *
 * Visibility / authorization rules:
 *   - Hidden once the box is already destroyed (no double-destroy UI).
 *   - Requires the `delete_box` Shield permission. We piggyback on
 *     `delete_box` instead of inventing a new permission because destroying
 *     a physical box is conceptually a "delete the artefact" right — and
 *     keeping the policy surface small is good practice. The Auditable
 *     trail captures who did it regardless of which permission was used.
 */
final class DestroyBoxAction
{
    public static function make(string $name = 'destroyBox'): Action
    {
        return Action::make($name)
            ->label('Mark as destroyed')
            ->icon('heroicon-o-fire')
            ->color('danger')
            ->modalHeading('Mark this box as physically destroyed')
            ->modalDescription('This marks the box as physically destroyed. All documents must already have a catalogue identifier.')
            ->form(self::form())
            ->requiresConfirmation()
            // The "fire" icon + the danger colour are the standard
            // "irreversible action" cue across the project.
            ->modalSubmitActionLabel('Mark destroyed')
            ->visible(fn (?Box $record): bool => $record instanceof Box && ! $record->isDestroyed())
            ->authorize(fn (): bool => (bool) (auth()->user()?->can('delete_box') ?? false))
            ->action(function (Box $record, array $data): void {
                $check = $record->canBeDestroyed();

                if (! $check['ok']) {
                    Notification::make()
                        ->title('Cannot mark box as destroyed')
                        ->body($check['reason'] ?? 'Box is not eligible for destruction.')
                        ->danger()
                        ->send();

                    return;
                }

                $reason = self::reason($data);

                DB::transaction(function () use ($record, $reason): void {
                    // markDestroyed() throws DomainException if the box has
                    // become ineligible between our check above and now —
                    // e.g. another operator destroyed it concurrently. The
                    // exception bubbles up so Filament surfaces a generic
                    // error notification and the transaction rolls back.
                    $record->markDestroyed($reason, auth()->id());
                });

                Notification::make()
                    ->title('Box marked as destroyed')
                    ->body("Box #{$record->box_number} recorded as physically destroyed.")
                    ->success()
                    ->send();
            });
    }

    public static function bulk(string $name = 'destroyBoxes'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Destroy boxes')
            ->icon('heroicon-o-fire')
            ->color('danger')
            ->modalHeading('Mark the selected boxes as physically destroyed')
            ->modalDescription('Every document of a box must already have a catalogue identifier. A box that does not qualify is skipped and named.')
            ->form(self::form())
            ->requiresConfirmation()
            ->modalSubmitActionLabel('Mark destroyed')
            ->authorize(fn (): bool => (bool) (auth()->user()?->can('delete_box') ?? false))
            ->fetchSelectedRecords(false)
            ->action(function (Collection $records, array $data): void {
                $reason = self::reason($data);

                $result = BoxBulkSupport::run($records, static function (Box $box) use ($reason): void {
                    $check = $box->canBeDestroyed();
                    if (! $check['ok']) {
                        throw new \DomainException($check['reason'] ?? 'Box is not eligible for destruction.');
                    }

                    $box->markDestroyed($reason, auth()->id());
                });

                BoxBulkSupport::notify($result, 'marked as destroyed', 'No box was marked as destroyed');
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * @return array<int, Textarea>
     */
    private static function form(): array
    {
        return [
            Textarea::make('reason')
                ->label('Reason / where destroyed')
                ->placeholder('Optional — e.g. shredded on-site 2026-05-27, witness J.D.')
                ->maxLength(500)
                ->rows(3),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function reason(array $data): ?string
    {
        $reason = isset($data['reason']) && is_string($data['reason'])
            ? trim($data['reason'])
            : null;

        return $reason === '' ? null : $reason;
    }
}
