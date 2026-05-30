<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\FlagTypeResource\Pages;

use App\Filament\Resources\Lookups\FlagTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFlagType extends CreateRecord
{
    protected static string $resource = FlagTypeResource::class;
}
