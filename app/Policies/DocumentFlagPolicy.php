<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DocumentFlag;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class DocumentFlagPolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any_document_flag');
    }

    public function view(AuthUser $authUser, DocumentFlag $documentFlag): bool
    {
        return $authUser->can('view_document_flag');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create_document_flag');
    }

    public function update(AuthUser $authUser, DocumentFlag $documentFlag): bool
    {
        return $authUser->can('update_document_flag');
    }

    public function delete(AuthUser $authUser, DocumentFlag $documentFlag): bool
    {
        return $authUser->can('delete_document_flag');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any_document_flag');
    }

    public function restore(AuthUser $authUser, DocumentFlag $documentFlag): bool
    {
        return $authUser->can('restore_document_flag');
    }

    public function forceDelete(AuthUser $authUser, DocumentFlag $documentFlag): bool
    {
        return $authUser->can('force_delete_document_flag');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any_document_flag');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any_document_flag');
    }

    public function replicate(AuthUser $authUser, DocumentFlag $documentFlag): bool
    {
        return $authUser->can('replicate_document_flag');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder_document_flag');
    }
}
