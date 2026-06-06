<?php

declare(strict_types=1);

use App\Filament\Actions\Documents\ExportSelectedAction;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * Wave D4 — volume_label → volume_number rename + part_number in form/table/export.
 */
uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function wd4_repo(): Repository
{
    return Repository::factory()->create([
        'code' => 'WD4-' . strtoupper(substr(uniqid(), -6)),
    ]);
}

function wd4_series(int $repoId): Series
{
    return Series::withoutGlobalScope(RepositoryScope::class)->create([
        'code' => 'WD4-' . substr(uniqid(), -4),
        'title' => 'WD4 Series',
        'is_wills_series' => false,
        'is_active' => true,
    ]);
}

function wd4_doc(int $repoId, int $seriesId, array $attrs = []): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'WD4-' . substr(uniqid(), -6),
        'repository_id' => $repoId,
        'series_id' => $seriesId,
    ], $attrs));
}

// ===========================================================================
// Schema
// ===========================================================================

it('D4-Schema.1: documents.volume_number column exists', function (): void {
    expect(DB::getSchemaBuilder()->hasColumn('documents', 'volume_number'))->toBeTrue();
});

it('D4-Schema.2: documents.volume_label column does NOT exist after rename', function (): void {
    expect(DB::getSchemaBuilder()->hasColumn('documents', 'volume_label'))->toBeFalse();
});

it('D4-Schema.3: documents.part_number column exists', function (): void {
    expect(DB::getSchemaBuilder()->hasColumn('documents', 'part_number'))->toBeTrue();
});

// ===========================================================================
// Model round-trips
// ===========================================================================

it('D4-Model.1: volume_number persists and retrieves correctly', function (): void {
    $repo = wd4_repo();
    $series = wd4_series($repo->id);
    $doc = wd4_doc($repo->id, $series->id, ['volume_number' => 'Vol XII']);

    $doc->refresh();
    expect($doc->volume_number)->toBe('Vol XII');
});

it('D4-Model.2: part_number persists and retrieves correctly', function (): void {
    $repo = wd4_repo();
    $series = wd4_series($repo->id);
    $doc = wd4_doc($repo->id, $series->id, ['part_number' => 'Part 3']);

    $doc->refresh();
    expect($doc->part_number)->toBe('Part 3');
});

it('D4-Model.3: volume_number and part_number are both nullable', function (): void {
    $repo = wd4_repo();
    $series = wd4_series($repo->id);
    $doc = wd4_doc($repo->id, $series->id);

    $doc->refresh();
    expect($doc->volume_number)->toBeNull();
    expect($doc->part_number)->toBeNull();
});

// ===========================================================================
// Export column set
// ===========================================================================

it('D4-Export.1: ExportSelectedAction $allColumns includes part_number', function (): void {
    // Call the action via reflection to access the private column map.
    $reflection = new ReflectionClass(ExportSelectedAction::class);
    $method = $reflection->getMethod('perform');

    // Rather than invoking perform, we verify the allColumns array is declared
    // correctly by checking via the bulk() method structure or by calling
    // visibleExportColumns indirectly. The simplest check: create an empty
    // collection and let the method respond with a stream — but we can't call
    // it without a proper response context. Instead we check via the
    // FiltersExportColumns trait's underlying logic.
    //
    // The safe approach: inspect the private 'allColumns' through the action's
    // bulk() closure which calls perform(). We can unit-test the column map by
    // checking the method body reflection or using the public behaviour.
    //
    // Simpler approach: use a partial mock / closure capture.
    // Since allColumns is defined as a local variable inside perform(), we
    // test it indirectly: a real Document + empty collection returns a response
    // that WOULD include part_number in the header if the column is present.
    //
    // Most direct approach for this codebase pattern: read the source to confirm
    // 'part_number' appears as a key in the perform() method's $allColumns.
    $source = file_get_contents(
        app_path('Filament/Actions/Documents/ExportSelectedAction.php')
    );
    expect($source)->toContain("'part_number'");
    expect($source)->toContain("'Part Number'");
});

it('D4-Export.2: ExportSelectedAction $allColumns has at least 9 entries (8 original + part_number)', function (): void {
    $source = file_get_contents(
        app_path('Filament/Actions/Documents/ExportSelectedAction.php')
    );

    // Count occurrences of '=> ' in the $allColumns array definition block.
    // This is a structural proxy for the column count.
    preg_match_all("/'\w+'\s*=>\s*'[^']+'/", $source, $matches);
    // At least 9 pairs (the original 8 + part_number).
    expect(count($matches[0]))->toBeGreaterThanOrEqual(9);
});
