<?php

namespace App\Filament\Resources\DocumentFlagResource\Pages;

use App\Filament\Resources\DocumentFlagResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDocumentFlags extends ListRecords
{
    protected static string $resource = DocumentFlagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
