<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups\BatchTypeResource\Pages;

use App\Filament\Resources\Lookups\BatchTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBatchType extends EditRecord
{
    protected static string $resource = BatchTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
