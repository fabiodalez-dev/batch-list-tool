<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;
use OwenIt\Auditing\Models\Audit;

class AuditPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any_audit');
    }

    public function view(AuthUser $authUser, Audit $audit): bool
    {
        return $authUser->can('view_audit');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create_audit');
    }

    public function update(AuthUser $authUser, Audit $audit): bool
    {
        return $authUser->can('update_audit');
    }

    public function delete(AuthUser $authUser, Audit $audit): bool
    {
        return $authUser->can('delete_audit');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any_audit');
    }

    public function restore(AuthUser $authUser, Audit $audit): bool
    {
        return $authUser->can('restore_audit');
    }

    public function forceDelete(AuthUser $authUser, Audit $audit): bool
    {
        return $authUser->can('force_delete_audit');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any_audit');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any_audit');
    }

    public function replicate(AuthUser $authUser, Audit $audit): bool
    {
        return $authUser->can('replicate_audit');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder_audit');
    }
}
