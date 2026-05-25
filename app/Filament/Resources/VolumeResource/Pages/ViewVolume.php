<?php

namespace App\Filament\Resources\VolumeResource\Pages;

use App\Filament\Resources\VolumeResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewVolume extends ViewRecord
{
    protected static string $resource = VolumeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
