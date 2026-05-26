<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Apply eager-load `with(...)` only when the result-set is big enough
 * that the SELECT IN (...) round-trip pays for itself.
 *
 * Heuristic: below ~200 rows, individual lazy loads (1 + N small SELECTs
 * each fetching one row by primary key) cost less wall-clock time than
 * the eager batch SELECT IN (id, id, id, ...) on the parent's filtered
 * id-set. The threshold matches Filament's default page-size buckets
 * (10 / 25 / 50 / 100 / "all") — anything past "100" is the regime where
 * eager loading actually helps.
 *
 * The count is run on a *clone* of the current query (so any existing
 * where() / whereHas() filters apply) and is hard-capped with
 * LIMIT $threshold + 1 — once MySQL sees the (threshold + 1)-th row it
 * stops counting, so the probe stays sub-millisecond regardless of the
 * table's true size.
 */
trait ConditionallyPreloadsRelations
{
    /**
     * @param array<int, string> $relations e.g. ['authorities', 'series', 'currentBox.batch']
     */
    public function scopeConditionallyWith(
        Builder $query,
        array $relations,
        int $threshold = 200,
    ): Builder {
        // Bail out fast on a no-op call: keeps the scope safely chainable
        // even when the caller has nothing to preload (e.g. a feature flag
        // turned the relation list into []).
        if ($relations === []) {
            return $query;
        }

        // Clone before count() — count() mutates the underlying query
        // (it resets the ORDER BY / SELECT columns) and we want the
        // outer builder untouched for the actual fetch downstream.
        $probe = clone $query;

        $cheapCount = $probe->limit($threshold + 1)->count();

        if ($cheapCount > $threshold) {
            return $query->with($relations);
        }

        return $query;
    }
}
