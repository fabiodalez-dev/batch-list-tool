<?php

namespace App\Models\Concerns;

use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Multi-tenant scoping per RFQ §3.5.1:
 * Any model using this trait is bound to a Repository via `repository_id`,
 * and a global Eloquent scope restricts queries to the repositories the
 * authenticated user has been assigned to.
 *
 * Admin / super_admin bypass the scope (they see every repository).
 *
 * The `creating` hook below ALSO enforces mass-assignment safety:
 *   - non-privileged users cannot stamp a `repository_id` that they don't own;
 *   - if no `repository_id` is provided, we force-set it from the user's
 *     `default_repository_id` (falling back to the first repo on the pivot).
 *
 * This is why `repository_id` is excluded from `$fillable` on consumers and
 * explicitly listed in `$guarded` — the hook is the single source of truth.
 */
trait BelongsToRepository
{
    public static function bootBelongsToRepository(): void
    {
        static::addGlobalScope(new RepositoryScope);

        static::creating(function (Model $model) {
            $user = auth()->user();
            if ($user === null) {
                return; // CLI / queue / unauthenticated → trust the caller
            }

            // Privileged roles bypass tenant check, but we still default the
            // repository_id to their own default if omitted (UX nicety, not a
            // security check).
            //
            // We use `getAttribute()`/`setAttribute()` instead of magic property
            // access throughout this closure so PHPStan/Larastan don't have to
            // refine `$model` (typed as the abstract `Model`) to the concrete
            // consumer class to know `repository_id` exists — every Eloquent
            // model accepts arbitrary attribute names through these methods.
            // A model may declare that repository_id = NULL is a first-class
            // "global / shared by every repository" state (e.g. Location). When
            // the caller has EXPLICITLY set repository_id to null on such a
            // model, that is a deliberate choice — do not auto-stamp a default
            // over it. Models that don't opt in keep the old behaviour, where
            // NULL just means "not decided yet" and defaulting is correct.
            if (self::callerChoseGlobalRepository($model)) {
                return;
            }

            if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['super_admin', 'admin'])) {
                if (empty($model->getAttribute('repository_id')) && ! empty($user->default_repository_id)) {
                    $model->setAttribute('repository_id', $user->default_repository_id);
                }

                return;
            }

            // Non-privileged: must have a repository, must be one of theirs.
            $allowedIds = method_exists($user, 'repositories')
                ? $user->repositories()->pluck('repositories.id')->all()
                : [];

            if (empty($model->getAttribute('repository_id'))) {
                // Fall back to default, then first accessible
                $model->setAttribute(
                    'repository_id',
                    $user->default_repository_id ?? ($allowedIds[0] ?? null),
                );
            }

            if (! in_array((int) $model->getAttribute('repository_id'), array_map('intval', $allowedIds), true)) {
                throw new \DomainException(
                    'Multi-tenant violation: cannot create '
                    . $model::class
                    . ' in repository_id=' . ($model->getAttribute('repository_id') ?? 'null')
                    . ' — user only has access to: ' . implode(',', $allowedIds)
                );
            }
        });
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * Whether repository_id = NULL is a valid "global / shared" state for this
     * model (opt-in). Consumers that support global records override this and
     * return true; the default is false so mandatory-tenant models are
     * unaffected.
     */
    public function allowsGlobalRepository(): bool
    {
        return false;
    }

    /**
     * True when the caller has DELIBERATELY set repository_id to null on a model
     * that supports global records — i.e. an intentional global, which the
     * creating hook must not overwrite with a default. Distinguishes "explicit
     * null" (key present, value null) from "not provided" (key absent).
     */
    private static function callerChoseGlobalRepository(Model $model): bool
    {
        return method_exists($model, 'allowsGlobalRepository')
            && $model->allowsGlobalRepository()
            && array_key_exists('repository_id', $model->getAttributes())
            && $model->getAttribute('repository_id') === null;
    }
}
