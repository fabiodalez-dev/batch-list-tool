<?php

namespace App\Filament\Resources\BoxResource\Pages;

use App\Filament\Concerns\HandlesCustomFieldForm;
use App\Filament\Resources\BoxResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBox extends CreateRecord
{
    use HandlesCustomFieldForm;

    protected static string $resource = BoxResource::class;
}
