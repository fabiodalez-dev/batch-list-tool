<?php

declare(strict_types=1);

namespace App\Filament\Resources\PracticeResource\Pages;

use App\Filament\Resources\PracticeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPractices extends ListRecords
{
    protected static string $resource = PracticeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
