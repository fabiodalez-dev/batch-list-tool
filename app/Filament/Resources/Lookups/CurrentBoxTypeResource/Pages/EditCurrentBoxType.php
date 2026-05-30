<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\CurrentBoxTypeResource\Pages;

use App\Filament\Resources\Lookups\CurrentBoxTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCurrentBoxType extends EditRecord
{
    protected static string $resource = CurrentBoxTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
