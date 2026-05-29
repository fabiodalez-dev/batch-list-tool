<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DocumentType;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as AuthUser;

class DocumentTypePolicy
{
    use HandlesAuthorization;

    public function viewAny(AuthUser $authUser): bool
    {
        return $authUser->can('view_any_document_type');
    }

    public function view(AuthUser $authUser, DocumentType $documentType): bool
    {
        return $authUser->can('view_document_type');
    }

    public function create(AuthUser $authUser): bool
    {
        return $authUser->can('create_document_type');
    }

    public function update(AuthUser $authUser, DocumentType $documentType): bool
    {
        return $authUser->can('update_document_type');
    }

    public function delete(AuthUser $authUser, DocumentType $documentType): bool
    {
        return $authUser->can('delete_document_type');
    }

    public function deleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('delete_any_document_type');
    }

    public function restore(AuthUser $authUser, DocumentType $documentType): bool
    {
        return $authUser->can('restore_document_type');
    }

    public function forceDelete(AuthUser $authUser, DocumentType $documentType): bool
    {
        return $authUser->can('force_delete_document_type');
    }

    public function forceDeleteAny(AuthUser $authUser): bool
    {
        return $authUser->can('force_delete_any_document_type');
    }

    public function restoreAny(AuthUser $authUser): bool
    {
        return $authUser->can('restore_any_document_type');
    }

    public function replicate(AuthUser $authUser, DocumentType $documentType): bool
    {
        return $authUser->can('replicate_document_type');
    }

    public function reorder(AuthUser $authUser): bool
    {
        return $authUser->can('reorder_document_type');
    }
}
