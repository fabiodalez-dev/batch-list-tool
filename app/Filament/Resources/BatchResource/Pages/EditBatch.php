<?php

namespace App\Filament\Resources\BatchResource\Pages;

use App\Filament\Concerns\HandlesCustomFieldForm;
use App\Filament\Resources\BatchResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBatch extends EditRecord
{
    use HandlesCustomFieldForm;

    protected static string $resource = BatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
