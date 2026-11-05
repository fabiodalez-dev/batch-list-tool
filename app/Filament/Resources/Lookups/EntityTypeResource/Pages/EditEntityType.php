<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\EntityTypeResource\Pages;

use App\Filament\Resources\Lookups\EntityTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditEntityType extends EditRecord
{
    protected static string $resource = EntityTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), DeleteAction::make()];
    }
}
