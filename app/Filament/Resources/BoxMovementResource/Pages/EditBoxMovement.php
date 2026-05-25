<?php

namespace App\Filament\Resources\BoxMovementResource\Pages;

use App\Filament\Resources\BoxMovementResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBoxMovement extends EditRecord
{
    protected static string $resource = BoxMovementResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
