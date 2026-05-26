<?php

namespace App\Policies;

use App\Models\DocumentFlag;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Policy for {@see DocumentFlag} — RFQ §3.1.12.
 *
 * Permission names follow the filament-shield convention used elsewhere in
 * the codebase for compound model names (cf. `BoxMovementPolicy`):
 *   view_any_document::flag, view_document::flag, create_document::flag, etc.
 *
 * The `resolve` permission is a custom workflow gate — separate from
 * `update` because a reviewer may be allowed to close a flag without being
 * allowed to edit its content.
 */
class DocumentFlagPolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user): bool
    {
        return $user->can('view_any_document::flag');
    }

    public function view(User $user, DocumentFlag $documentFlag): bool
    {
        return $user->can('view_document::flag');
    }

    public function create(User $user): bool
    {
        return $user->can('create_document::flag');
    }

    public function update(User $user, DocumentFlag $documentFlag): bool
    {
        return $user->can('update_document::flag');
    }

    public function delete(User $user, DocumentFlag $documentFlag): bool
    {
        return $user->can('delete_document::flag');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('delete_any_document::flag');
    }

    public function forceDelete(User $user, DocumentFlag $documentFlag): bool
    {
        return $user->can('force_delete_document::flag');
    }

    public function forceDeleteAny(User $user): bool
    {
        return $user->can('force_delete_any_document::flag');
    }

    public function restore(User $user, DocumentFlag $documentFlag): bool
    {
        return $user->can('restore_document::flag');
    }

    public function restoreAny(User $user): bool
    {
        return $user->can('restore_any_document::flag');
    }

    public function replicate(User $user, DocumentFlag $documentFlag): bool
    {
        return $user->can('replicate_document::flag');
    }

    public function reorder(User $user): bool
    {
        return $user->can('reorder_document::flag');
    }

    /**
     * Custom workflow gate — RFQ §3.1.12.
     *
     * Used by the FlagsRelationManager and DocumentFlagResource to gate
     * the "Mark resolved" / "Mark dismissed" / "Mark acknowledged" actions
     * independently of the generic `update` permission.
     */
    public function resolve(User $user, DocumentFlag $documentFlag): bool
    {
        return $user->can('resolve_document::flag') || $user->can('update_document::flag');
    }
}
