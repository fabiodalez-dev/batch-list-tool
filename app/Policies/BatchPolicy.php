<?php

declare(strict_types=1);

namespace App\Policies;

use Illuminate\Foundation\Auth\User as AuthUser;
use App\Models\Batch;
use Illuminate\Auth\Access\HandlesAuthorization;

class BatchPolicy
{
    use HandlesAuthorization;
    
    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any_batch');
    }

    public function view(AuthUser $authUser, Batch $batch): bool
    {
        return $authUser->can('view_batch');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create_batch');
    }

    public function update(AuthUser $authUser, Batch $batch): bool
    {
        return $authUser->can('update_batch');
    }

    public function delete(AuthUser $authUser, Batch $batch): bool
    {
        return $authUser->can('delete_batch');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any_batch');
    }

    public function restore(AuthUser $authUser, Batch $batch): bool
    {
        return $authUser->can('restore_batch');
    }

    public function forceDelete(AuthUser $authUser, Batch $batch): bool
    {
        return $authUser->can('force_delete_batch');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any_batch');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any_batch');
    }

    public function replicate(AuthUser $authUser, Batch $batch): bool
    {
        return $authUser->can('replicate_batch');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder_batch');
    }

}