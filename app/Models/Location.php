<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * RFQ §3.1.9 — Configurable Location Hierarchies and sub-locations.
 *
 * A Location is a configurable lookup that admins can shape into an arbitrary
 * tree (Adjacency List + materialised `path`). The tree can mix any of the
 * `TYPES` below — typically a "repository" at the root, then "room"s,
 * "shelf"s, "showcase"s and so on. A Location can be attached to BOTH a Box
 * and a Document (the RFQ is explicit: "at both box level and document
 * level"), letting the archive describe `Document in Showcase X` AND
 * `Box on Shelf B inside Room 3 inside Repository A` at the same time.
 *
 * Multi-tenancy: `repository_id` is NULLABLE — a Location may be global
 * (shared lab, off-site holding area). The {@see BelongsToRepository} trait
 * scopes queries to the user's repos, but global rows are also visible
 * thanks to {@see scopeForRepository()} (used by Filament Selects) and to
 * the explicit `whereNull('repository_id')` branch in the scope below.
 *
 * Sort order is intentionally per-parent: locations under the same parent
 * are siblings in a tree, so a per-parent sort is what the UI wants.
 */
class Location extends Model implements AuditableContract
{
    use Auditable;
    use BelongsToRepository;
    use HasFactory;
    use SoftDeletes;

    /**
     * The 9 location categories accepted by the form layer. Stored as a
     * VARCHAR in the DB (no enum) so both the production driver (MySQL) and
     * the test driver (SQLite) behave identically; the application-level
     * whitelist is enforced via the `type` form Select and validation rules
     * on resources that consume Location.
     */
    public const TYPES = [
        'repository',
        'room',
        'work_area',
        'shelf',
        'museum',
        'showcase',
        'conservation',
        'temp_holding',
        'other',
    ];

    /**
     * Cap on tree depth. Hierarchies deeper than this are rejected by the
     * model boot hook and treated as a configuration error (a 6-level path
     * already covers repository / room / sub-room / shelf-section / shelf /
     * slot, which is more than NRA Malta ever needs).
     */
    public const MAX_DEPTH = 6;

    protected $fillable = [
        'parent_id',
        'name',
        'code',
        'type',
        'repository_id',
        'notes',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'depth' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * Helper: returns the ancestor ids encoded in $this->path, as ints.
     * "7/12" → [7, 12]. Returns [] for root nodes.
     *
     * @return array<int, int>
     */
    public function ancestorIdsFromPath(): array
    {
        if ($this->path === null || $this->path === '') {
            return [];
        }

        return array_values(array_map('intval', explode('/', $this->path)));
    }

    /* ---------------------------------------------------------------------
     * Relations
     * ------------------------------------------------------------------- */

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function boxes(): HasMany
    {
        return $this->hasMany(Box::class, 'location_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'location_id');
    }

    /* ---------------------------------------------------------------------
     * Scopes
     * ------------------------------------------------------------------- */

    /**
     * Filter by one or more `type` values.
     *
     * @param string|array<int, string> $types
     */
    public function scopeOfType(Builder $query, string|array $types): Builder
    {
        return is_array($types)
            ? $query->whereIn('type', $types)
            : $query->where('type', $types);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Locations visible inside a given repository — explicitly INCLUDES
     * global locations (`repository_id IS NULL`). Used by the Filament
     * Selects in BoxResource / DocumentResource so that a user editing a
     * record inside Repo A still sees the global "Conservation Lab".
     */
    public function scopeForRepository(Builder $query, ?int $repositoryId): Builder
    {
        return $query->where(function (Builder $q) use ($repositoryId) {
            $q->whereNull('repository_id');
            if ($repositoryId !== null) {
                $q->orWhere('repository_id', $repositoryId);
            }
        });
    }

    /* ---------------------------------------------------------------------
     * Tree helpers
     * ------------------------------------------------------------------- */

    /**
     * All descendants (children, grandchildren, …) of $this — recursive.
     *
     * Uses the materialised `path` column for a single LIKE-prefix query.
     * For an N=1000 archive this is two orders of magnitude cheaper than
     * the naive recursive children() walk.
     *
     * @return EloquentCollection<int, Location>
     */
    public function descendants(): EloquentCollection
    {
        $prefix = $this->path !== null && $this->path !== ''
            ? $this->path . '/' . $this->getKey()
            : (string) $this->getKey();

        // Match either the immediate-child shape ("$prefix") or any deeper
        // descendant ("$prefix/…"). We bound by MAX_DEPTH as a final safety
        // net — should never trip because saving enforces it, but cheap.
        return self::query()
            ->withoutGlobalScopes()
            ->where(function (Builder $q) use ($prefix) {
                $q->where('path', $prefix)
                    ->orWhere('path', 'like', $prefix . '/%');
            })
            ->where('depth', '<=', self::MAX_DEPTH)
            ->orderBy('depth')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Ancestors of $this, ordered root-first.
     *
     * @return EloquentCollection<int, Location>
     */
    public function ancestors(): EloquentCollection
    {
        $ids = $this->ancestorIdsFromPath();
        if ($ids === []) {
            /** @var EloquentCollection<int, Location> $empty */
            $empty = new EloquentCollection;

            return $empty;
        }

        $found = self::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $ids)
            ->get();

        // `whereIn` doesn't preserve the order encoded in $ids — reorder.
        $byId = $found->keyBy('id');

        /** @var EloquentCollection<int, Location> $sorted */
        $sorted = new EloquentCollection(
            (new Collection($ids))
                ->map(fn (int $id) => $byId->get($id))
                ->filter()
                ->values()
                ->all()
        );

        return $sorted;
    }

    /**
     * Human-readable breadcrumb. Used by the Filament Selects to render
     * nested options as "Repository A / Room 3 / Shelf B" instead of
     * the bare leaf name. Bullets are doubled for tighter visual rhythm.
     */
    public function breadcrumb(string $separator = ' / '): string
    {
        $names = $this->ancestors()->pluck('name')->all();
        $names[] = $this->name;

        return implode($separator, $names);
    }

    /**
     * Whether this Location is referenced by any Box or Document.
     * Centralises the "can I delete this?" check used by the Filament
     * resource (the canDelete() guard) and by the unit tests.
     */
    public function isReferenced(): bool
    {
        return $this->boxes()->withTrashed()->exists()
            || $this->documents()->withTrashed()->exists();
    }

    /** Convenience: does this location have at least one child? */
    public function hasChildren(): bool
    {
        return $this->children()->withTrashed()->exists();
    }

    /**
     * Boot the model.
     *
     * We recompute `path` and `depth` in a saving hook (NOT an observer
     * class) so the logic stays colocated with the trait that owns it and
     * the test suite doesn't depend on the observer being registered in a
     * provider that the test boot path may not load.
     *
     * NOTE: we do NOT call $model->save() inside this hook — saving here
     * would recurse forever. We only mutate the in-memory attributes so the
     * outer save flushes them in the same INSERT/UPDATE statement.
     *
     * When `parent_id` changes on an *existing* record, we must also
     * recompute the path of every descendant — that's what `updated`
     * handles below.
     */
    protected static function booted(): void
    {
        static::saving(function (Location $location): void {
            self::recomputePath($location);
        });

        static::updated(function (Location $location): void {
            // If parent_id was just changed, descendants' paths/depths are
            // now stale. Re-walk the subtree and save each one — bypassing
            // the saving hook recursion is fine because we explicitly fix
            // path/depth before the saveQuietly call.
            if ($location->wasChanged('parent_id') || $location->wasChanged('path')) {
                self::recomputeSubtree($location);
            }
        });
    }

    /**
     * Compute `path` and `depth` from the current parent. Pure function on
     * the in-memory model — no DB writes, safe to call from `saving`.
     *
     * `path` shape:
     *   - root node                 → null
     *   - direct child of root id=7 → "7"
     *   - grandchild of id=7,id=12  → "7/12"
     *
     * We DO NOT include the node's own id in its path: descendants() then
     * matches LIKE "{parent->path}/{parent->id}/%" which is a clean prefix
     * search and avoids the "is this me or my own descendant?" ambiguity.
     *
     * @throws \DomainException when the resulting depth would exceed MAX_DEPTH.
     */
    private static function recomputePath(Location $location): void
    {
        if (empty($location->parent_id)) {
            $location->path = null;
            $location->depth = 0;

            return;
        }

        // Cycle defence: a node cannot be its own ancestor. The cheap check
        // is "parent_id !== self.id"; the deep check ("ancestor chain does
        // not include self.id") is the next block.
        if ((int) $location->parent_id === (int) $location->getKey()) {
            throw new \DomainException('Location cannot be its own parent.');
        }

        // Resolve parent WITHOUT the global RepositoryScope so a global
        // location (repository_id=null) can be a parent of a repo-scoped
        // location, and so the recompute works inside seeders/CLI/tests
        // where no user is authenticated.
        /** @var Location|null $parent */
        $parent = self::query()
            ->withoutGlobalScopes()
            ->find($location->parent_id);

        if ($parent === null) {
            // Stale parent_id — drop it and treat as root. The DB FK would
            // ultimately reject the insert with a nicer error than a null
            // dereference here.
            $location->path = null;
            $location->depth = 0;

            return;
        }

        // Deep cycle defence: walk the parent chain and refuse if our own
        // id shows up in it.
        if ($location->exists) {
            $ancestorIds = $parent->ancestorIdsFromPath();
            $ancestorIds[] = (int) $parent->getKey();
            if (in_array((int) $location->getKey(), $ancestorIds, true)) {
                throw new \DomainException(
                    'Cycle detected: location #' . $location->getKey()
                    . ' cannot be moved under one of its own descendants.'
                );
            }
        }

        $location->path = $parent->path !== null && $parent->path !== ''
            ? $parent->path . '/' . $parent->getKey()
            : (string) $parent->getKey();
        $location->depth = $parent->depth + 1;

        if ($location->depth > self::MAX_DEPTH) {
            throw new \DomainException(
                'Location hierarchy depth exceeds MAX_DEPTH ('
                . self::MAX_DEPTH . '). Restructure the tree.'
            );
        }
    }

    /**
     * Recompute path/depth for every descendant of $root. Called after a
     * parent change. Uses saveQuietly() to avoid firing audit `updated`
     * events for each descendant — the audit log records the SOURCE move
     * (the user-initiated update on $root); descendant path adjustments are
     * an implementation detail, not a business event.
     */
    private static function recomputeSubtree(Location $root): void
    {
        $rootId = (int) $root->getKey();
        $rootPath = $root->path;

        // All descendants are those whose `path` previously had $rootId in
        // its chain. We don't know the old path here, so we re-walk via
        // parent_id from scratch — bounded by MAX_DEPTH so this is O(N)
        // for the subtree, not O(tree).
        $queue = self::query()
            ->withoutGlobalScopes()
            ->where('parent_id', $rootId)
            ->get();

        foreach ($queue as $child) {
            /** @var Location $child */
            $child->path = $rootPath !== null && $rootPath !== ''
                ? $rootPath . '/' . $rootId
                : (string) $rootId;
            $child->depth = $root->depth + 1;
            $child->saveQuietly();
            self::recomputeSubtree($child);
        }
    }
}
