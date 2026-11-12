<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Filament\Support\SearchableSelects;
use App\Models\Authority;
use App\Models\Document;
use App\Models\DocumentIdentifierHistory;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Action #8 — Replace one authority with another on document(s).
 *
 * Client 2026-11-05: "This power action should only be available when the ticked
 * documents have the same Authority to replace". A bulk action's visibility
 * cannot follow the live selection, so the check is made when it is opened: with
 * no authority common to all of them it does not open and says why. The
 * authority to replace is then picked among the common ones.
 *
 * For each document: if the "old" authority is currently attached, detach it
 * and attach the "new" authority. We preserve the `is_primary` pivot value
 * from the old row — if the old authority was the primary, the new one
 * becomes the primary; otherwise it inherits the same secondary status.
 * Who the document used to be attributed to is kept in its history.
 *
 * The check is made again per document: one that no longer has the "old"
 * authority is skipped, with nothing written, and counted in the notification.
 *
 * Per-row atomicity (review C-2): each row runs in its own DB transaction
 * so the detach + attach + audit row commit together (or roll back
 * together), and a single-row failure does NOT undo previously-replaced
 * rows.
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
            ->mountUsing(function (Action $action, Document $record, ?Schema $schema): void {
                self::openOrRefuse($action, $schema, self::commonAuthorities(self::only($record)));
            })
            ->form(fn (?Document $record): array => self::form($record !== null ? self::commonAuthorities(self::only($record)) : []))
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
            ->mountUsing(function (BulkAction $action, ?Schema $schema): void {
                self::openOrRefuse($action, $schema, self::commonAuthorities($action->getSelectedRecordsQuery()));
            })
            ->form(fn (BulkAction $action): array => self::form(self::commonAuthorities($action->getSelectedRecordsQuery())))
            ->action(function (EloquentCollection $records, array $data): void {
                self::perform($records, $data);
            })
            ->deselectRecordsAfterCompletion()
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    /**
     * @param array<int, string> $common
     */
    private static function openOrRefuse(Action $action, ?Schema $schema, array $common): void
    {
        if ($common === []) {
            Notification::make()
                ->title('Replace authority is not available')
                ->body('The selected documents have no authority in common. Select documents that share the authority to replace.')
                ->warning()->send();

            $action->cancel();
        }

        $schema?->fill();
    }

    /**
     * @return Builder<Document>
     */
    private static function only(Document $record): Builder
    {
        return Document::withTrashed()->whereKey($record->getKey());
    }

    /**
     * The authorities every one of these documents has. Asked of the query, as
     * "select all" can tick all 26,871 documents.
     *
     * @param Builder<Document> $documents
     * @return array<int, string> id => citing label
     */
    private static function commonAuthorities(Builder $documents): array
    {
        $common = DB::table('document_authority')
            ->whereIn('document_id', (clone $documents)->reorder()->select($documents->qualifyColumn('id')))
            ->groupBy('authority_id')
            ->havingRaw('COUNT(DISTINCT document_id) = ?', [(clone $documents)->reorder()->count()])
            ->pluck('authority_id');

        return Authority::query()->whereKey($common)->orderBy('surname')->get()
            ->mapWithKeys(fn (Authority $authority): array => [$authority->getKey() => $authority->citingLabel()])
            ->all();
    }

    /**
     * @param array<int, string> $common
     * @return array<int, Component>
     */
    private static function form(array $common): array
    {
        // The list and the search of the new authority leave the old one out.
        $without = fn (array $options, mixed $old): array => array_diff_key($options, [(int) $old => true]);

        return [
            Select::make('old_authority_id')
                ->label('Authority to replace')
                ->options($common)
                ->default(count($common) === 1 ? array_key_first($common) : null)
                ->searchable()
                ->live()
                ->required(),
            SearchableSelects::authority('new_authority_id')
                ->label('New authority')
                ->options(fn (Get $get): array => $without(SearchableSelects::authoritySearchResults(''), $get('old_authority_id')))
                ->getSearchResultsUsing(fn (string $search, Get $get): array => $without(SearchableSelects::authoritySearchResults($search), $get('old_authority_id')))
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

        foreach ($records as $doc) {
            /** @var Document $doc */
            try {
                $pivot = $doc->authorities()->where('authorities.id', $old->getKey())->first();
                if ($pivot === null) {
                    $skipped++;
                    continue;
                }

                $wasPrimary = (bool) ($pivot->pivot->is_primary ?? false);

                DB::transaction(static function () use ($doc, $old, $new, $wasPrimary): void {
                    $doc->authorities()->detach($old->getKey());

                    // Don't add a duplicate row if the doc already has `new`;
                    // it still takes over the primary role of the one replaced.
                    if (! $doc->authorities()->where('authorities.id', $new->getKey())->exists()) {
                        $doc->authorities()->attach($new->getKey(), ['is_primary' => $wasPrimary]);
                    } elseif ($wasPrimary) {
                        $doc->authorities()->updateExistingPivot($new->getKey(), ['is_primary' => true]);
                    }

                    DocumentIdentifierHistory::recordPastAuthority(
                        $doc,
                        (int) $old->getKey(),
                        Str::limit('Replaced by ' . $new->citingLabel(), 252),
                    );

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
                });

                $replaced++;
            } catch (\Throwable $e) {
                $errors[] = "#{$doc->identifier}: {$e->getMessage()}";
            }
        }

        if ($errors === [] && $replaced > 0) {
            $suffix = $skipped > 0 ? " ({$skipped} did not have the old authority)" : '';
            Notification::make()
                ->title("Replaced '{$old->citingLabel()}' with '{$new->citingLabel()}' on {$replaced} document(s){$suffix}")
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
            ->title('Authority replace failed (' . count($errors) . ' failed)')
            ->body(implode("\n", array_slice($errors, 0, 5)) ?: 'No documents updated.')
            ->danger()->send();
    }
}
