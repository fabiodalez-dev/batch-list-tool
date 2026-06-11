<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\LocationTypeResource\Pages;

use App\Filament\Resources\Lookups\LocationTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLocationType extends CreateRecord
{
    protected static string $resource = LocationTypeResource::class;
}
