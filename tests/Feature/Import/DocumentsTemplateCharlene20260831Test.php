<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Filament\Resources\SeriesResource;
use App\Models\Document;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Client 2026-08-31 (Charlene) — Documents template / importer changes:
 *   1. Part Number surfaced on the template (importer already read it).
 *   2. Series → Subseries throughout (display rename; FK/table unchanged).
 *   3. Current Box removed from the document import (arrives with the box).
 *   4. Location removed; the document's location is the CODE entered under
 *      'NRA Location' (resolves to documents.location_id). A name is rejected.
 *   5. Torre is a TRUE/FALSE column; blank imports as FALSE.
 *
 * These drive the real DocumentImporter entry point (guessColumnMap → importer),
 * not internals — the header the operator types is what gets tested.
 */
uses(RefreshDatabase::class);

function dtc_setup(): array
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    /** @var User $u */
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id]);
    $u->assignRole('super_admin');
    test()->actingAs($u);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true, 'is_wills_series' => false]);

    return [$repo, $u];
}

/** @param array<string,string|null> $data */
function dtc_import(array $data, int $userId): void
{
    $map = ImportWizard::guessColumnMap(DocumentImporter::class, array_keys($data));
    EntityResolver::flushMemo();
    /** @var Import $imp */
    $imp = Import::query()->create([
        'completed_at' => null, 'file_name' => 't.xlsx', 'file_path' => '/tmp/t.xlsx',
        'importer' => DocumentImporter::class, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $userId,
    ]);
    (new DocumentImporter($imp, $map, []))($data);
}

function dtc_doc(string $identifier): ?Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', $identifier)->first();
}

// ── 1. Part Number ─────────────────────────────────────────────────────────

it('surfaces Part Number on the document template and imports it', function () {
    expect(TemplateGenerator::DOCUMENT_HEADERS)->toContain('Part Number');

    [, $u] = dtc_setup();
    dtc_import([
        'Identifier' => 'DTC-PN-1',
        'Subseries' => 'REG',
        'Part Number' => '7',
    ], $u->id);

    expect(dtc_doc('DTC-PN-1')?->part_number)->toBe('7');
});

// ── 2. Series → Subseries ──────────────────────────────────────────────────

it('resolves the FK from the new "Subseries" header', function () {
    [, $u] = dtc_setup();
    $seriesId = Series::where('code', 'REG')->value('id');

    dtc_import([
        'Identifier' => 'DTC-SUB-1',
        'Subseries' => 'REG',
    ], $u->id);

    expect(dtc_doc('DTC-SUB-1')?->series_id)->toBe($seriesId);
});

it('still resolves the FK from the legacy "Series" header (back-compat)', function () {
    [, $u] = dtc_setup();
    $seriesId = Series::where('code', 'REG')->value('id');

    dtc_import([
        'Identifier' => 'DTC-SUB-2',
        'Series' => 'REG',
    ], $u->id);

    expect(dtc_doc('DTC-SUB-2')?->series_id)->toBe($seriesId);
});

it('presents the Series entity as "Subseries" in the UI', function () {
    expect(SeriesResource::getNavigationLabel())->toBe('Subseries')
        ->and(SeriesResource::getPluralModelLabel())->toBe('Subseries');
});

it('removes Current Box and the standalone Location from the document template', function () {
    expect(TemplateGenerator::DOCUMENT_HEADERS)
        ->not->toContain('Current Box')
        ->not->toContain('Location')
        ->not->toContain('Series');
});

// ── 4. NRA Location = code-resolved location ───────────────────────────────

it('resolves the NRA Location CODE onto documents.location_id', function () {
    [$repo, $u] = dtc_setup();
    $loc = Location::withoutGlobalScope(RepositoryScope::class)->create([
        'code' => 'REPO-1-45', 'name' => 'Repository 1 — Shelf 45', 'type' => 'repository',
        'is_active' => true, 'repository_id' => $repo->id,
    ]);

    dtc_import([
        'Identifier' => 'DTC-LOC-1',
        'Subseries' => 'REG',
        'NRA Location' => 'REPO-1-45',
    ], $u->id);

    $doc = dtc_doc('DTC-LOC-1');
    // The code resolves onto location_id, and does NOT also leak into the legacy
    // free-text nra_location field (the import column was removed to stop the
    // guessColumnMap Levenshtein double-fill).
    expect($doc?->location_id)->toBe($loc->id)
        ->and($doc?->nra_location)->toBeNull();
});

it('rejects a NAME under NRA Location — Charlene: "Archive 1" is wrong, use the code', function () {
    [, $u] = dtc_setup();

    expect(fn () => dtc_import([
        'Identifier' => 'DTC-LOC-2',
        'Subseries' => 'REG',
        'NRA Location' => 'Archive 1',
    ], $u->id))->toThrow(ValidationException::class);

    expect(dtc_doc('DTC-LOC-2'))->toBeNull();
});

// ── 5. Torre boolean ───────────────────────────────────────────────────────

it('imports Torre TRUE as true', function () {
    [, $u] = dtc_setup();

    dtc_import([
        'Identifier' => 'DTC-TORRE-1',
        'Subseries' => 'REG',
        'Torre' => 'TRUE',
    ], $u->id);

    expect(dtc_doc('DTC-TORRE-1')?->torre)->toBeTrue();
});

it('imports a blank Torre cell as FALSE on a new row', function () {
    [, $u] = dtc_setup();

    dtc_import([
        'Identifier' => 'DTC-TORRE-2',
        'Subseries' => 'REG',
        'Torre' => '',
    ], $u->id);

    expect(dtc_doc('DTC-TORRE-2')?->torre)->toBeFalse();
});
