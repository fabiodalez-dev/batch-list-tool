<?php

namespace App\Filament\Resources\BoxResource\Pages;

use App\Filament\Concerns\HandlesCustomFieldForm;
use App\Filament\Resources\BoxResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBox extends EditRecord
{
    use HandlesCustomFieldForm;

    protected static string $resource = BoxResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
