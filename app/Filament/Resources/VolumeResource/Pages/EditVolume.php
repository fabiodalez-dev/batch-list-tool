<?php

namespace App\Filament\Resources\VolumeResource\Pages;

use App\Filament\Concerns\HandlesCustomFieldForm;
use App\Filament\Resources\VolumeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditVolume extends EditRecord
{
    use HandlesCustomFieldForm;

    protected static string $resource = VolumeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
