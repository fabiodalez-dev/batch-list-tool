<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\Document;

/**
 * Resolve a document's effective-location breadcrumb, memoised by location id
 * for the lifetime of the request.
 *
 * Location::breadcrumb() runs an ancestors() query per call. Report tables
 * render a page of rows and the CSV/PDF exports thousands, but they share a
 * handful of distinct locations — so caching by id collapses O(rows) ancestor
 * queries to O(distinct locations). Used by the location-bearing reports and the
 * pending-disinfestation dashboard widget.
 */
trait ResolvesEffectiveLocationBreadcrumb
{
    /** @var array<int, string> */
    private array $effectiveLocationBreadcrumbMemo = [];

    protected function effectiveLocationBreadcrumb(Document $document): ?string
    {
        $location = $document->effectiveLocation();
        if ($location === null) {
            return null;
        }

        return $this->effectiveLocationBreadcrumbMemo[(int) $location->getKey()] ??= $location->breadcrumb();
    }
}
