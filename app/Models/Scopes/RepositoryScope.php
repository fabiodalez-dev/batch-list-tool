<?php

namespace App\Models\Scopes;

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

        $builder->whereIn($model->getTable() . '.repository_id', $allowed);
    }
}
