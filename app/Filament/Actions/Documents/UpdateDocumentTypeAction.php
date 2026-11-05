<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Models\Document;
use App\Models\DocumentType;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Action #12 — Set `documents.document_type`.
 *
 * Client 2026-11-05: "The Label should be shown here not the identifier". The
 * options are the active Document Types, by name, and hold what a document
 * stores: {@see DocumentType::storedValue()}. The Document saving hook links
 * `document_type_id` from it. No new values from here: a type has its own page.
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
                ->options(fn (): array => self::types())
                ->searchable()
                ->required()
                ->native(false),
        ];
    }

    /**
     * @return array<string, string> stored value => name
     */
    private static function types(): array
    {
        return DocumentType::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (DocumentType $type): array => [$type->storedValue() => (string) $type->name])
            ->all();
    }

    /**
     * @param EloquentCollection<int, Document> $records
     * @param array<string, mixed> $data
     */
    private static function perform(EloquentCollection $records, array $data): void
    {
        $type = trim((string) ($data['document_type'] ?? ''));
        $name = self::types()[$type] ?? null;
        if ($name === null) {
            Notification::make()
                ->title('Choose one of the active document types')
                ->danger()->send();

            return;
        }

        $result = ActionSupport::performBulk(
            $records,
            function (Document $doc) use ($type): void {
                $doc->document_type = $type;
                $doc->save();
            },
        );

        ActionSupport::notifyBulkResult(
            $result,
            successVerb: "updated to document type '{$name}'",
            failedTitle: 'Update failed',
        );
    }
}
