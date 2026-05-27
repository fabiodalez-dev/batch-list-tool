<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Location;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class LocationPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any_location');
    }

    public function view(AuthUser $authUser, Location $location): bool
    {
        return $authUser->can('view_location');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create_location');
    }

    public function update(AuthUser $authUser, Location $location): bool
    {
        return $authUser->can('update_location');
    }

    public function delete(AuthUser $authUser, Location $location): bool
    {
        return $authUser->can('delete_location');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any_location');
    }

    public function restore(AuthUser $authUser, Location $location): bool
    {
        return $authUser->can('restore_location');
    }

    public function forceDelete(AuthUser $authUser, Location $location): bool
    {
        return $authUser->can('force_delete_location');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any_location');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any_location');
    }

    public function replicate(AuthUser $authUser, Location $location): bool
    {
        return $authUser->can('replicate_location');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder_location');
    }
}
