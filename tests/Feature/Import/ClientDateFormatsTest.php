<?php

declare(strict_types=1);

use App\Support\BulkImport\DateRangeNormalizer;

/**
 * Client 2026-08-18 (Charlene) — the document "Dates" column arrives in many
 * free-text formats. The raw string is stored verbatim in `documents.dates`;
 * for search we derive an integer year range. This pins the derived range for
 * every format Charlene listed, so "make the dates searchable" is a proven
 * contract, not a hope.
 */
it('derives the searchable year range for each client date format', function (string $input, ?int $start, ?int $end): void {
    $r = DateRangeNormalizer::extractYearRange($input);

    expect($r['year_start'])->toBe($start)
        ->and($r['year_end'])->toBe($end);
})->with([
    'YYYY' => ['1750', 1750, 1750],
    'YYYY - YYYY' => ['1607 - 1629', 1607, 1629],
    'Mon YYYY' => ['Jan 1801', 1801, 1801],
    // Month-only, no year → not year-searchable (the free-text value still stands).
    'Mon - Mon' => ['Jan - Mar', null, null],
    // A single trailing year applies to the whole month span.
    'Mon - Mon YYYY' => ['Jan - Mar 1850', 1850, 1850],
    'Mon YYYY - Mon YYYY' => ['Jan 1850 - Mar 1852', 1850, 1852],
    'Mon YYYY - YYYY' => ['Jan 1850 - 1855', 1850, 1855],
    'YYYY - Mon YYYY' => ['1850 - Mar 1855', 1850, 1855],
    'Mon YYYY - Mon YYYY; Mon YYYY' => ['Jan 1850 - Mar 1852; Jun 1860', 1850, 1860],
    'Mon - Mon YYYY - YYYY (rare)' => ['Jan - Mar 1850 - 1852', 1850, 1852],
    // Open-ended: from the year onward — the END stays null (searchable as ">= start").
    'YYYY - (open-ended)' => ['1750 -', 1750, null],
    'Unknown' => ['Unknown', null, null],
    'n/a' => ['n/a', null, null],
    '? (question mark)' => ['?', null, null],
]);
