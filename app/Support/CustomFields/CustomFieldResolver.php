<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

use App\Models\CustomFieldDefinition;
use App\Support\ActiveRepository;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Central resolver for active custom-field definitions.
 *
 * Spec §1 — kills the duplication across DocumentImporter,
 * ListDocuments, TemplateGenerator, and future exporters/importers
 * for Batch, Box, and Volume.
 *
 * Two responsibilities:
 *   1. Determine the active repository id for the current request
 *      (the topbar switcher via ActiveRepository; fallback to the
 *      authenticated user's default_repository_id; else null).
 *   2. Return the active CustomFieldDefinition collection for an
 *      entity type in that repository, ordered by sort_order.
 *      Results are memoised per (repoId|entityType) to avoid repeat
 *      queries within one request or import chunk.
 */
final class CustomFieldResolver
{
    /**
     * Request-level memo: keyed "{repoId}:{entityType}".
     * Populated on first call; reset between tests via flush().
     *
     * @var array<string, EloquentCollection<int, CustomFieldDefinition>>
     */
    private static array $cache = [];

    /**
     * Active repository id for the current request.
     *
     * Resolution order:
     *   1. app(ActiveRepository::class)->id() when the container resolves the
     *      class and the session carries an explicit selection.
     *   2. auth()->user()?->default_repository_id as the fallback (no session,
     *      queue worker, CLI running as a user).
     *   3. null when unauthenticated or running in CLI context with no user.
     */
    public static function activeRepositoryId(): ?int
    {
        // Guard: the container resolution of ActiveRepository is the ONLY
        // operation wrapped here. If the container cannot bind the class
        // (e.g. a CLI/queue context where the service provider is absent)
        // we fall through to the user default. An exception thrown by
        // $ar->id() itself MUST propagate — a bad id() means a real
        // tenant-identity error and must never silently fall back to the
        // wrong repository.
        $ar = null;

        try {
            /** @var ActiveRepository|null $resolved */
            $resolved = resolve(ActiveRepository::class);
            $ar = $resolved;
        } catch (\Throwable) {
            // Container cannot resolve ActiveRepository (e.g. CLI test
            // context without a service provider) — fall through to default.
        }

        if ($ar !== null) {
            // id() is called OUTSIDE the try-catch — a failing id() must
            // propagate (wrong tenant is worse than a crash).
            $id = $ar->id();
            if ($id !== null) {
                return $id;
            }
        }

        // Fallback: the user's persisted default repository (queue workers,
        // CLI commands that authenticate as a user, unauthenticated requests).
        $user = auth()->user();
        if ($user === null) {
            return null;
        }

        $default = $user->getAttribute('default_repository_id');

        return $default !== null ? (int) $default : null;
    }

    /**
     * Active definitions for $entityType in the resolved repository,
     * ordered by sort_order.
     *
     * Returns an empty collection when the resolved repository id is null
     * or when no active definitions exist — safe to iterate unconditionally.
     *
     * Results are request-memoised per (repoId|entityType) to avoid N+1
     * in exporters that call this once per row. Call flush() in tests
     * to reset the cache between scenarios.
     *
     * @param string $entityType One of 'document', 'batch', 'box', 'volume'.
     * @return EloquentCollection<int, CustomFieldDefinition>
     */
    public static function definitionsFor(string $entityType): EloquentCollection
    {
        $repoId = self::activeRepositoryId();

        if ($repoId === null) {
            return new EloquentCollection;
        }

        $cacheKey = "{$repoId}:{$entityType}";

        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }

        self::$cache[$cacheKey] = CustomFieldDefinition::query()
            ->where('repository_id', $repoId)
            ->where('entity_type', $entityType)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return self::$cache[$cacheKey];
    }

    /**
     * Reset the request-level memo. Call this in tests between scenarios
     * that change the active repository or switch users.
     */
    public static function flush(): void
    {
        self::$cache = [];
    }
}
