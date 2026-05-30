<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\FlagTypeResource\Pages;

use App\Filament\Resources\Lookups\FlagTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFlagType extends EditRecord
{
    protected static string $resource = FlagTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
