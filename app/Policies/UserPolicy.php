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

    public function update(User $u, User $m): bool
    {
        if (! $u->can('update_user')) {
            return false;
        }
        if ($m->hasRole('super_admin') && ! $u->hasRole('super_admin')) {
            return false;
        }

        return true;
    }

    public function delete(User $u, User $m): bool
    {
        if (! $u->can('delete_user')) {
            return false;
        }
        if ($u->is($m)) {
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

    public function forceDelete(User $u, User $m): bool
    {
        if (! $u->can('force_delete_user') || $u->is($m)) {
            return false;
        }

        // Anti-escalation: a non-super_admin cannot force-delete a super_admin
        // (mirrors the update/delete guards).
        if ($m->hasRole('super_admin') && ! $u->hasRole('super_admin')) {
            return false;
        }

        return true;
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
