<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Accession;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class AccessionPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any_accession');
    }

    public function view(AuthUser $authUser, Accession $accession): bool
    {
        return $authUser->can('view_accession');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create_accession');
    }

    public function update(AuthUser $authUser, Accession $accession): bool
    {
        return $authUser->can('update_accession');
    }

    public function delete(AuthUser $authUser, Accession $accession): bool
    {
        return $authUser->can('delete_accession');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any_accession');
    }

    public function restore(AuthUser $authUser, Accession $accession): bool
    {
        return $authUser->can('restore_accession');
    }

    public function forceDelete(AuthUser $authUser, Accession $accession): bool
    {
        return $authUser->can('force_delete_accession');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any_accession');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any_accession');
    }

    public function replicate(AuthUser $authUser, Accession $accession): bool
    {
        return $authUser->can('replicate_accession');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder_accession');
    }
}
