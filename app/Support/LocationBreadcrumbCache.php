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

    public static function for(?Location $location): ?string
    {
        if ($location === null) {
            return null;
        }

        return self::$memo[(int) $location->getKey()] ??= $location->full_path;
    }

    public static function flush(): void
    {
        self::$memo = [];
    }
}
