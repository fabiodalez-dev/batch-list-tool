<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

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
        $role = $this->data['role'] ?? null;

        // Defence-in-depth: reject super_admin escalation if the acting user is
        // not already a super_admin, regardless of what the form UI shows.
        if ($role === 'super_admin' && ! auth()->user()?->hasRole('super_admin')) {
            throw ValidationException::withMessages([
                'role' => __('You do not have permission to assign the super_admin role.'),
            ]);
        }

        $this->assignedRole = $role;

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
