<?php

declare(strict_types=1);

namespace App\Filament\Resources\PracticeResource\Pages;

use App\Filament\Resources\PracticeResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditPractice extends EditRecord
{
    protected static string $resource = PracticeResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
