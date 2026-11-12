<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\CustodyStatusTypeResource\Pages;

use App\Filament\Resources\Lookups\CustodyStatusTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustodyStatusType extends CreateRecord
{
    protected static string $resource = CustodyStatusTypeResource::class;
}
