<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\CurrentBoxTypeResource\Pages;

use App\Filament\Resources\Lookups\CurrentBoxTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCurrentBoxTypes extends ListRecords
{
    protected static string $resource = CurrentBoxTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
