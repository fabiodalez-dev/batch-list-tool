<?php

namespace App\Models\Scopes;

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
 * Privileged roles (`super_admin`, `admin`) bypass the scope entirely.
 * Unauthenticated context (CLI, queue) also bypasses, mirroring
 * RepositoryScope's behaviour.
 *
 * @see \App\Models\Scopes\RepositoryScope
 * @see \App\Models\Scopes\ThroughBoxBatchRepositoryScope
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

        $foreignTable = $this->foreignTable;
        $foreignKey   = $this->foreignKey;
        $childTable   = $model->getTable();

        $builder->whereExists(function ($query) use ($foreignTable, $foreignKey, $childTable, $allowedIds) {
            $query->select($foreignTable . '.id')
                ->from($foreignTable)
                ->whereColumn($foreignTable . '.id', $childTable . '.' . $foreignKey)
                ->whereIn($foreignTable . '.repository_id', $allowedIds);
        });
    }
}
