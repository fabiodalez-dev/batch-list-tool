<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Filament\Support\SearchableSelects;
use App\Models\Authority;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Action #9 — Detach an Authority from document(s).
 *
 * Documents that don't currently have the chosen authority are silently
 * skipped (no-op intent).
 */
final class DetachAuthorityAction
{
    public static function make(string $name = 'detachAuthority'): Action
    {
        return Action::make($name)
            ->label('Detach authority')
            ->icon('heroicon-o-user-minus')
            ->color('danger')
            ->modalHeading('Detach an authority from this document')
            ->form(self::form())
            ->action(function (Document $record, array $data): void {
                self::perform(ActionSupport::asCollection($record), $data);
            })
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    public static function bulk(string $name = 'bulkDetachAuthority'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Detach authority')
            ->icon('heroicon-o-user-minus')
            ->color('danger')
            ->modalHeading('Detach an authority from selected documents')
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
            SearchableSelects::authority('authority_id')
                ->label('Authority to detach')
                ->required(),
        ];
    }

    /**
     * @param EloquentCollection<int, Document> $records
     * @param array<string, mixed> $data
     */
    private static function perform(EloquentCollection $records, array $data): void
    {
        $authorityId = (int) ($data['authority_id'] ?? 0);

        /** @var Authority|null $authority */
        $authority = Authority::query()->find($authorityId);
        if ($authority === null) {
            Notification::make()
                ->title('Cannot detach — authority not found')
                ->danger()->send();

            return;
        }

        $detached = 0;
        $skipped = 0;
        $errors = [];

        DB::transaction(function () use ($records, $authority, &$detached, &$skipped, &$errors): void {
            foreach ($records as $doc) {
                /** @var Document $doc */
                try {
                    $existed = $doc->authorities()->where('authorities.id', $authority->getKey())->exists();
                    if (! $existed) {
                        $skipped++;
                        continue;
                    }

                    $doc->authorities()->detach($authority->getKey());

                    ActionSupport::logPivotChange(
                        document: $doc,
                        event: 'authority_detached',
                        newValues: [],
                        oldValues: ['authority_id' => $authority->getKey()],
                        tags: 'pivot,authority,detach',
                    );

                    $detached++;
                } catch (\Throwable $e) {
                    $errors[] = "#{$doc->identifier}: {$e->getMessage()}";
                }
            }
        });

        $label = "{$authority->identifier} — {$authority->surname}";

        if ($errors === [] && $detached > 0) {
            $suffix = $skipped > 0 ? " ({$skipped} did not have it)" : '';
            Notification::make()
                ->title("Detached '{$label}' from {$detached} document(s){$suffix}")
                ->success()->send();

            return;
        }

        if ($detached === 0 && $skipped > 0 && $errors === []) {
            Notification::make()
                ->title("No documents had '{$label}' — nothing to detach")
                ->warning()->send();

            return;
        }

        if ($detached > 0) {
            Notification::make()
                ->title("Partial: detached {$detached}, " . count($errors) . ' failed')
                ->body(implode("\n", array_slice($errors, 0, 5)))
                ->warning()->send();

            return;
        }

        Notification::make()
            ->title('Authority detach failed')
            ->body(implode("\n", array_slice($errors, 0, 5)) ?: 'No documents updated.')
            ->danger()->send();
    }
}
