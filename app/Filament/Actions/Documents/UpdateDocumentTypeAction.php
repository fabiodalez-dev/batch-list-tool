<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Models\Document;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Action #12 — Set `documents.document_type` (free text).
 *
 * The form Select is `searchable` + `createOptionForm` is off — operators
 * can pick from existing distinct values OR type a new one. The autocomplete
 * is sourced from `Document::distinct()->pluck('document_type')` so it
 * naturally surfaces the existing vocabulary; entering a new value extends it.
 */
final class UpdateDocumentTypeAction
{
    public static function make(string $name = 'updateDocumentType'): Action
    {
        return Action::make($name)
            ->label('Set document type')
            ->icon('heroicon-o-document-text')
            ->color('primary')
            ->modalHeading('Set the document type for this record')
            ->form(self::form())
            ->action(function (Document $record, array $data): void {
                self::perform(ActionSupport::asCollection($record), $data);
            })
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    public static function bulk(string $name = 'bulkUpdateDocumentType'): BulkAction
    {
        return BulkAction::make($name)
            ->label('Set document type')
            ->icon('heroicon-o-document-text')
            ->color('primary')
            ->modalHeading('Set the document type for selected records')
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
            Select::make('document_type')
                ->label('Document type')
                ->options(fn (): array => self::existingTypes())
                ->searchable()
                ->allowHtml(false)
                ->required()
                ->native(false)
                // Allow the operator to enter a brand-new value not yet in
                // the dataset — Filament's getSearchResultsUsing always
                // appends the typed query as a valid selection.
                ->getSearchResultsUsing(function (string $search): array {
                    $existing = self::existingTypes();
                    $search = trim($search);
                    if ($search === '') {
                        return $existing;
                    }
                    $filtered = collect($existing)
                        ->filter(fn ($v) => stripos((string) $v, $search) !== false)
                        ->all();
                    if (! array_key_exists($search, $filtered)) {
                        $filtered[$search] = $search . '  (new)';
                    }

                    return $filtered;
                })
                ->getOptionLabelUsing(fn ($value) => (string) $value),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function existingTypes(): array
    {
        $types = Document::query()
            ->withoutGlobalScopes()
            ->select('document_type')
            ->whereNotNull('document_type')
            ->where('document_type', '!=', '')
            ->distinct()
            ->orderBy('document_type')
            ->limit(200)
            ->pluck('document_type')
            ->all();

        $out = [];
        foreach ($types as $t) {
            $out[(string) $t] = (string) $t;
        }

        return $out;
    }

    /**
     * @param EloquentCollection<int, Document> $records
     * @param array<string, mixed> $data
     */
    private static function perform(EloquentCollection $records, array $data): void
    {
        $type = trim((string) ($data['document_type'] ?? ''));
        if ($type === '') {
            Notification::make()
                ->title('Document type cannot be blank')
                ->danger()->send();

            return;
        }

        $ok = 0;
        $errors = [];

        DB::transaction(function () use ($records, $type, &$ok, &$errors): void {
            foreach ($records as $doc) {
                /** @var Document $doc */
                try {
                    $doc->document_type = $type;
                    $doc->save();
                    $ok++;
                } catch (\Throwable $e) {
                    $errors[] = "#{$doc->identifier}: {$e->getMessage()}";
                }
            }
        });

        if ($errors === [] && $ok > 0) {
            Notification::make()
                ->title("Set document_type='{$type}' on {$ok} document(s)")
                ->success()->send();

            return;
        }

        if ($ok > 0) {
            Notification::make()
                ->title("Partial: {$ok} updated, " . count($errors) . ' failed')
                ->body(implode("\n", array_slice($errors, 0, 5)))
                ->warning()->send();

            return;
        }

        Notification::make()
            ->title('Update failed')
            ->body(implode("\n", array_slice($errors, 0, 5)) ?: 'No documents updated.')
            ->danger()->send();
    }
}
