<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class UserPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $u): bool
    {
        return $u->can('view_any_user');
    }

    public function view(AuthUser $u, User $m): bool
    {
        return $u->can('view_user');
    }

    public function create(AuthUser $u): bool
    {
        return $u->can('create_user');
    }

    public function update(AuthUser $u, User $m): bool
    {
        if (! $u->can('update_user')) {
            return false;
        }
        if ($m->hasRole('super_admin') && ! $u->hasRole('super_admin')) {
            return false;
        }

        return true;
    }

    public function delete(AuthUser $u, User $m): bool
    {
        if (! $u->can('delete_user')) {
            return false;
        }
        if (method_exists($u, 'is') && $u->is($m)) {
            return false; // no self-delete
        }
        if ($m->hasRole('super_admin') && ! $u->hasRole('super_admin')) {
            return false;
        }

        return true;
    }

    public function deleteAny(AuthUser $u): bool
    {
        return $u->can('delete_any_user');
    }

    public function restore(AuthUser $u, User $m): bool
    {
        return $u->can('restore_user');
    }

    public function forceDelete(AuthUser $u, User $m): bool
    {
        return $u->can('force_delete_user') && ! (method_exists($u, 'is') && $u->is($m));
    }

    public function forceDeleteAny(AuthUser $u): bool
    {
        return $u->can('force_delete_any_user');
    }

    public function restoreAny(AuthUser $u): bool
    {
        return $u->can('restore_any_user');
    }

    public function replicate(AuthUser $u, User $m): bool
    {
        return $u->can('replicate_user');
    }

    public function reorder(AuthUser $u): bool
    {
        return $u->can('reorder_user');
    }
}
