<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Two-hop multi-tenant scope for `BoxMovement`:
 *
 *   box_movements.box_id ─► boxes.id
 *   boxes.batch_id        ─► batches.id
 *   batches.repository_id ─► repositories.id  (tenant key)
 *
 * Generated SQL:
 *
 *   WHERE EXISTS (
 *     SELECT boxes.id
 *       FROM boxes
 *       JOIN batches ON batches.id = boxes.batch_id
 *      WHERE boxes.id = box_movements.<box_id>
 *        AND batches.repository_id IN (...)
 *   )
 *
 * We DON'T compose with Box's own scope (i.e. we don't go through the Eloquent
 * relation) because relying on a scope-of-a-scope is fragile: any caller that
 * uses `Box::withoutGlobalScopes()` would silently widen `BoxMovement`'s
 * visibility too.
 *
 * Box has both `from_box_id` and `to_box_id` (nullable). We default to
 * checking `to_box_id` (current destination) because that is the canonical
 * "where the document ended up" key and the one most queries assert on.
 *
 * Privileged roles (`super_admin`, `admin`) bypass the scope entirely.
 *
 * @see \App\Models\Scopes\ThroughBatchRepositoryScope
 */
class ThroughBoxBatchRepositoryScope implements Scope
{
    public function __construct(
        private string $boxForeignKey = 'to_box_id',
    ) {}

    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();
        if ($user === null) {
            return; // CLI / queue / unauthenticated → no scope
        }

        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['super_admin', 'admin'])) {
            return; // admins see everything
        }

        $allowedIds = method_exists($user, 'repositories')
            ? $user->repositories()->pluck('repositories.id')->all()
            : [];

        if (empty($allowedIds)) {
            $builder->whereRaw('1=0'); // user has no repos → no records visible
            return;
        }

        $childTable    = $model->getTable();
        $boxForeignKey = $this->boxForeignKey;

        $builder->whereExists(function ($query) use ($childTable, $boxForeignKey, $allowedIds) {
            $query->select('boxes.id')
                ->from('boxes')
                ->join('batches', 'batches.id', '=', 'boxes.batch_id')
                ->whereColumn('boxes.id', $childTable . '.' . $boxForeignKey)
                ->whereIn('batches.repository_id', $allowedIds);
        });
    }
}
