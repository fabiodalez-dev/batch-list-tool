<?php

namespace App\Models\Concerns;

use App\Models\Scopes\RepositoryScope;
use App\Models\Repository;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Multi-tenant scoping per RFQ §3.5.1:
 * Any model using this trait is bound to a Repository via `repository_id`,
 * and a global Eloquent scope restricts queries to the repositories the
 * authenticated user has been assigned to.
 *
 * Admin / super_admin bypass the scope (they see every repository).
 */
trait BelongsToRepository
{
    public static function bootBelongsToRepository(): void
    {
        static::addGlobalScope(new RepositoryScope);

        // Default repository_id on new records to the authenticated user's default
        static::creating(function ($model) {
            if (empty($model->repository_id) && auth()->check()) {
                $defaultId = auth()->user()->default_repository_id;
                if ($defaultId) {
                    $model->repository_id = $defaultId;
                }
            }
        });
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }
}
