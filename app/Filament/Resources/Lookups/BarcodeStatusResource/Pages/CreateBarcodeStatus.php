<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\BarcodeStatusResource\Pages;

use App\Filament\Resources\Lookups\BarcodeStatusResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBarcodeStatus extends CreateRecord
{
    protected static string $resource = BarcodeStatusResource::class;
}
