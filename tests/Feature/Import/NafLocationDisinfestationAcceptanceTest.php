<?php

declare(strict_types=1);

use App\Filament\Imports\BoxImporter;
use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Filament\Pages\Reports\DocumentLocationReport;
use App\Filament\Pages\Reports\PendingDisinfestationReport;
use App\Models\Accession;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\DocumentIdentifierHistory;
use App\Models\DocumentType;
use App\Models\Location;
use App\Models\Lookup\BoxType;
use App\Models\Practice;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
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

/**
 * @param array<string,string|int|null> $data
 * @param array<string,mixed> $options importer options (e.g. skip_duplicates)
 */
function naf_import(string $importer, array $data, int $userId, array $options = []): void
{
    $map = ImportWizard::guessColumnMap($importer, array_keys($data));
    EntityResolver::flushMemo();
    /** @var Import $imp */
    $imp = Import::query()->create([
        'completed_at' => null, 'file_name' => 't.xlsx', 'file_path' => '/tmp/t.xlsx',
        'importer' => $importer, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $userId,
    ]);
    (new $importer($imp, $map, $options))($data);
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
    expect(TemplateGenerator::GENERATOR_VERSION)->toBe('1.11.0');
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
        ->and($cols)->toContain('location')
        // Client 2026-08-18: Temporary Identifier + Citation Reference are the
        // new trailing columns (Location is no longer last).
        ->and($headers)->toContain('Temporary Identifier', 'Citation Reference')
        ->and($cols)->toContain('temporary_identifier', 'citation_reference');
});

it('NC1: imports Temporary Identifier / Citation Reference / Conservation Object Ref (client 2026-08-18)', function () {
    [$repo, $u] = naf_admin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);

    naf_import(DocumentImporter::class, [
        'Identifier' => 'NC1', 'Series' => 'REG',
        'Temporary Identifier' => 'TMP-1',
        'Citation Reference' => 'Cite ABC 2026',
        'Conservation Object Reference Number' => 'COR 120-135',
    ], $u->id);

    $doc = naf_doc('NC1');
    expect($doc)->not->toBeNull()
        ->and($doc->temporary_identifier)->toBe('TMP-1')
        ->and($doc->citation_reference)->toBe('Cite ABC 2026')
        ->and($doc->object_reference_number)->toBe('COR 120-135'); // #27 = existing column, relabelled
});

it('NC2: Temporary Identifier must be unique — a clash on a DIFFERENT document fails the row', function () {
    [$repo, $u] = naf_admin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);

    naf_import(DocumentImporter::class, ['Identifier' => 'NC2A', 'Series' => 'REG', 'Temporary Identifier' => 'DUP-T'], $u->id);

    // A second, different document reusing the same Temporary Identifier is rejected.
    expect(fn () => naf_import(DocumentImporter::class, ['Identifier' => 'NC2B', 'Series' => 'REG', 'Temporary Identifier' => 'DUP-T'], $u->id))
        ->toThrow(RowImportFailedException::class);
    expect(naf_doc('NC2B'))->toBeNull();

    // Re-importing the SAME document with its own Temporary Identifier is fine (ignores self).
    naf_import(DocumentImporter::class, ['Identifier' => 'NC2A', 'Series' => 'REG', 'Temporary Identifier' => 'DUP-T', 'Note' => 'again'], $u->id);
    expect(naf_doc('NC2A')?->notes)->toBe('again');
});

it('AC1: imports Accession by NAME — creates it + links accession_id, keeps the legacy text (client 2026-08-18)', function () {
    [$repo, $u] = naf_admin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);

    naf_import(DocumentImporter::class, ['Identifier' => 'AC1', 'Series' => 'REG', 'Accession' => 'Hugh Grima Accession'], $u->id);

    $acc = Accession::withoutGlobalScopes()->where('code', 'Hugh Grima Accession')->first();
    expect($acc)->not->toBeNull()
        ->and($acc->accession_number)->toBeNull();
    expect(naf_doc('AC1')?->accession_id)->toBe($acc->id)
        ->and(naf_doc('AC1')?->accession_code_legacy)->toBe('Hugh Grima Accession');
});

it('AC2: imports Accession by NUMBER — creates it, and a second document with the same number reuses it', function () {
    [$repo, $u] = naf_admin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);

    naf_import(DocumentImporter::class, ['Identifier' => 'AC2', 'Series' => 'REG', 'Accession' => '2025-124'], $u->id);
    $acc = Accession::withoutGlobalScopes()->where('accession_number', '2025-124')->first();
    expect($acc)->not->toBeNull();
    expect(naf_doc('AC2')?->accession_id)->toBe($acc->id);

    naf_import(DocumentImporter::class, ['Identifier' => 'AC2B', 'Series' => 'REG', 'Accession' => '2025-124'], $u->id);
    expect(naf_doc('AC2B')?->accession_id)->toBe($acc->id)
        ->and(Accession::withoutGlobalScopes()->where('accession_number', '2025-124')->count())->toBe(1);
});

it('AI1: "Actual Identifier" links the matching Authority and does NOT become the document identifier (#9, corrected)', function () {
    // Charlene 2026-08-18 #9 (refined): "Actual Identifier" is the Authority
    // link, NOT the document's own identifier — the PR#201 reading was wrong.
    [$repo, $u] = naf_admin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);
    $auth = Authority::create(['identifier' => 'R77', 'surname' => 'Grima', 'entity_type' => 'PERSON']);

    naf_import(DocumentImporter::class, ['Actual Identifier' => 'R77', 'Series' => 'REG'], $u->id);

    // Only one document was created; its identifier is auto-generated, NOT 'R77'.
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->latest('id')->first();
    expect($doc)->not->toBeNull()
        ->and($doc->identifier)->not->toBe('R77')
        ->and($doc->authorities()->pluck('authorities.id')->all())->toContain($auth->id);
    // The R-code never leaked into the document identifier column.
    expect(naf_doc('R77'))->toBeNull();
});

it('MV1: RAS / In-Situ movement columns are captured verbatim in the legacy columns (#1)', function () {
    [$repo, $u] = naf_admin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);

    naf_import(DocumentImporter::class, [
        'Identifier' => 'MV1', 'Series' => 'REG',
        'RAS Batch 2' => '5', 'RAS Box 2' => '7',
        'In Situ Box 1' => 'Small Box 12', 'In Situ Box 2' => 'NRA 3',
    ], $u->id);

    $doc = naf_doc('MV1');
    expect($doc->ras_batch_2)->toBe('5')
        ->and($doc->ras_box_2)->toBe('7')
        ->and($doc->in_situ_box_1)->toBe('Small Box 12')
        ->and($doc->in_situ_box_2)->toBe('NRA 3');
});

it('DTP1: links document_type_id AND practice_id from the lookups, keeps the free-text (#12/#17)', function () {
    [$repo, $u] = naf_admin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);
    $dt = DocumentType::create(['identifier' => 'DEED', 'name' => 'Deed of Sale', 'is_active' => true]);
    $pr = Practice::create(['identifier' => 'PRAC-1', 'name' => 'Notary X', 'is_active' => true, 'repository_id' => $repo->id]);

    naf_import(DocumentImporter::class, ['Identifier' => 'DTP1', 'Series' => 'REG', 'Document Type' => 'DEED', 'Practice' => 'PRAC-1'], $u->id);

    $doc = naf_doc('DTP1');
    expect($doc->document_type)->toBe('DEED')          // free text kept
        ->and($doc->document_type_id)->toBe($dt->id)   // AND linked
        ->and($doc->practice)->toBe('PRAC-1')
        ->and($doc->practice_id)->toBe($pr->id);
});

it('DTP2: an unknown Document Type / Practice leaves the FK null and the row still succeeds', function () {
    [$repo, $u] = naf_admin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);

    naf_import(DocumentImporter::class, ['Identifier' => 'DTP2', 'Series' => 'REG', 'Document Type' => 'NoSuchType', 'Practice' => 'NoSuchPractice'], $u->id);

    $doc = naf_doc('DTP2');
    expect($doc)->not->toBeNull()
        ->and($doc->document_type)->toBe('NoSuchType')   // text stands
        ->and($doc->document_type_id)->toBeNull()        // FK not forced
        ->and($doc->practice)->toBe('NoSuchPractice')
        ->and($doc->practice_id)->toBeNull();
});

it('PA1: Prev Attributed Identifier / Volume are recorded in the identifier history, idempotently (#8)', function () {
    [$repo, $u] = naf_admin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);

    naf_import(DocumentImporter::class, [
        'Identifier' => 'PA1', 'Series' => 'REG',
        'Prev Attributed Identifier' => 'OLD-ID-1', 'Prev Attributed Volume' => 'V-3',
    ], $u->id);

    $doc = naf_doc('PA1');
    $hist = DocumentIdentifierHistory::withoutGlobalScopes()
        ->where('document_id', $doc->id)->where('previous_identifier', 'OLD-ID-1')->first();
    expect($hist)->not->toBeNull()
        ->and($hist->previous_volume)->toBe('V-3')
        ->and($hist->new_identifier)->toBe('PA1');

    // Re-import the same document → the SAME history row is updated, not duplicated.
    naf_import(DocumentImporter::class, [
        'Identifier' => 'PA1', 'Series' => 'REG',
        'Prev Attributed Identifier' => 'OLD-ID-1', 'Prev Attributed Volume' => 'V-4',
    ], $u->id);

    $rows = DocumentIdentifierHistory::withoutGlobalScopes()
        ->where('document_id', $doc->id)->where('previous_identifier', 'OLD-ID-1')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->previous_volume)->toBe('V-4');
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

it('B10: a re-imported batch-less box is SKIPPED by default — never duplicated, never rewritten (client 2026-08-13)', function () {
    // Charlene ran the same file through the wizard AND the page → duplicates.
    // A barcode-less batch-less box is now matched by (repository, box_type,
    // box_number); with the default (overwrite OFF → skip_duplicates TRUE) the
    // second import is SKIPPED: one row, and the original data is untouched.
    [$repo, $u] = naf_admin();
    naf_box($repo->id);

    $data = ['box_type' => 'IN_SITU', 'box_number' => 'DUP-1', 'batch_number' => '', 'provenance_unknown' => 'yes', 'notes' => 'first'];
    naf_import(BoxImporter::class, $data, $u->id, ['skip_duplicates' => true]);

    // The second import matches → is skipped. The real job catches this signal
    // and records the row in the "failed/skipped rows" CSV; here it surfaces as
    // the RowImportFailedException that skipIfDuplicate() throws.
    try {
        naf_import(BoxImporter::class, array_merge($data, ['notes' => 'second']), $u->id, ['skip_duplicates' => true]);
    } catch (RowImportFailedException) {
        // expected — the row was skipped, not imported
    }

    $matches = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->where('box_type', 'IN_SITU')->where('box_number', 'DUP-1')->get();

    expect($matches)->toHaveCount(1)                  // no duplicate
        ->and($matches->first()->notes)->toBe('first'); // and NOT overwritten
});

it('B11: with the Overwrite option ON, a re-imported batch-less box is UPDATED (deliberate mass-update)', function () {
    // Overwrite ON (wizard checkbox → skip_duplicates FALSE): the same match
    // updates the existing box instead of skipping. Still exactly one row.
    [$repo, $u] = naf_admin();
    naf_box($repo->id);

    $data = ['box_type' => 'IN_SITU', 'box_number' => 'DUP-2', 'batch_number' => '', 'provenance_unknown' => 'yes', 'notes' => 'first'];
    naf_import(BoxImporter::class, $data, $u->id, ['skip_duplicates' => false]);
    naf_import(BoxImporter::class, array_merge($data, ['notes' => 'second']), $u->id, ['skip_duplicates' => false]);

    $matches = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->where('box_type', 'IN_SITU')->where('box_number', 'DUP-2')->get();

    expect($matches)->toHaveCount(1)                   // no duplicate
        ->and($matches->first()->notes)->toBe('second'); // overwritten on purpose
});

it('B12: a barcode-less BATCHED box is matched by (batch, box_number) — no duplicate on re-import', function () {
    [$repo, $u] = naf_admin();
    naf_box($repo->id); // batch_number '1'

    // A RAS box with a batch but NO barcode — matched by (batch_id, box_number).
    $data = ['box_type' => 'RAS', 'box_number' => 'RDUP-1', 'batch_number' => '1'];
    naf_import(BoxImporter::class, $data, $u->id, ['skip_duplicates' => true]);

    try {
        naf_import(BoxImporter::class, $data, $u->id, ['skip_duplicates' => true]);
    } catch (RowImportFailedException) {
        // expected — the row matched an existing box and was skipped
    }

    $matches = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->where('box_number', 'RDUP-1')->get();

    expect($matches)->toHaveCount(1);
});

it('B13: matches the LIVE box over a soft-deleted one of the same identity — never restores a duplicate', function () {
    // The natural key has no unique constraint, so a live AND a soft-deleted box
    // can share it. The match must prefer the live one, never restore the
    // deleted one while a live duplicate exists (CodeRabbit, PR #196).
    [$repo, $u] = naf_admin();
    naf_box($repo->id);

    $deleted = Box::create(['box_type' => 'IN_SITU', 'box_number' => 'DUP-3', 'provenance_unknown' => true, 'repository_id' => $repo->id]);
    $deleted->delete(); // soft-delete
    $live = Box::create(['box_type' => 'IN_SITU', 'box_number' => 'DUP-3', 'provenance_unknown' => true, 'repository_id' => $repo->id]);

    // Overwrite ON → update the matched box; it must be the LIVE one.
    naf_import(BoxImporter::class, [
        'box_type' => 'IN_SITU', 'box_number' => 'DUP-3', 'batch_number' => '', 'provenance_unknown' => 'yes', 'notes' => 'updated',
    ], $u->id, ['skip_duplicates' => false]);

    $active = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('box_number', 'DUP-3')->get();
    expect($active)->toHaveCount(1)                    // still exactly one live box
        ->and($active->first()->id)->toBe($live->id)  // the LIVE one was matched
        ->and($active->first()->notes)->toBe('updated');

    // The soft-deleted box is still trashed (not restored).
    $stillDeleted = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->withTrashed()->find($deleted->id);
    expect($stillDeleted->trashed())->toBeTrue();
});

it('B14: the batch-less match is REPOSITORY-scoped — a same-key box in another repo is untouched (CodeRabbit)', function () {
    // Proves the repository_id filter is load-bearing: two batch-less boxes
    // share (box_type, box_number) across two repositories. Re-importing for
    // repo A must update ONLY repo A's box, never repo B's, and never duplicate.
    [$repoA, $u] = naf_admin();
    $repoB = Repository::factory()->create();

    $inB = Box::create(['box_type' => 'IN_SITU', 'box_number' => 'COLL-1', 'provenance_unknown' => true, 'repository_id' => $repoB->id, 'notes' => 'B-orig']);

    $data = ['box_type' => 'IN_SITU', 'box_number' => 'COLL-1', 'batch_number' => '', 'provenance_unknown' => 'yes'];
    naf_import(BoxImporter::class, array_merge($data, ['notes' => 'A-first']), $u->id, ['skip_duplicates' => false]);   // creates repo A's box
    naf_import(BoxImporter::class, array_merge($data, ['notes' => 'A-updated']), $u->id, ['skip_duplicates' => false]); // updates repo A's box

    $all = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('box_number', 'COLL-1')->get();
    expect($all)->toHaveCount(2); // one per repository, no duplicate
    expect($all->firstWhere('repository_id', $repoA->id)->notes)->toBe('A-updated'); // repo A matched + updated
    expect($inB->fresh()->notes)->toBe('B-orig');                                    // repo B untouched
});

it('B15: the batched match is BATCH-scoped — a same-number box in another batch is untouched (CodeRabbit)', function () {
    // Proves the batch_id filter is load-bearing: two RAS boxes share box_number
    // across two batches. Re-importing for batch 1 must update ONLY batch 1's box.
    [$repo, $u] = naf_admin();
    [$batch1] = naf_box($repo->id); // batch_number '1'
    $batch2 = Batch::withoutGlobalScope(RepositoryScope::class)->firstOrCreate(['batch_number' => '2', 'repository_id' => $repo->id]);

    $inB2 = Box::create(['box_type' => 'RAS', 'box_number' => 'RC-1', 'batch_id' => $batch2->id, 'notes' => 'B2-orig']);

    $data = ['box_type' => 'RAS', 'box_number' => 'RC-1', 'batch_number' => '1'];
    naf_import(BoxImporter::class, array_merge($data, ['notes' => 'B1-first']), $u->id, ['skip_duplicates' => false]);
    naf_import(BoxImporter::class, array_merge($data, ['notes' => 'B1-updated']), $u->id, ['skip_duplicates' => false]);

    $all = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('box_number', 'RC-1')->get();
    expect($all)->toHaveCount(2); // one per batch, no duplicate
    expect($all->firstWhere('batch_id', $batch1->id)->notes)->toBe('B1-updated'); // batch 1 matched + updated
    expect($inB2->fresh()->notes)->toBe('B2-orig');                               // batch 2 untouched
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

/* ══════════════ #9 — Authority Identifier (rename + alternate key + multi) ══════════════ */

it('AUTH1: the "Authority Identifier" header links the document to its Authority by alternate key (#9)', function () {
    [$repo, $u] = naf_admin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);
    $auth = Authority::create(['identifier' => 'R390', 'alternative_identifier' => '997', 'surname' => 'Grima', 'entity_type' => 'PERSON']);

    naf_import(DocumentImporter::class, [
        'Document Identifier' => 'AUTH-DOC-1', 'Series' => 'REG', 'Authority Identifier' => '997',
    ], $u->id);

    $doc = naf_doc('AUTH-DOC-1');
    expect($doc)->not->toBeNull();
    $attached = $doc->authorities()->get();
    expect($attached->count())->toBe(1)
        ->and($attached->first()->id)->toBe($auth->id);
});

it('AUTH2: the legacy "Actual Identifier" header maps to the Authority link, not the document identifier (#9)', function () {
    [$repo, $u] = naf_admin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);
    $auth = Authority::create(['identifier' => 'R533', 'surname' => 'Gatt', 'entity_type' => 'PERSON']);

    naf_import(DocumentImporter::class, [
        'Document Identifier' => 'AUTH-DOC-2', 'Series' => 'REG', 'Actual Identifier' => 'R533',
    ], $u->id);

    $doc = naf_doc('AUTH-DOC-2');
    expect($doc)->not->toBeNull()
        ->and($doc->identifier)->toBe('AUTH-DOC-2'); // NOT 'R533' — that's the authority key
    expect($doc->authorities()->get()->pluck('id')->all())->toContain($auth->id);
});

it('AUTH3: multiple authorities via ";" (R-code + alternate key) all attach (#9/#13)', function () {
    [$repo, $u] = naf_admin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);
    $a1 = Authority::create(['identifier' => 'R533', 'surname' => 'Gatt', 'entity_type' => 'PERSON']);
    $a2 = Authority::create(['identifier' => 'R538', 'alternative_identifier' => '362', 'surname' => 'Grech', 'entity_type' => 'PERSON']);

    // "Calcedonio Gatt; Giuseppe Grech" is shown as two creators — here the two
    // notaries are given as R-code + alternate key.
    naf_import(DocumentImporter::class, [
        'Document Identifier' => 'AUTH-DOC-3', 'Series' => 'REG', 'Authority Identifier' => 'R533; 362',
    ], $u->id);

    $doc = naf_doc('AUTH-DOC-3');
    $ids = $doc->authorities()->get()->pluck('id')->all();
    expect($ids)->toContain($a1->id)->toContain($a2->id);
});

/* ══════════════ #23 — documents row links Batch ↔ Accession (pivot) ══════════════ */

it('ACB1: a documents row naming BOTH a Batch and an Accession writes the accession_batch pivot, idempotently (#23)', function () {
    [$repo, $u] = naf_admin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);

    naf_import(DocumentImporter::class, [
        'Document Identifier' => 'ACB-1', 'Series' => 'REG',
        'Batch number' => '7', 'Accession' => '2025-777',
    ], $u->id);

    $doc = naf_doc('ACB-1');
    expect($doc)->not->toBeNull()
        ->and($doc->batch_id)->not->toBeNull()
        ->and($doc->accession_id)->not->toBeNull();

    $acc = Accession::withoutGlobalScopes()->whereKey($doc->accession_id)->first();
    expect($acc->batches()->get()->modelKeys())->toContain($doc->batch_id);

    // Re-import the identical row → the pivot is NOT duplicated (unique key +
    // syncWithoutDetaching): the accession still has exactly one linked batch.
    naf_import(DocumentImporter::class, [
        'Document Identifier' => 'ACB-1', 'Series' => 'REG',
        'Batch number' => '7', 'Accession' => '2025-777',
    ], $u->id);

    expect($acc->batches()->count())->toBe(1);
});

it('ACB2: a documents row with an Accession but NO Batch writes no pivot (#23)', function () {
    [$repo, $u] = naf_admin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);

    naf_import(DocumentImporter::class, [
        'Document Identifier' => 'ACB-2', 'Series' => 'REG', 'Accession' => '2025-888',
    ], $u->id);

    $doc = naf_doc('ACB-2');
    expect($doc?->accession_id)->not->toBeNull()
        ->and($doc->batch_id)->toBeNull();

    $acc = Accession::withoutGlobalScopes()->whereKey($doc->accession_id)->first();
    expect($acc->batches()->count())->toBe(0);
});
