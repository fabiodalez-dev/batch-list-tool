<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\BoxTypeResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Resources\Lookups\BoxTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBoxTypes extends ListRecords
{
    use ExplainsPage;

    protected static string $resource = BoxTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
