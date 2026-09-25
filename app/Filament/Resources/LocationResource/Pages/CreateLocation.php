<?php

declare(strict_types=1);

namespace App\Filament\Resources\LocationResource\Pages;

use App\Filament\Concerns\HandlesCustomFieldForm;
use App\Filament\Resources\LocationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLocation extends CreateRecord
{
    use HandlesCustomFieldForm;

    protected static string $resource = LocationResource::class;
}
