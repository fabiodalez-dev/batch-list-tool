<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Box;
use Illuminate\Auth\Access\HandlesAuthorization;

class BoxPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any_box');
    }

    public function view(AuthUser $authUser, Box $box): bool
    {
        return $authUser->can('view_box');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create_box');
    }

    public function update(AuthUser $authUser, Box $box): bool
    {
        return $authUser->can('update_box');
    }

    public function delete(AuthUser $authUser, Box $box): bool
    {
        return $authUser->can('delete_box');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any_box');
    }

    public function restore(AuthUser $authUser, Box $box): bool
    {
        return $authUser->can('restore_box');
    }

    public function forceDelete(AuthUser $authUser, Box $box): bool
    {
        return $authUser->can('force_delete_box');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any_box');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any_box');
    }

    public function replicate(AuthUser $authUser, Box $box): bool
    {
        return $authUser->can('replicate_box');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder_box');
    }

}