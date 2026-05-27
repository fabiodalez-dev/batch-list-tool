<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentTypeResource\Pages;

use App\Filament\Resources\DocumentTypeResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditDocumentType extends EditRecord
{
    protected static string $resource = DocumentTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Reference vocabulary — deactivate instead of hard delete so
            // historical Document rows that reference this name by string
            // stay readable.
            Action::make('deactivate')
                ->label('Deactivate')
                ->icon('heroicon-o-no-symbol')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('This entry will be hidden from new Document forms but historical references to it remain readable.')
                ->visible(fn (): bool => (bool) $this->record?->is_active)
                ->action(function (): void {
                    $this->record->update(['is_active' => false]);
                    $this->refreshFormData(['is_active']);
                }),
        ];
    }
}
