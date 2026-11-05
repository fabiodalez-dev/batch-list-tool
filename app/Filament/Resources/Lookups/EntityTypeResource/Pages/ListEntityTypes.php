<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\EntityTypeResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Resources\Lookups\EntityTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEntityTypes extends ListRecords
{
    use ExplainsPage;

    protected static string $resource = EntityTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
