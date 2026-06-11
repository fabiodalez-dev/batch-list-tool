<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\LocationTypeResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Resources\Lookups\LocationTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLocationTypes extends ListRecords
{
    use ExplainsPage;

    protected static string $resource = LocationTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
