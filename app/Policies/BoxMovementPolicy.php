<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\BoxMovement;
use Illuminate\Auth\Access\HandlesAuthorization;

class BoxMovementPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any_box_movement');
    }

    public function view(AuthUser $authUser, BoxMovement $boxMovement): bool
    {
        return $authUser->can('view_box_movement');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create_box_movement');
    }

    public function update(AuthUser $authUser, BoxMovement $boxMovement): bool
    {
        return $authUser->can('update_box_movement');
    }

    public function delete(AuthUser $authUser, BoxMovement $boxMovement): bool
    {
        return $authUser->can('delete_box_movement');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any_box_movement');
    }

    public function restore(AuthUser $authUser, BoxMovement $boxMovement): bool
    {
        return $authUser->can('restore_box_movement');
    }

    public function forceDelete(AuthUser $authUser, BoxMovement $boxMovement): bool
    {
        return $authUser->can('force_delete_box_movement');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any_box_movement');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any_box_movement');
    }

    public function replicate(AuthUser $authUser, BoxMovement $boxMovement): bool
    {
        return $authUser->can('replicate_box_movement');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder_box_movement');
    }

}