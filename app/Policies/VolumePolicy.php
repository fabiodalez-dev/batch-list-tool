<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Volume;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class VolumePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any_volume');
    }

    public function view(AuthUser $authUser, Volume $volume): bool
    {
        return $authUser->can('view_volume');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create_volume');
    }

    public function update(AuthUser $authUser, Volume $volume): bool
    {
        return $authUser->can('update_volume');
    }

    public function delete(AuthUser $authUser, Volume $volume): bool
    {
        return $authUser->can('delete_volume');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any_volume');
    }

    public function restore(AuthUser $authUser, Volume $volume): bool
    {
        return $authUser->can('restore_volume');
    }

    public function forceDelete(AuthUser $authUser, Volume $volume): bool
    {
        return $authUser->can('force_delete_volume');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any_volume');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any_volume');
    }

    public function replicate(AuthUser $authUser, Volume $volume): bool
    {
        return $authUser->can('replicate_volume');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder_volume');
    }
}
