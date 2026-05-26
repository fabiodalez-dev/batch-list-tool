<?php

namespace App\Filament\Resources\DocumentFlagResource\Pages;

use App\Filament\Resources\DocumentFlagResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDocumentFlag extends EditRecord
{
    protected static string $resource = DocumentFlagResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
