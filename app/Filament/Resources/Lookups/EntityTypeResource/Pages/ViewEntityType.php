<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\EntityTypeResource\Pages;

use App\Filament\Resources\Lookups\EntityTypeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewEntityType extends ViewRecord
{
    protected static string $resource = EntityTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
