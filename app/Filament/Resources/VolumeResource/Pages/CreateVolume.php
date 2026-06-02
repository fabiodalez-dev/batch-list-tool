<?php

namespace App\Filament\Resources\VolumeResource\Pages;

use App\Filament\Concerns\HandlesCustomFieldForm;
use App\Filament\Resources\VolumeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateVolume extends CreateRecord
{
    use HandlesCustomFieldForm;

    protected static string $resource = VolumeResource::class;
}
