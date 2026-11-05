<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\EntityTypeResource\Pages;

use App\Filament\Resources\Lookups\EntityTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEntityType extends CreateRecord
{
    protected static string $resource = EntityTypeResource::class;
}
