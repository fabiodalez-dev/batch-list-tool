<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\BoxTypeResource\Pages;

use App\Filament\Resources\Lookups\BoxTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBoxType extends CreateRecord
{
    protected static string $resource = BoxTypeResource::class;
}
