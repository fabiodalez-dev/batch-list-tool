<?php

namespace App\Filament\Resources\AccessionResource\Pages;

use App\Filament\Resources\AccessionResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAccession extends EditRecord
{
    protected static string $resource = AccessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
