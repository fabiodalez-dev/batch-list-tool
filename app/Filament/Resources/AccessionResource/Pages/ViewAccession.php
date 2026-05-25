<?php

namespace App\Filament\Resources\AccessionResource\Pages;

use App\Filament\Resources\AccessionResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewAccession extends ViewRecord
{
    protected static string $resource = AccessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
