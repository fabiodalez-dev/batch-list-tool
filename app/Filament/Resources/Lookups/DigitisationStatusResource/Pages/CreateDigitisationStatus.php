<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\DigitisationStatusResource\Pages;

use App\Filament\Resources\Lookups\DigitisationStatusResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDigitisationStatus extends CreateRecord
{
    protected static string $resource = DigitisationStatusResource::class;
}
