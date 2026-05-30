<?php

namespace App\Models\Scopes;

use App\Support\ActiveRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Multi-tenant scope for models that do NOT carry `repository_id` directly
 * but belong (one-hop) to a parent that does.
 *
 * Typical use: `Box` belongs to `Batch`, and `batches.repository_id` is the
 * tenant key. We restrict the query with:
 *
 *   WHERE EXISTS (
 *     SELECT 1 FROM batches
 *     WHERE batches.id = boxes.batch_id
 *       AND batches.repository_id IN (...)
 *   )
 *
 * Privileged roles (`super_admin`, `admin`) bypass the membership restriction
 * entirely — BUT they still honour an explicit active-repository narrowing
 * (RFQ Wave 2 Task 10) so the topbar switcher works for them too.
 * Unauthenticated context (CLI, queue) bypasses, mirroring RepositoryScope.
 *
 * @see RepositoryScope
 */
class ThroughBatchRepositoryScope implements Scope
{
    public function __construct(
        private string $foreignTable = 'batches',
        private string $foreignKey = 'batch_id',
    ) {}

    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();
        if ($user === null) {
            return; // CLI / queue / unauthenticated → no scope
        }

        $active = app(ActiveRepository::class)->id();

        // Allowed set computed from the SAME source of truth as RepositoryScope
        // (pivot ∪ default_repository_id), so the two scopes can never diverge
        // — a user whose only access is via default_repository_id (empty pivot)
        // now sees that repository's boxes too (review I1 / Fix 3). null →
        // privileged: no membership restriction.
        $allowedIds = ActiveRepository::allowedRepositoryIdsFor($user);

        if ($allowedIds === null) {
            // Privileged: no membership restriction, but still honour an
            // explicit active-repository narrowing chosen via the switcher.
            if ($active !== null) {
                $this->applyExists($builder, $model, [$active]);
            }

            return;
        }

        if (empty($allowedIds)) {
            $builder->whereRaw('1=0'); // user has no repos → no records visible

            return;
        }

        // Active-repository narrowing INTERSECTED with the allowed set — a
        // stale/revoked active id (not in $allowedIds) is ignored and we fall
        // back to the full allowed set. Never widens, never exposes a forbidden
        // repo, never goes empty on a bad id. null (All) keeps the full set.
        if ($active !== null && in_array($active, array_map('intval', $allowedIds), true)) {
            $allowedIds = [$active];
        }

        $this->applyExists($builder, $model, $allowedIds);
    }

    /**
     * @param list<int|string> $repositoryIds
     */
    private function applyExists(Builder $builder, Model $model, array $repositoryIds): void
    {
        $foreignTable = $this->foreignTable;
        $foreignKey = $this->foreignKey;
        $childTable = $model->getTable();

        $builder->whereExists(function ($query) use ($foreignTable, $foreignKey, $childTable, $repositoryIds) {
            $query->select($foreignTable . '.id')
                ->from($foreignTable)
                ->whereColumn($foreignTable . '.id', $childTable . '.' . $foreignKey)
                ->whereIn($foreignTable . '.repository_id', $repositoryIds);
        });
    }
}
