<?php

namespace App\Filament\Resources\BoxMovementResource\Pages;

use App\Filament\Resources\BoxMovementResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBoxMovements extends ListRecords
{
    protected static string $resource = BoxMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
