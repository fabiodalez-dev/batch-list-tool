<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Practice;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class PracticePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any_practice');
    }

    public function view(AuthUser $authUser, Practice $practice): bool
    {
        return $authUser->can('view_practice');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create_practice');
    }

    public function update(AuthUser $authUser, Practice $practice): bool
    {
        return $authUser->can('update_practice');
    }

    public function delete(AuthUser $authUser, Practice $practice): bool
    {
        return $authUser->can('delete_practice');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any_practice');
    }

    public function restore(AuthUser $authUser, Practice $practice): bool
    {
        return $authUser->can('restore_practice');
    }

    public function forceDelete(AuthUser $authUser, Practice $practice): bool
    {
        return $authUser->can('force_delete_practice');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any_practice');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any_practice');
    }

    public function replicate(AuthUser $authUser, Practice $practice): bool
    {
        return $authUser->can('replicate_practice');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder_practice');
    }
}
