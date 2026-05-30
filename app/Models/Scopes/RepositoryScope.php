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

        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['super_admin', 'admin'])) {
            return; // admins see everything
        }

        // Build the allowed repository ids: pivot table + default_repository_id
        $allowed = collect();
        if (method_exists($user, 'repositories')) {
            $allowed = $user->repositories()->pluck('repositories.id');
        }
        if (! empty($user->default_repository_id)) {
            $allowed = $allowed->push($user->default_repository_id);
        }
        $allowed = $allowed->unique()->values()->all();

        if (empty($allowed)) {
            // User assigned to no repository → see nothing
            $builder->whereRaw('1 = 0');

            return;
        }

        // Active-repository narrowing. The resolver only ever returns an id the
        // user is allowed to scope to (or null = All), so intersecting is safe.
        $active = app(ActiveRepository::class)->id();
        if ($active !== null && in_array($active, array_map('intval', $allowed), true)) {
            $builder->where($model->getTable() . '.repository_id', $active);

            return;
        }

        $builder->whereIn($model->getTable() . '.repository_id', $allowed);
    }
}
