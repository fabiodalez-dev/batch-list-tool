<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

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

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // `role` is dehydrated(false) on the form, so read it from raw state.
        $role = $this->data['role'] ?? null;

        // Defence-in-depth: reject super_admin escalation if the acting user is
        // not already a super_admin, regardless of what the form UI shows.
        if ($role === 'super_admin' && ! auth()->user()?->hasRole('super_admin')) {
            throw ValidationException::withMessages([
                'role' => __('You do not have permission to assign the super_admin role.'),
            ]);
        }

        // Self-protection: if editing own account, the role and is_active fields
        // are disabled in the UI — reject any submitted changes to them server-side.
        if ($this->record->is(auth()->user())) {
            $currentRole = $this->record->roles->first()?->name;
            if ($role !== null && $role !== $currentRole) {
                throw ValidationException::withMessages([
                    'role' => __('You cannot change your own role.'),
                ]);
            }
            // Prevent self-deactivation via a crafted request.
            $data['is_active'] = true;
        }

        return $data;
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
