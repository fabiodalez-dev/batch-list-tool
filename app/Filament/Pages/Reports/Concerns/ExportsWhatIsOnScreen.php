<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The one query every export of a report reads: the report's own query with
 * the filters and the search the operator has applied on screen.
 *
 * Before this, each report picked its export query format by format. Four
 * reports ignored the filters in every format, and four more honoured them in
 * XLSX but not in CSV or PDF — so the same report with the same filters gave
 * three different files depending on the button pressed, and a filtered
 * screen exported the whole archive without a word.
 *
 * The fallback to reportQuery() covers a page whose table was never booted —
 * never the case inside a Livewire action, which is where every export button
 * runs, but the case when a report is constructed directly. It has to test the
 * property: getFilteredTableQuery() does not return null on an unbooted table,
 * it throws ("$table must not be accessed before initialization"). The
 * `getFilteredTableQuery() ?? reportQuery()` idiom several reports used before
 * therefore never fell back at all.
 */
trait ExportsWhatIsOnScreen
{
    /**
     * Each report builds over its own model (Document, Box, StockTakeEntry…),
     * so the model parameter is left open rather than pinned to one of them.
     *
     * @return Builder<covariant Model>
     */
    protected function exportQuery(): Builder
    {
        if (! isset($this->table)) {
            return $this->reportQuery();
        }

        return $this->getFilteredTableQuery() ?? $this->reportQuery();
    }
}
