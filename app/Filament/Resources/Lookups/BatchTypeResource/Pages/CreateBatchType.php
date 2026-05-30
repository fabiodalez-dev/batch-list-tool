<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\BatchTypeResource\Pages;

use App\Filament\Resources\Lookups\BatchTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBatchType extends CreateRecord
{
    protected static string $resource = BatchTypeResource::class;
}
