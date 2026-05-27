<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Repository;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class RepositoryPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any_repository');
    }

    public function view(AuthUser $authUser, Repository $repository): bool
    {
        return $authUser->can('view_repository');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create_repository');
    }

    public function update(AuthUser $authUser, Repository $repository): bool
    {
        return $authUser->can('update_repository');
    }

    public function delete(AuthUser $authUser, Repository $repository): bool
    {
        return $authUser->can('delete_repository');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any_repository');
    }

    public function restore(AuthUser $authUser, Repository $repository): bool
    {
        return $authUser->can('restore_repository');
    }

    public function forceDelete(AuthUser $authUser, Repository $repository): bool
    {
        return $authUser->can('force_delete_repository');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any_repository');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any_repository');
    }

    public function replicate(AuthUser $authUser, Repository $repository): bool
    {
        return $authUser->can('replicate_repository');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder_repository');
    }
}
