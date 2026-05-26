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
 * Action #8 — Replace one authority with another on document(s).
 *
 * For each document: if the "old" authority is currently attached, detach it
 * and attach the "new" authority. We preserve the `is_primary` pivot value
 * from the old row — if the old authority was the primary, the new one
 * becomes the primary; otherwise it inherits the same secondary status.
 *
 * Documents that don't have the "old" authority attached are silently
 * skipped (they're not failures — the operator's intent on those docs is a
 * no-op).
 */
final class ReplaceAuthorityAction
{
    public static function make(string $name = 'replaceAuthority'): Action
    {
        return Action::make($name)
            ->label('Replace authority')
            ->icon('heroicon-o-arrows-right-left')
            ->color('warning')
            ->modalHeading('Replace one authority with another on this document')
            ->form(self::form())
            ->action(function (Document $record, array $data): void {
                self::perform(ActionSupport::asCollection($record), $data);
            })
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    public static function bulk(string $name = 'bulkReplaceAuthority'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Replace authority')
            ->icon('heroicon-o-arrows-right-left')
            ->color('warning')
            ->modalHeading('Replace one authority with another on selected documents')
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
            SearchableSelects::authority('old_authority_id')
                ->label('Authority to replace')
                ->required(),
            SearchableSelects::authority('new_authority_id')
                ->label('New authority')
                ->required()
                ->different('old_authority_id'),
        ];
    }

    /**
     * @param EloquentCollection<int, Document> $records
     * @param array<string, mixed> $data
     */
    private static function perform(EloquentCollection $records, array $data): void
    {
        $oldId = (int) ($data['old_authority_id'] ?? 0);
        $newId = (int) ($data['new_authority_id'] ?? 0);

        if ($oldId === $newId) {
            Notification::make()
                ->title('Old and new authority must differ')
                ->danger()->send();

            return;
        }

        /** @var Authority|null $old */
        $old = Authority::query()->find($oldId);
        /** @var Authority|null $new */
        $new = Authority::query()->find($newId);

        if ($old === null || $new === null || $old->trashed() || $new->trashed()) {
            Notification::make()
                ->title('Cannot replace — one of the authorities was not found')
                ->danger()->send();

            return;
        }

        $replaced = 0;
        $skipped = 0;
        $errors = [];

        DB::transaction(function () use ($records, $old, $new, &$replaced, &$skipped, &$errors): void {
            foreach ($records as $doc) {
                /** @var Document $doc */
                try {
                    $pivot = $doc->authorities()->where('authorities.id', $old->getKey())->first();
                    if ($pivot === null) {
                        $skipped++;
                        continue;
                    }

                    $wasPrimary = (bool) ($pivot->pivot->is_primary ?? false);

                    $doc->authorities()->detach($old->getKey());

                    // Don't add a duplicate row if the doc already has `new`.
                    if (! $doc->authorities()->where('authorities.id', $new->getKey())->exists()) {
                        $doc->authorities()->attach($new->getKey(), ['is_primary' => $wasPrimary]);
                    }

                    ActionSupport::logPivotChange(
                        document: $doc,
                        event: 'authority_replaced',
                        newValues: [
                            'authority_id' => $new->getKey(),
                            'is_primary' => $wasPrimary,
                        ],
                        oldValues: [
                            'authority_id' => $old->getKey(),
                            'is_primary' => $wasPrimary,
                        ],
                        tags: 'pivot,authority,replace',
                    );

                    $replaced++;
                } catch (\Throwable $e) {
                    $errors[] = "#{$doc->identifier}: {$e->getMessage()}";
                }
            }
        });

        if ($errors === [] && $replaced > 0) {
            $suffix = $skipped > 0 ? " ({$skipped} did not have the old authority)" : '';
            Notification::make()
                ->title("Replaced authority on {$replaced} document(s){$suffix}")
                ->success()->send();

            return;
        }

        if ($replaced === 0 && $skipped > 0 && $errors === []) {
            Notification::make()
                ->title('No documents had the old authority — nothing to replace')
                ->warning()->send();

            return;
        }

        if ($replaced > 0) {
            Notification::make()
                ->title("Partial: replaced {$replaced}, " . count($errors) . ' failed')
                ->body(implode("\n", array_slice($errors, 0, 5)))
                ->warning()->send();

            return;
        }

        Notification::make()
            ->title('Authority replace failed')
            ->body(implode("\n", array_slice($errors, 0, 5)) ?: 'No documents updated.')
            ->danger()->send();
    }
}
