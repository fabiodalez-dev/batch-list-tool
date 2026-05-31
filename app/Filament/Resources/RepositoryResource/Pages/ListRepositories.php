<?php

namespace App\Filament\Resources\RepositoryResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Resources\RepositoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListRepositories extends ListRecords
{
    use ExplainsPage;

    protected static string $resource = RepositoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
