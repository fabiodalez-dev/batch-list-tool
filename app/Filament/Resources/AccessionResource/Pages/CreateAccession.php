<?php

namespace App\Filament\Resources\AccessionResource\Pages;

use App\Filament\Concerns\HandlesCustomFieldForm;
use App\Filament\Resources\AccessionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAccession extends CreateRecord
{
    use HandlesCustomFieldForm;

    protected static string $resource = AccessionResource::class;
}
