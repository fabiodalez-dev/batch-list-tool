<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Session;

/**
 * Resolver for the user's *active* repository (RFQ Wave 2 Task 10,
 * Submission §4.3.3).
 *
 * The active repository is a per-session UI scope filter layered ON TOP of the
 * existing multi-tenant security scope (RepositoryScope / ThroughBatchRepositoryScope):
 *
 *   - active = null  → "All repositories": the global scopes behave exactly as
 *                      they do today (whereIn(<all the user's allowed repos>)).
 *                      This is the EXPAND-NEVER-RESTRICT default — current
 *                      behaviour is preserved.
 *   - active = <id>  → narrow to that single repository, provided it is within
 *                      the user's allowed set. An out-of-bounds / unknown id is
 *                      rejected and falls back to null (All).
 *
 * It is purely a *narrowing* convenience: it can never widen visibility beyond
 * what the security scope already permits. The security scope remains the
 * source of truth for tenancy; this only further restricts the result set.
 *
 * Persistence:
 *   - primary store: the session (key SESSION_KEY)
 *   - mirror: when an authenticated user is present and the `users` table has
 *     an `active_repository_id` column, the choice is mirrored there so it
 *     survives across sessions / devices. The session always wins on read.
 */
class ActiveRepository
{
    public const SESSION_KEY = 'active_repository_id';

    /**
     * The currently active repository id, or null for "All repositories".
     *
     * Returns a value ONLY when the user has EXPLICITLY selected a repository
     * this session (the session key is present). When nothing is explicitly
     * selected, this returns null ("All repositories") for EVERYONE — including
     * privileged admin / super_admin users, so their cross-repository oversight
     * is never silently narrowed by a stale session, a persisted preference, or
     * a default repository.
     *
     * Cross-session restore of the last explicit choice is a deliberate,
     * explicit step (see {@see restoreFromPreference()}), invoked at a session
     * boundary — never as an implicit side effect of a scope read. This keeps
     * the EXPAND-NEVER-RESTRICT default honest: an unselected scope is "All".
     *
     * The stored value is validated against the user's allowed repositories on
     * every read — fail-closed: an id the user can no longer access resolves to
     * null (All).
     */
    public function id(): ?int
    {
        if (! Session::has(self::SESSION_KEY)) {
            // Nothing EXPLICITLY selected this session → "All repositories".
            return null;
        }

        $stored = Session::get(self::SESSION_KEY);
        $value = $stored === null ? null : (int) $stored;

        return $this->sanitise($value);
    }

    /**
     * Restore the last explicit choice from the user's persisted preference
     * into the session, when the session does not already carry one. This is
     * the EXPLICIT cross-session continuity hook — call it from a session
     * boundary (e.g. a post-login listener / middleware), NOT from a scope.
     *
     * Returns the resolved active id (validated against the allowed set).
     */
    public function restoreFromPreference(): ?int
    {
        if (Session::has(self::SESSION_KEY)) {
            return $this->id();
        }

        $value = $this->sanitise($this->persistedPreference());
        Session::put(self::SESSION_KEY, $value);

        return $value;
    }

    /**
     * Set the active repository.
     *
     * `null` selects "All repositories". A specific id is accepted only when it
     * is within the current user's allowed repositories; otherwise it is
     * silently coerced to null (All) — fail-closed, never widen.
     *
     * The chosen value is written to the session and mirrored to the user's
     * persisted preference (best-effort) for cross-session continuity.
     */
    public function set(?int $repositoryId): ?int
    {
        $value = $this->sanitise($repositoryId);

        Session::put(self::SESSION_KEY, $value);
        $this->persist($value);

        return $value;
    }

    /**
     * SINGLE SOURCE OF TRUTH for "the repository ids this user may access".
     *
     * Folds together BOTH access paths (RFQ §3.5.1):
     *   - the `repository_user` pivot, and
     *   - `users.default_repository_id`
     *
     * so a user whose access is granted ONLY via a default repository (empty
     * pivot) is still allowed there. Both global scopes (RepositoryScope and
     * ThroughBatchRepositoryScope) and this resolver MUST derive their allowed
     * set from here, so they can never diverge again (review I1 / Fix 3).
     *
     * Returns:
     *   - null  → no membership-based restriction (privileged role, or no
     *             authenticated user / CLI / queue): caller treats as "all".
     *   - int[] → the explicit, de-duplicated allowed set (possibly empty →
     *             the caller must fail closed: the user can see nothing).
     *
     * @return list<int>|null
     */
    public static function allowedRepositoryIdsFor(?Authenticatable $user): ?array
    {
        if ($user === null) {
            return null; // CLI / queue / unauthenticated → no narrowing constraint
        }

        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(['super_admin', 'admin'])) {
            return null; // privileged: may scope to any repository
        }

        $ids = collect();
        if (method_exists($user, 'repositories')) {
            $ids = $user->repositories()->pluck('repositories.id');
        }
        if (! empty($user->default_repository_id)) {
            $ids = $ids->push($user->default_repository_id);
        }

        return $ids->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Validate a candidate id against the current user's allowed repositories.
     * Returns the id when allowed, or null (All) otherwise.
     */
    private function sanitise(?int $repositoryId): ?int
    {
        if ($repositoryId === null) {
            return null;
        }

        $allowed = $this->allowedRepositoryIds();

        // Privileged users (admin / super_admin) have no membership rows but
        // may scope to any repository — they are allowed to pick any id.
        if ($allowed === null) {
            return $repositoryId;
        }

        return in_array($repositoryId, $allowed, true) ? $repositoryId : null;
    }

    /**
     * The ids the current user may scope to.
     *
     * Returns:
     *   - null  → no membership-based restriction (admin / super_admin /
     *             unauthenticated): any id may be selected.
     *   - int[] → the explicit set of allowed repository ids (may be empty,
     *             in which case only "All" is selectable).
     *
     * @return list<int>|null
     */
    private function allowedRepositoryIds(): ?array
    {
        return self::allowedRepositoryIdsFor(auth()->user());
    }

    /**
     * Read the active repository persisted on the user record, when the column
     * exists. Best-effort: any failure resolves to null (All).
     */
    private function persistedPreference(): ?int
    {
        $user = auth()->user();
        if ($user === null) {
            return null;
        }

        $value = $user->getAttribute('active_repository_id');

        return $value === null ? null : (int) $value;
    }

    /**
     * Mirror the active repository onto the user record for cross-session
     * persistence. Best-effort and silent — a missing column or write failure
     * must never surface to the request.
     */
    private function persist(?int $repositoryId): void
    {
        $user = auth()->user();
        if ($user === null) {
            return;
        }

        try {
            if (! method_exists($user, 'getConnection')) {
                return;
            }

            $hasColumn = $user->getConnection()
                ->getSchemaBuilder()
                ->hasColumn($user->getTable(), 'active_repository_id');

            if (! $hasColumn) {
                return;
            }

            // Avoid a redundant write when the stored value is already correct.
            $current = $user->getAttribute('active_repository_id');
            $current = $current === null ? null : (int) $current;
            if ($current === $repositoryId) {
                return;
            }

            $user->forceFill(['active_repository_id' => $repositoryId])->saveQuietly();
        } catch (\Throwable) {
            // Persistence is a nicety, never a hard requirement.
        }
    }
}
