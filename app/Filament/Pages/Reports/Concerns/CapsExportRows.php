<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports\Concerns;

use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Safety cap for raw-row report exports (XLSX, CSV).
 *
 * Why: the unfiltered "Pending disinfestation" or "Box movement history"
 * exports can in principle pull every Document/BoxMovement row in the
 * archive (currently 3k, projected 100k+ after the M3 legacy import).
 * Loading the entire result into a maatwebsite/excel exporter would
 * blow the PHP memory limit on shared hosting (cPanel default: 512 MB).
 *
 * Behaviour: the trait runs `limit(cap + 1)` against the query, slices
 * the overflow row off, and — if the slice happened — flashes a Filament
 * warning notification to the user so they know the file is truncated
 * and they should refine their filters.
 *
 * Configuration: the cap defaults to 50_000 rows but a Page can override
 * by re-declaring `protected static int $exportRowCap = N;`.
 */
trait CapsExportRows
{
    protected static int $exportRowCap = 50_000;

    /**
     * Run the builder with `limit(cap + 1)`, slice the overflow row off
     * the end, and notify the user if truncation occurred.
     *
     * Return type is the non-generic `Eloquent\Collection` (rather than a
     * template-typed `Collection<int, TModel>`) because the cap-then-slice
     * path returns a re-keyed slice whose generic parameter PHPStan cannot
     * preserve through `slice()->values()`; the export consumers iterate
     * with model-typed closures and do not need a tighter return.
     *
     * @param Builder<Model> $query
     * @return Collection<int, Model>
     */
    protected function fetchExportRowsWithCap(Builder $query): Collection
    {
        $cap = static::$exportRowCap;
        $rows = $query->limit($cap + 1)->get();

        if ($rows->count() > $cap) {
            $truncated = $rows->slice(0, $cap)->values();
            $this->notifyExportTruncated($cap);

            return $truncated;
        }

        return $rows;
    }

    /**
     * Exposed for grouped reports that pre-aggregate in PHP — they pass
     * us the materialised iterable and we cap-and-notify the same way.
     *
     * @param iterable<int, mixed> $rows
     * @return array<int, mixed>
     */
    protected function capExportRows(iterable $rows): array
    {
        $cap = static::$exportRowCap;

        // Fast path for the array case: count is O(1), slice is O(cap).
        if (is_array($rows)) {
            if (count($rows) > $cap) {
                $this->notifyExportTruncated($cap);

                return array_slice($rows, 0, $cap);
            }

            return $rows;
        }

        // Streaming path: never materialise more than `cap` items. A future
        // caller passing a Generator / LazyCollection no longer pays the
        // worst-case cost of fully exhausting the source.
        $result = [];
        $count = 0;
        foreach ($rows as $row) {
            if ($count >= $cap) {
                $this->notifyExportTruncated($cap);

                return $result;
            }
            $result[] = $row;
            $count++;
        }

        return $result;
    }

    protected function notifyExportTruncated(int $cap): void
    {
        Notification::make()
            ->warning()
            ->title('Export truncated')
            ->body(sprintf(
                'Only the first %s rows were exported. Add filters (date range, series, batch) to scope the download.',
                number_format($cap),
            ))
            ->persistent()
            ->send();
    }
}
