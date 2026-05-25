<?php

namespace App\Filament\Resources\BoxMovementResource\Pages;

use App\Filament\Resources\BoxMovementResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewBoxMovement extends ViewRecord
{
    protected static string $resource = BoxMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
