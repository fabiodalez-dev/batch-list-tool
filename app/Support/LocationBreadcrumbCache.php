<?php

declare(strict_types=1);

namespace App\Support;

use App\Filament\Concerns\ResolvesEffectiveLocationBreadcrumb;
use App\Models\Location;

/**
 * Request-scoped memo for a Location's full-path breadcrumb, keyed by location
 * id.
 *
 * Location::full_path → breadcrumb() → ancestors() runs one `whereIn` per call.
 * A list page renders a page of rows that share a handful of distinct
 * locations, so caching by id collapses O(rows) ancestor queries to
 * O(distinct locations). Unlike the {@see ResolvesEffectiveLocationBreadcrumb}
 * trait (instance-scoped, for the Livewire report pages), this static cache is
 * usable from a static Resource table() closure regardless of the render
 * context (list page, relation manager, export).
 *
 * Scope note: the memo is process-static. On this deployment (php-fpm/cgi, one
 * process per web request) that is naturally request-scoped; breadcrumb columns
 * are never rendered from a long-lived worker. Call {@see flush()} if a caller
 * ever needs to force a re-read within the same process.
 */
final class LocationBreadcrumbCache
{
    /** @var array<int, string> */
    private static array $memo = [];

    /** @var array<string, ?Location> */
    private static array $byCode = [];

    public static function for(?Location $location): ?string
    {
        if ($location === null) {
            return null;
        }

        return self::$memo[(int) $location->getKey()] ??= $location->full_path;
    }

    /**
     * The breadcrumb of the location a code names ("MUSE-1-33" → "Showcase
     * 6"), or the code itself when no location has it. Client 2026-10-27:
     * Museum Location showed the code.
     */
    public static function forCode(?string $code, ?int $repositoryId): ?string
    {
        $code = trim((string) $code);
        if ($code === '') {
            return null;
        }
        // Codes are only unique within a repository: look in the
        // document's own repository, never another one.
        $key = $repositoryId . '|' . $code;
        if (! array_key_exists($key, self::$byCode)) {
            self::$byCode[$key] = $repositoryId === null ? null : Location::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('repository_id', $repositoryId)
                ->where('code', $code)
                ->first();
        }

        return self::for(self::$byCode[$key]) ?? $code;
    }

    public static function flush(): void
    {
        self::$memo = [];
        self::$byCode = [];
    }
}
