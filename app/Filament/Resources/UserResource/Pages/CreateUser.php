<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * Remember the chosen role across the mutate → afterCreate boundary so it
     * can be synced into spatie/laravel-permission once the record exists.
     */
    protected ?string $assignedRole = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // `role` is dehydrated(false) on the form, so it never reaches $data —
        // read it from the raw form state instead.
        $this->assignedRole = $this->data['role'] ?? null;

        // New users always start with a forced password change.
        $data['must_change_password'] = true;

        return $data;
    }

    protected function afterCreate(): void
    {
        if ($this->assignedRole !== null) {
            $this->record->syncRoles([$this->assignedRole]);
        }
    }
}
