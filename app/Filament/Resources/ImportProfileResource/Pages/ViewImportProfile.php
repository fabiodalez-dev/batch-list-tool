<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportProfileResource\Pages;

use App\Filament\Pages\ImportWizard;
use App\Filament\Resources\ImportProfileResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewImportProfile extends ViewRecord
{
    protected static string $resource = ImportProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('use_in_wizard')
                ->label('Use in Import Wizard')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('primary')
                ->url(fn (): string => ImportWizard::getUrl(['profile' => $this->getRecord()->getKey()])),

            Actions\EditAction::make()
                ->visible(fn (): bool => ImportProfileResource::canManage($this->getRecord())),

            Actions\DeleteAction::make()
                ->visible(fn (): bool => ImportProfileResource::canManage($this->getRecord())),
        ];
    }
}
