<?php

namespace App\Filament\Resources\BackupDestinationResource\Pages;

use App\Filament\Resources\BackupDestinationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBackupDestinations extends ListRecords
{
    protected static string $resource = BackupDestinationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
