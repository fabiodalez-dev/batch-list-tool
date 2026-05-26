<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Series;
use Illuminate\Auth\Access\HandlesAuthorization;

class SeriesPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any_series');
    }

    public function view(AuthUser $authUser, Series $series): bool
    {
        return $authUser->can('view_series');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create_series');
    }

    public function update(AuthUser $authUser, Series $series): bool
    {
        return $authUser->can('update_series');
    }

    public function delete(AuthUser $authUser, Series $series): bool
    {
        return $authUser->can('delete_series');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any_series');
    }

    public function restore(AuthUser $authUser, Series $series): bool
    {
        return $authUser->can('restore_series');
    }

    public function forceDelete(AuthUser $authUser, Series $series): bool
    {
        return $authUser->can('force_delete_series');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any_series');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any_series');
    }

    public function replicate(AuthUser $authUser, Series $series): bool
    {
        return $authUser->can('replicate_series');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder_series');
    }

}