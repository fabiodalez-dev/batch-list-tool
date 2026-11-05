<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\CustodyStatusTypeResource\Pages;

use App\Filament\Resources\Lookups\CustodyStatusTypeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCustodyStatusType extends ViewRecord
{
    protected static string $resource = CustodyStatusTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
