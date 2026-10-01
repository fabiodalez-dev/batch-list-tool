<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Support\BulkImport\TemplateGenerator;
use App\Support\Export\EntityExport;
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

it('D4-Export.1: the documents export carries a Part Number column with the value in it', function (): void {
    // Both document exports (the list's Export CSV and Export selected) write
    // the documents import template's columns through EntityExport, so the
    // check is on the file itself rather than on a hand-written column list.
    $repo = wd4_repo();
    $series = wd4_series($repo->id);
    $doc = wd4_doc($repo->id, $series->id, ['part_number' => 'PT-7']);

    $headers = EntityExport::headers('document');
    expect($headers)->toContain('Part Number');

    $field = EntityExport::fieldsFor('document', $headers)[array_search('Part Number', $headers, true)];
    expect($field)->toBe('part_number')
        ->and(EntityExport::value('document', $doc, $field))->toBe('PT-7');
});

it('D4-Export.2: the documents export has every template column, plus the document identifier and the fields the template lacks', function (): void {
    $headers = EntityExport::headers('document');

    expect($headers)->toBe([
        'Document Identifier',
        ...TemplateGenerator::headersFor('document'),
        'No of Acts', 'Pages/Folios',
        'RAS 1 Box Destroyed', 'RAS 2 Box Destroyed', 'In Situ Box 1 Destroyed', 'In Situ Box 2 Destroyed', 'In Situ Box 3 Destroyed',
        'Current Box',
    ]);
});
