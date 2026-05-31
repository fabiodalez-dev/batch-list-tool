<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\BatchTypeResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Resources\Lookups\BatchTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBatchTypes extends ListRecords
{
    use ExplainsPage;

    protected static string $resource = BatchTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
