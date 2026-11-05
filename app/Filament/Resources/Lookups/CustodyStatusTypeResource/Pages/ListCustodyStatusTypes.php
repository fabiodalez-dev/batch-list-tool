<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\CustodyStatusTypeResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Resources\Lookups\CustodyStatusTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCustodyStatusTypes extends ListRecords
{
    use ExplainsPage;

    protected static string $resource = CustodyStatusTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
