<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        // `role` is dehydrated(false) on the form, so sync it explicitly from
        // the raw form state into spatie/laravel-permission.
        $role = $this->data['role'] ?? null;

        if ($role !== null) {
            $this->record->syncRoles([$role]);
        }
    }
}
