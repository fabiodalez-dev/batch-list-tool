<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Models\Document;
use App\Models\Scopes\RepositoryScope;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Action #14 — Update the document's `identifier`.
 *
 * SINGLE-RECORD ONLY — bulk renaming would silently create duplicates and
 * is rejected by design (the human operator must do this one at a time so
 * the new identifier is verified per row).
 *
 * The DocumentObserver already writes a row into `document_identifier_history`
 * on every save() that changes `identifier`, so we just update the model and
 * the audit trail is recorded automatically.
 */
final class UpdateIdentifierAction
{
    public static function make(string $name = 'updateIdentifier'): Action
    {
        return Action::make($name)
            ->label('Change identifier')
            ->icon('heroicon-o-identification')
            ->color('danger')
            ->modalHeading("Change the document's identifier")
            ->modalDescription('The previous identifier is preserved in the identifier history for traceability.')
            ->form([
                TextInput::make('identifier')
                    ->label('New identifier')
                    ->required()
                    ->maxLength(64)
                    ->placeholder('e.g. R7-bis'),
            ])
            ->action(function (Document $record, array $data): void {
                self::perform($record, $data);
            })
            ->visible(fn () => auth()->user()?->can('update_document') ?? false);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function perform(Document $record, array $data): void
    {
        $new = trim((string) ($data['identifier'] ?? ''));
        if ($new === '') {
            Notification::make()->title('Identifier cannot be blank')->danger()->send();

            return;
        }

        if ($new === $record->identifier) {
            Notification::make()->title('Identifier unchanged')->warning()->send();

            return;
        }

        // Uniqueness within the same repository (multi-tenant safety).
        $exists = Document::query()
            ->withoutGlobalScope(RepositoryScope::class)
            ->where('repository_id', $record->repository_id)
            ->where('identifier', $new)
            ->where('id', '!=', $record->getKey())
            ->exists();
        if ($exists) {
            Notification::make()
                ->title('Identifier already in use within this repository')
                ->danger()->send();

            return;
        }

        try {
            DB::transaction(function () use ($record, $new): void {
                $record->identifier = $new;
                // DocumentObserver writes the identifier-history row on save().
                $record->save();
            });

            Notification::make()
                ->title("Identifier changed to '{$new}'")
                ->success()->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Identifier update failed')
                ->body($e->getMessage())
                ->danger()->send();
        }
    }
}
