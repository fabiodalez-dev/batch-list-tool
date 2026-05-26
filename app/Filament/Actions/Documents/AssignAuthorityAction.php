<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Filament\Support\SearchableSelects;
use App\Models\Authority;
use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Auditable;

/**
 * Action #7 — Attach an Authority (notary) to document(s).
 *
 * The pivot is idempotent: documents that already have the chosen authority
 * are silently skipped (we don't increment $ok, we don't add an error — the
 * end state matches what the operator asked for).
 *
 * Because pivot writes do NOT fire {@see Auditable} events
 * we write a manual audit row via {@see ActionSupport::logPivotChange()}.
 */
final class AssignAuthorityAction
{
    public static function make(string $name = 'assignAuthority'): Action
    {
        return Action::make($name)
            ->label('Add authority')
            ->icon('heroicon-o-user-plus')
            ->color('primary')
            ->modalHeading('Attach an authority to this document')
            ->form(self::form())
            ->action(function (Document $record, array $data): void {
                self::perform(ActionSupport::asCollection($record), $data);
            })
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    public static function bulk(string $name = 'bulkAssignAuthority'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Add authority')
            ->icon('heroicon-o-user-plus')
            ->color('primary')
            ->modalHeading('Attach an authority to selected documents')
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
                ->label('Authority (notary)')
                ->required(),
            Toggle::make('is_primary')
                ->label('Mark as primary authority')
                ->default(false),
        ];
    }

    /**
     * @param EloquentCollection<int, Document> $records
     * @param array<string, mixed> $data
     */
    private static function perform(EloquentCollection $records, array $data): void
    {
        $authorityId = (int) ($data['authority_id'] ?? 0);
        $isPrimary = (bool) ($data['is_primary'] ?? false);

        /** @var Authority|null $authority */
        $authority = Authority::query()->find($authorityId);
        if ($authority === null || $authority->trashed()) {
            Notification::make()
                ->title('Cannot attach — authority not found')
                ->danger()->send();

            return;
        }

        $attached = 0;
        $skipped = 0;
        $errors = [];

        DB::transaction(function () use ($records, $authority, $isPrimary, &$attached, &$skipped, &$errors): void {
            foreach ($records as $doc) {
                /** @var Document $doc */
                try {
                    $exists = $doc->authorities()->where('authorities.id', $authority->getKey())->exists();
                    if ($exists) {
                        $skipped++;
                        continue;
                    }

                    $doc->authorities()->attach($authority->getKey(), ['is_primary' => $isPrimary]);

                    ActionSupport::logPivotChange(
                        document: $doc,
                        event: 'authority_attached',
                        newValues: [
                            'authority_id' => $authority->getKey(),
                            'is_primary' => $isPrimary,
                        ],
                        tags: 'pivot,authority,attach',
                    );

                    $attached++;
                } catch (\Throwable $e) {
                    $errors[] = "#{$doc->identifier}: {$e->getMessage()}";
                }
            }
        });

        $label = "{$authority->identifier} — {$authority->surname}";

        if ($errors === [] && $attached > 0) {
            $suffix = $skipped > 0 ? " ({$skipped} already had it)" : '';
            Notification::make()
                ->title("Attached '{$label}' to {$attached} document(s){$suffix}")
                ->success()->send();

            return;
        }

        if ($attached === 0 && $skipped > 0 && $errors === []) {
            Notification::make()
                ->title("All {$skipped} selected document(s) already had '{$label}'")
                ->warning()->send();

            return;
        }

        if ($attached > 0) {
            Notification::make()
                ->title("Partial: attached {$attached}, " . count($errors) . ' failed')
                ->body(implode("\n", array_slice($errors, 0, 5)))
                ->warning()->send();

            return;
        }

        Notification::make()
            ->title('Authority attach failed')
            ->body(implode("\n", array_slice($errors, 0, 5)) ?: 'No documents updated.')
            ->danger()->send();
    }
}
