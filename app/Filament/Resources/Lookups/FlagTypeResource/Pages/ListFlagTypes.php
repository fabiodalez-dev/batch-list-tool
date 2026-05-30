<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\FlagTypeResource\Pages;

use App\Filament\Resources\Lookups\FlagTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFlagTypes extends ListRecords
{
    protected static string $resource = FlagTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
