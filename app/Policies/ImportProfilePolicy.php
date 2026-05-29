<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ImportProfile;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class ImportProfilePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any_import_profile');
    }

    public function view(AuthUser $authUser, ImportProfile $importProfile): bool
    {
        return $authUser->can('view_import_profile');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create_import_profile');
    }

    public function update(AuthUser $authUser, ImportProfile $importProfile): bool
    {
        return $authUser->can('update_import_profile');
    }

    public function delete(AuthUser $authUser, ImportProfile $importProfile): bool
    {
        return $authUser->can('delete_import_profile');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any_import_profile');
    }

    public function restore(AuthUser $authUser, ImportProfile $importProfile): bool
    {
        return $authUser->can('restore_import_profile');
    }

    public function forceDelete(AuthUser $authUser, ImportProfile $importProfile): bool
    {
        return $authUser->can('force_delete_import_profile');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any_import_profile');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any_import_profile');
    }

    public function replicate(AuthUser $authUser, ImportProfile $importProfile): bool
    {
        return $authUser->can('replicate_import_profile');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder_import_profile');
    }
}
