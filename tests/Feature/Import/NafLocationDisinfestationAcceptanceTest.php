<?php

declare(strict_types=1);

use App\Filament\Imports\BoxImporter;
use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Filament\Pages\Reports\DocumentLocationReport;
use App\Filament\Pages\Reports\PendingDisinfestationReport;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Location;
use App\Models\Lookup\BoxType;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * NAF acceptance suite — client feedback 2026-08-04 (Charlene / Maria Pia).
 *
 * Reusable end-to-end tests for the four decisions, driven through the REAL
 * importer entry point (the same `($data)` invocation the streaming job uses,
 * with a real ImportWizard::guessColumnMap column map — no reflection past the
 * bug, no synthetic shortcuts):
 *
 *   A) Location import on the document, code-resolved (like the box).
 *   B) Location + Disinfestation Date removed from the generated box template
 *      (importer stays tolerant for sheets already in circulation).
 *   C) Tracking Note, distinct from the general Note, on boxes AND documents.
 *   D) Pending-disinfestation uses the document's own (raw) date; the box→doc
 *      mirror gap-fills only and never clobbers the document's own date.
 *
 * A companion local-only smoke over the real client files lives in
 * {@see NafRealFileSmokeTest} (skipped in CI where the PII files are absent).
 */
uses(RefreshDatabase::class);

function naf_admin(): array
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    /** @var User $u */
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id]);
    $u->assignRole('super_admin');
    test()->actingAs($u);

    return [$repo, $u];
}

function naf_loc(int $repoId, string $code, ?string $name = null): Location
{
    return Location::withoutGlobalScope(RepositoryScope::class)->create([
        'code' => $code,
        'name' => $name ?? ('Loc ' . $code),
        'type' => 'repository',
        'is_active' => true,
        'repository_id' => $repoId,
    ]);
}

/** @param array<string,string|int|null> $data */
function naf_import(string $importer, array $data, int $userId): void
{
    $map = ImportWizard::guessColumnMap($importer, array_keys($data));
    EntityResolver::flushMemo();
    /** @var Import $imp */
    $imp = Import::query()->create([
        'completed_at' => null, 'file_name' => 't.xlsx', 'file_path' => '/tmp/t.xlsx',
        'importer' => $importer, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $userId,
    ]);
    (new $importer($imp, $map, []))($data);
}

function naf_box(int $repoId): array
{
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->firstOrCreate(
        ['batch_number' => '1', 'repository_id' => $repoId],
    );

    return [$batch];
}

function naf_doc(string $identifier): ?Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', $identifier)->first();
}

function naf_boxByNumber(string $number): ?Box
{
    return Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('box_number', $number)->first();
}

/* ══════════════ A — Location import on the document (code-resolved) ══════════════ */

it('A1: resolves a known Location code onto documents.location_id', function () {
    [$repo, $u] = naf_admin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);
    $loc = naf_loc($repo->id, 'SHELF-A3');

    naf_import(DocumentImporter::class, ['Identifier' => 'A1', 'Series' => 'REG', 'Location' => 'SHELF-A3'], $u->id);

    expect(naf_doc('A1')?->location_id)->toBe($loc->id);
});

it('A2: fails the row on an unknown Location code, persisting nothing', function () {
    [$repo, $u] = naf_admin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);

    expect(fn () => naf_import(DocumentImporter::class, ['Identifier' => 'A2', 'Series' => 'REG', 'Location' => 'NOPE'], $u->id))
        ->toThrow(ValidationException::class);
    expect(naf_doc('A2'))->toBeNull();
});

it('A3: a blank Location leaves location_id null (inherits the box)', function () {
    [$repo, $u] = naf_admin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);

    naf_import(DocumentImporter::class, ['Identifier' => 'A3', 'Series' => 'REG', 'Location' => ''], $u->id);

    expect(naf_doc('A3'))->not->toBeNull()->and(naf_doc('A3')->location_id)->toBeNull();
});

it('A4: resolves the Location code case-insensitively', function () {
    [$repo, $u] = naf_admin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);
    $loc = naf_loc($repo->id, 'SHELF-B7');

    naf_import(DocumentImporter::class, ['Identifier' => 'A4', 'Series' => 'REG', 'Location' => 'shelf-b7'], $u->id);

    expect(naf_doc('A4')?->location_id)->toBe($loc->id);
});

it('A5: trims surrounding whitespace before resolving the Location code', function () {
    [$repo, $u] = naf_admin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);
    $loc = naf_loc($repo->id, 'SHELF-C1');

    naf_import(DocumentImporter::class, ['Identifier' => 'A5', 'Series' => 'REG', 'Location' => '  SHELF-C1  '], $u->id);

    expect(naf_doc('A5')?->location_id)->toBe($loc->id);
});

it('A6: re-importing with a blank Location clears a prior document-level override', function () {
    [$repo, $u] = naf_admin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);
    $loc = naf_loc($repo->id, 'SHELF-D9');

    // First import sets a document-level location override.
    naf_import(DocumentImporter::class, ['Identifier' => 'A6', 'Series' => 'REG', 'Location' => 'SHELF-D9'], $u->id);
    expect(naf_doc('A6')?->location_id)->toBe($loc->id);

    // Re-import the SAME identifier with a blank Location → override cleared, so
    // the document falls back to its box's location.
    naf_import(DocumentImporter::class, ['Identifier' => 'A6', 'Series' => 'REG', 'Location' => ''], $u->id);
    expect(naf_doc('A6')?->location_id)->toBeNull();
});

/* ══════════════ C — Tracking Note (box + document) ══════════════ */

it('C1: imports a box Tracking Note into boxes.tracking_note (header "Tracking Note")', function () {
    [$repo, $u] = naf_admin();
    naf_box($repo->id);

    naf_import(BoxImporter::class, [
        'box_type' => 'RAS', 'box_number' => 'C1', 'batch_number' => '1', 'barcode' => 'BC-C1',
        'Tracking Note' => 'note via Tracking Note',
    ], $u->id);

    expect(naf_boxByNumber('C1')?->tracking_note)->toBe('note via Tracking Note')
        ->and(naf_boxByNumber('C1')->notes)->toBeNull();
});

it('C2: imports the real box source header "Tracking" into boxes.tracking_note', function () {
    [$repo, $u] = naf_admin();
    naf_box($repo->id);

    naf_import(BoxImporter::class, [
        'box_type' => 'RAS', 'box_number' => 'C2', 'batch_number' => '1', 'barcode' => 'BC-C2',
        'Tracking' => 'note via plain Tracking', 'Note' => 'general note',
    ], $u->id);

    expect(naf_boxByNumber('C2')?->tracking_note)->toBe('note via plain Tracking')
        ->and(naf_boxByNumber('C2')->notes)->toBe('general note');
});

it('C3: imports a document Tracking Note via the new header', function () {
    [$repo, $u] = naf_admin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);

    naf_import(DocumentImporter::class, ['Identifier' => 'C3', 'Series' => 'REG', 'Tracking Note' => 'doc tracking'], $u->id);

    expect(naf_doc('C3')?->tracking)->toBe('doc tracking');
});

it('C4: imports a document Tracking via the legacy header (backward compat, col 47)', function () {
    [$repo, $u] = naf_admin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);

    naf_import(DocumentImporter::class, ['Identifier' => 'C4', 'Series' => 'REG', 'Tracking' => 'legacy tracking'], $u->id);

    expect(naf_doc('C4')?->tracking)->toBe('legacy tracking');
});

it('C5: box and document tracking notes are independent of the general note', function () {
    [$repo, $u] = naf_admin();
    naf_box($repo->id);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);

    naf_import(BoxImporter::class, [
        'box_type' => 'RAS', 'box_number' => 'C5', 'batch_number' => '1', 'barcode' => 'BC-C5',
        'notes' => 'GN', 'Tracking Note' => 'TN',
    ], $u->id);

    $box = naf_boxByNumber('C5');
    expect($box->notes)->toBe('GN')->and($box->tracking_note)->toBe('TN');
});

/* ══════════════ B — Box template drops Location + Disinfestation ══════════════ */

it('B1: the generated box template no longer offers Location or disinfestation_date', function () {
    $headers = TemplateGenerator::headersFor('box');
    expect($headers)->not->toContain('Location')
        ->and($headers)->not->toContain('disinfestation_date')
        ->and($headers)->toContain('Tracking Note');
});

it('B2: the box importer STILL accepts Location + disinfestation_date (tolerant for old sheets)', function () {
    $cols = collect(BoxImporter::getColumns())->map(fn ($c) => $c->getName());
    expect($cols)->toContain('location')->and($cols)->toContain('disinfestation_date');
});

it('B3: a legacy box sheet with Location + Disinfestation Date still imports cleanly', function () {
    [$repo, $u] = naf_admin();
    naf_box($repo->id);
    $loc = naf_loc($repo->id, 'OLD-SHELF');

    naf_import(BoxImporter::class, [
        'box_type' => 'RAS', 'box_number' => 'B3', 'batch_number' => '1', 'barcode' => 'BC-B3',
        'Location' => 'OLD-SHELF', 'Disinfestation date' => '2026-01-10',
    ], $u->id);

    $box = naf_boxByNumber('B3');
    expect($box)->not->toBeNull()
        ->and($box->location_id)->toBe($loc->id)
        ->and($box->disinfestation_date?->toDateString())->toBe('2026-01-10');
});

it('B4: the generator version was bumped for the template contract change', function () {
    expect(TemplateGenerator::GENERATOR_VERSION)->toBe('1.7.0');
});

it('B5: every generated box header still maps to a BoxImporter column (round-trip)', function () {
    $headers = TemplateGenerator::headersFor('box');
    $cols = collect(BoxImporter::getColumns())->map(fn ($c) => $c->getName())->all();
    $aliases = [
        'parent_box_number' => 'parent_barcode', 'Tracking Note' => 'tracking_note',
        'Seal Number' => 'seal_number', 'Current Box Type' => 'current_box_type', 'Destroyed' => 'destroyed',
        'Provenance Unknown' => 'provenance_unknown',
    ];
    foreach ($headers as $h) {
        expect($cols)->toContain($aliases[$h] ?? $h);
    }
});

it('B6: the document template offers Location (appended) and the importer maps it', function () {
    $headers = TemplateGenerator::headersFor('document');
    $cols = collect(DocumentImporter::getColumns())->map(fn ($c) => $c->getName())->all();
    expect($headers)->toContain('Location')
        ->and($headers[array_key_last($headers)])->toBe('Location')
        ->and($cols)->toContain('location');
});

it('B7: an IN_SITU box imports WITHOUT a location now (location optional, client feedback 2026-08-05)', function () {
    // Charlene needs to import In-Situ boxes; the model guard that required them
    // to carry a location was removed (location is optional for every box type).
    // The parent-RAS-box requirement (RFQ #3) is unchanged.
    [$repo, $u] = naf_admin();
    naf_box($repo->id);

    // Parent RAS box first.
    naf_import(BoxImporter::class, [
        'box_type' => 'RAS', 'box_number' => 'PARENT-1', 'batch_number' => '1', 'barcode' => 'BC-PARENT-1',
    ], $u->id);

    // IN_SITU box referencing the parent by barcode, with NO location.
    naf_import(BoxImporter::class, [
        'box_type' => 'IN_SITU', 'box_number' => 'INSITU-1', 'batch_number' => '1',
        'parent_box_number' => 'BC-PARENT-1',
    ], $u->id);

    $parent = naf_boxByNumber('PARENT-1');
    $box = naf_boxByNumber('INSITU-1');
    expect($parent)->not->toBeNull();
    expect($box)->not->toBeNull()
        ->and($box->box_type)->toBe('IN_SITU')
        ->and($box->parent_box_id)->toBe($parent->id) // parent RAS resolved by barcode (RFQ #3 unchanged)
        ->and($box->location_id)->toBeNull();
});

it('B8: an IN_SITU/NRA box imports with NO parent when provenance_unknown is set (client 2026-08-10)', function () {
    // Charlene is importing In-Situ / NRA boxes that genuinely have no RAS
    // parent. The importer now honours provenance_unknown (the same escape hatch
    // the create form offers), mirroring the model saving() guard.
    [$repo, $u] = naf_admin();
    naf_box($repo->id);

    // IN_SITU box, no parent, provenance unknown → allowed.
    naf_import(BoxImporter::class, [
        'box_type' => 'IN_SITU', 'box_number' => 'INSITU-NP', 'batch_number' => '1',
        'provenance_unknown' => 'yes',
    ], $u->id);

    $box = naf_boxByNumber('INSITU-NP');
    expect($box)->not->toBeNull()
        ->and($box->box_type)->toBe('IN_SITU')
        ->and($box->parent_box_id)->toBeNull()
        ->and($box->provenance_unknown)->toBeTrue();

    // Guard intact: an NRA box with neither a parent nor provenance_unknown is
    // still rejected (RFQ #3), and nothing is inserted.
    expect(fn () => naf_import(BoxImporter::class, [
        'box_type' => 'NRA', 'box_number' => 'NRA-NP', 'batch_number' => '1',
    ], $u->id))->toThrow(ValidationException::class);

    expect(naf_boxByNumber('NRA-NP'))->toBeNull();
});

it('B9: a box_type added to the Box Types lookup imports, even if it is not a built-in type (client 2026-08-10, MUS)', function () {
    // box_type is lookup-driven (the create form uses BoxType::optionsWith and
    // the model enforces Lookups::assertActive) — the importer must honour the
    // same lookup instead of a hardcoded RAS/IN_SITU/NRA/MAV/STVC list.
    [$repo, $u] = naf_admin();
    naf_box($repo->id);

    BoxType::query()->create([
        'code' => 'MUS', 'label' => 'Museum', 'sort_order' => 99, 'is_active' => true, 'is_legacy' => false,
    ]);

    naf_import(BoxImporter::class, [
        'box_type' => 'MUS', 'box_number' => 'MUS-1', 'batch_number' => '1', 'barcode' => 'BC-MUS-1',
    ], $u->id);

    $box = naf_boxByNumber('MUS-1');
    expect($box)->not->toBeNull()
        ->and($box->box_type)->toBe('MUS');

    // A code that is NOT in the lookup is still rejected, and nothing inserted.
    expect(fn () => naf_import(BoxImporter::class, [
        'box_type' => 'ZZZ', 'box_number' => 'ZZZ-1', 'batch_number' => '1', 'barcode' => 'BC-ZZZ-1',
    ], $u->id))->toThrow(ValidationException::class);

    expect(naf_boxByNumber('ZZZ-1'))->toBeNull();
});

/* ══════════════ D — Mirror gap-fill (document own date survives PERM_OUT) ══════════════ */

it('D1: a document own disinfestation_date survives creation inside a PERM_OUT box', function () {
    [$repo, $u] = naf_admin();
    [$batch] = naf_box($repo->id);
    $box = Box::factory()->create(['batch_id' => $batch->id, 'box_type' => 'RAS', 'barcode_status' => 'PERM_OUT', 'disinfestation_date' => '2026-05-05']);
    $doc = Document::withoutGlobalScopes()->create([
        'identifier' => 'D1', 'document_type' => 'T', 'series_id' => Series::factory()->create()->id,
        'repository_id' => $repo->id, 'batch_id' => $batch->id, 'current_box_id' => $box->id,
        'barcode_status' => 'IN', 'disinfestation_date' => '2024-01-01',
    ]);

    $fresh = Document::withoutGlobalScopes()->find($doc->id);
    expect($fresh->disinfestation_date?->toDateString())->toBe('2024-01-01')
        ->and($fresh->barcode_status)->toBe('PERM_OUT');
});

it('D2: a document with NO own date is gap-filled from the PERM_OUT box', function () {
    [$repo, $u] = naf_admin();
    [$batch] = naf_box($repo->id);
    $box = Box::factory()->create(['batch_id' => $batch->id, 'box_type' => 'RAS', 'barcode_status' => 'PERM_OUT', 'disinfestation_date' => '2026-05-05']);
    $doc = Document::withoutGlobalScopes()->create([
        'identifier' => 'D2', 'document_type' => 'T', 'series_id' => Series::factory()->create()->id,
        'repository_id' => $repo->id, 'batch_id' => $batch->id, 'current_box_id' => $box->id,
        'barcode_status' => 'IN', 'disinfestation_date' => null,
    ]);

    expect(Document::withoutGlobalScopes()->find($doc->id)->disinfestation_date?->toDateString())->toBe('2026-05-05');
});

it('D3: the box→document status mirror preserves the document own date on a status flip', function () {
    [$repo, $u] = naf_admin();
    [$batch] = naf_box($repo->id);
    $box = Box::factory()->create(['batch_id' => $batch->id, 'box_type' => 'RAS', 'barcode_status' => 'IN']);
    $doc = Document::withoutGlobalScopes()->create([
        'identifier' => 'D3', 'document_type' => 'T', 'series_id' => Series::factory()->create()->id,
        'repository_id' => $repo->id, 'batch_id' => $batch->id, 'current_box_id' => $box->id,
        'barcode_status' => 'IN', 'disinfestation_date' => '2023-03-03',
    ]);
    $loc = naf_loc($repo->id, 'PO-LOC');
    $box->update(['barcode_status' => 'PERM_OUT', 'disinfestation_date' => '2026-06-06', 'location_id' => $loc->id]);

    $fresh = Document::withoutGlobalScopes()->find($doc->id);
    expect($fresh->barcode_status)->toBe('PERM_OUT')
        ->and($fresh->disinfestation_date?->toDateString())->toBe('2023-03-03');
});

/* ══════════════ Reports (requests 1 & 2) ══════════════ */

it('R1: DocumentLocationReport renders and its query covers in-box and not-in-box docs', function () {
    [$repo, $u] = naf_admin();
    $series = Series::factory()->create();
    [$batch] = naf_box($repo->id);
    $box = Box::factory()->create(['batch_id' => $batch->id, 'box_type' => 'RAS', 'barcode_status' => 'IN']);
    $in = Document::withoutGlobalScopes()->create(['identifier' => 'R1a', 'document_type' => 'T', 'series_id' => $series->id, 'repository_id' => $repo->id, 'batch_id' => $batch->id, 'current_box_id' => $box->id]);
    $out = Document::withoutGlobalScopes()->create(['identifier' => 'R1b', 'document_type' => 'T', 'series_id' => $series->id, 'repository_id' => $repo->id, 'current_box_id' => null]);

    $m = new ReflectionMethod(DocumentLocationReport::class, 'reportQuery');
    $ids = $m->invoke(new DocumentLocationReport)->pluck('documents.id')->all();
    expect($ids)->toContain($in->id)->toContain($out->id);
});

it('R2: DocumentLocationReport shows the box location for an in-box document (inherited)', function () {
    [$repo, $u] = naf_admin();
    $series = Series::factory()->create();
    [$batch] = naf_box($repo->id);
    $loc = naf_loc($repo->id, 'R2-LOC');
    $box = Box::factory()->create(['batch_id' => $batch->id, 'box_type' => 'RAS', 'barcode_status' => 'IN', 'location_id' => $loc->id]);
    $doc = Document::withoutGlobalScopes()->create(['identifier' => 'R2', 'document_type' => 'T', 'series_id' => $series->id, 'repository_id' => $repo->id, 'batch_id' => $batch->id, 'current_box_id' => $box->id, 'location_id' => null]);

    $cols = (new DocumentLocationReport)->getXlsxColumns();
    expect($cols['Location']($doc->fresh()))->toBe($loc->breadcrumb())
        ->and($cols['Location from']($doc->fresh()))->toBe('box')
        ->and($cols['In a box']($doc->fresh()))->toBe('Yes');
});

it('R3: DocumentLocationReport shows the document own location for a not-in-box document', function () {
    [$repo, $u] = naf_admin();
    $series = Series::factory()->create();
    $loc = naf_loc($repo->id, 'R3-LOC');
    $doc = Document::withoutGlobalScopes()->create(['identifier' => 'R3', 'document_type' => 'T', 'series_id' => $series->id, 'repository_id' => $repo->id, 'current_box_id' => null, 'location_id' => $loc->id]);

    $cols = (new DocumentLocationReport)->getXlsxColumns();
    expect($cols['Location']($doc->fresh()))->toBe($loc->breadcrumb())
        ->and($cols['Location from']($doc->fresh()))->toBe('document')
        ->and($cols['In a box']($doc->fresh()))->toBe('No');
});

it('R4: the pending-disinfestation report exposes the effective location column', function () {
    [$repo, $u] = naf_admin();
    $series = Series::factory()->create();
    [$batch] = naf_box($repo->id);
    $loc = naf_loc($repo->id, 'R4-LOC');
    $box = Box::factory()->create(['batch_id' => $batch->id, 'box_type' => 'RAS', 'barcode_status' => 'IN', 'location_id' => $loc->id]);
    $doc = Document::withoutGlobalScopes()->create(['identifier' => 'R4', 'document_type' => 'T', 'series_id' => $series->id, 'repository_id' => $repo->id, 'batch_id' => $batch->id, 'current_box_id' => $box->id, 'disinfestation_date' => null]);

    $cols = (new PendingDisinfestationReport)->getXlsxColumns();
    expect($cols)->toHaveKey('Location')
        ->and($cols['Location']($doc->fresh()))->toBe($loc->breadcrumb());
});

it('R5: the pending-disinfestation query still keys off the document own (raw) date (D)', function () {
    [$repo, $u] = naf_admin();
    $series = Series::factory()->create();
    $done = Document::withoutGlobalScopes()->create(['identifier' => 'R5-done', 'document_type' => 'T', 'series_id' => $series->id, 'repository_id' => $repo->id, 'disinfestation_date' => '2025-01-01']);
    $pending = Document::withoutGlobalScopes()->create(['identifier' => 'R5-pending', 'document_type' => 'T', 'series_id' => $series->id, 'repository_id' => $repo->id, 'disinfestation_date' => null]);

    $m = new ReflectionMethod(PendingDisinfestationReport::class, 'reportQuery');
    $ids = $m->invoke(new PendingDisinfestationReport)->pluck('documents.id')->all();
    expect($ids)->toContain($pending->id)->not->toContain($done->id);
});
