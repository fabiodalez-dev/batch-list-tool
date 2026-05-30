<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * F7 — `document_barcode_history.document_id` must not carry a DUPLICATE index.
 *
 * On MariaDB/MySQL (prod) foreignId('document_id')->constrained() already
 * creates an index on the FK column, so a separate explicit
 * ->index('document_id') is a redundant duplicate. We assert the invariant two
 * ways:
 *
 *  1. Schema introspection: at most one index whose columns are exactly
 *     [document_id] (an explicit duplicate would push this to 2 on MariaDB).
 *  2. Migration source: the create-table block does not call ->index() for the
 *     document_id column after the constrained() FK — the prod-relevant,
 *     cross-driver guarantee. We match the chained Blueprint call specifically
 *     (`->index('document_id')`) rather than the bare substring so the
 *     explanatory comment in the migration doesn't trip the check.
 */
it('does not carry a duplicate index on document_id (schema)', function (): void {
    $documentIdIndexes = collect(Schema::getIndexes('document_barcode_history'))
        ->filter(fn (array $idx): bool => $idx['columns'] === ['document_id']);

    expect($documentIdIndexes->count())->toBeLessThanOrEqual(1);
});

it('does not re-declare an explicit Blueprint index on document_id in the migration (source)', function (): void {
    $migration = collect(glob(database_path('migrations/*_add_document_barcode_and_history.php')))->first();

    expect($migration)->not->toBeNull();

    $source = file_get_contents($migration);

    // The Blueprint call that would create the duplicate is `$t->index('document_id')`
    // (or `$table->index('document_id')`). Match the `->index('document_id')`
    // form preceded by a `$variable` token so the comment text doesn't match.
    expect($source)->not->toMatch('/\$\w+\s*->\s*index\(\s*[\'"]document_id[\'"]/');

    // And confirm the FK is declared via constrained() (which provides the index).
    expect($source)->toContain("foreignId('document_id')->constrained");
});
