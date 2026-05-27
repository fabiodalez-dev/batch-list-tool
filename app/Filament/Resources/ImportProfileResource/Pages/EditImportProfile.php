<?php

declare(strict_types=1);

namespace App\Filament\Resources\ImportProfileResource\Pages;

use App\Filament\Resources\ImportProfileResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditImportProfile extends EditRecord
{
    protected static string $resource = ImportProfileResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make()
                ->visible(fn (): bool => ImportProfileResource::canManage($this->getRecord())),
        ];
    }
}
