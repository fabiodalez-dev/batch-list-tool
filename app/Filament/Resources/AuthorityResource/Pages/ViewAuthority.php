<?php

namespace App\Filament\Resources\AuthorityResource\Pages;

use App\Filament\Resources\AuthorityResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewAuthority extends ViewRecord
{
    protected static string $resource = AuthorityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
