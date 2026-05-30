<?php

namespace App\Models\Scopes;

use App\Support\ActiveRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts queries to the repositories the authenticated user has been
 * assigned to (via the `repository_user` pivot or `users.default_repository_id`).
 *
 * Bypassed when:
 *   - no authenticated user (console, queue, tests without acting-as)
 *   - the user has the `super_admin` or `admin` role (cross-repo view)
 *   - the query has been explicitly opted out via `withoutGlobalScope`
 *
 * Active-repository narrowing (RFQ Wave 2 Task 10): on top of the allowed set,
 * if the user has selected a single *active* repository (session-backed, via
 * App\Support\ActiveRepository) the query is further narrowed to that one repo.
 * active = null means "All repositories" → unchanged behaviour. The narrowing
 * is always intersected with the allowed set, so it can never widen access.
 *
 * RFQ §3.5.1.
 */
class RepositoryScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();
        if (! $user) {
            return; // CLI / queue / unauthenticated → no scope
        }

        $active = app(ActiveRepository::class)->id();

        // Allowed set = pivot ∪ default_repository_id (shared source of truth,
        // so this scope can never diverge from ThroughBatchRepositoryScope /
        // ActiveRepository). null → privileged: no membership restriction.
        $allowed = ActiveRepository::allowedRepositoryIdsFor($user);
        if ($allowed === null) {
            // Privileged (admin / super_admin): no membership restriction, but
            // still honour an explicit active-repository narrowing chosen via
            // the topbar switcher — matching ThroughBatchRepositoryScope so an
            // admin who picks one repo sees a CONSISTENT view across Documents,
            // Batches and Boxes. active = null (All) → unchanged: see all.
            if ($active !== null) {
                $builder->where($model->getTable() . '.repository_id', $active);
            }

            return;
        }

        if (empty($allowed)) {
            // User assigned to no repository → see nothing (fail closed).
            $builder->whereRaw('1 = 0');

            return;
        }

        // Active-repository narrowing, INTERSECTED with the allowed set: a
        // stale/revoked active id (not in $allowed) is ignored and we fall
        // back to the full allowed set — never widen, never expose a forbidden
        // repo, never go empty on a bad id.
        if ($active !== null && in_array($active, array_map('intval', $allowed), true)) {
            $builder->where($model->getTable() . '.repository_id', $active);

            return;
        }

        $builder->whereIn($model->getTable() . '.repository_id', $allowed);
    }
}
