<?php

namespace App\Filament\Resources\AccessionResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Resources\AccessionResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAccessions extends ListRecords
{
    use ExplainsPage;

    protected static string $resource = AccessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
