<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\LocationTypeResource\Pages;

use App\Filament\Resources\Lookups\LocationTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLocationType extends EditRecord
{
    protected static string $resource = LocationTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
