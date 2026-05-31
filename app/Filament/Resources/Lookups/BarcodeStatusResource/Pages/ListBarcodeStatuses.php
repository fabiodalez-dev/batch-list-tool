<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\BarcodeStatusResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Resources\Lookups\BarcodeStatusResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBarcodeStatuses extends ListRecords
{
    use ExplainsPage;

    protected static string $resource = BarcodeStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
