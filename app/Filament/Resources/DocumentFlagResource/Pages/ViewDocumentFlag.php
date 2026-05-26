<?php

namespace App\Filament\Resources\DocumentFlagResource\Pages;

use App\Filament\Resources\DocumentFlagResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewDocumentFlag extends ViewRecord
{
    protected static string $resource = DocumentFlagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
