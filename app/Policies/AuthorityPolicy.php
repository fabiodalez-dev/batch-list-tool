<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Authority;
use Illuminate\Auth\Access\HandlesAuthorization;

class AuthorityPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any_authority');
    }

    public function view(AuthUser $authUser, Authority $authority): bool
    {
        return $authUser->can('view_authority');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create_authority');
    }

    public function update(AuthUser $authUser, Authority $authority): bool
    {
        return $authUser->can('update_authority');
    }

    public function delete(AuthUser $authUser, Authority $authority): bool
    {
        return $authUser->can('delete_authority');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any_authority');
    }

    public function restore(AuthUser $authUser, Authority $authority): bool
    {
        return $authUser->can('restore_authority');
    }

    public function forceDelete(AuthUser $authUser, Authority $authority): bool
    {
        return $authUser->can('force_delete_authority');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any_authority');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any_authority');
    }

    public function replicate(AuthUser $authUser, Authority $authority): bool
    {
        return $authUser->can('replicate_authority');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder_authority');
    }

}