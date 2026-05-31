<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\DigitisationStatusResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Resources\Lookups\DigitisationStatusResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDigitisationStatuses extends ListRecords
{
    use ExplainsPage;

    protected static string $resource = DigitisationStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
