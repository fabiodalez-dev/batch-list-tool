<?php

declare(strict_types=1);

use App\Filament\Imports\AuthorityImporter;
use App\Filament\Imports\BatchImporter;
use App\Filament\Imports\BoxImporter;
use App\Filament\Imports\DocumentImporter;
use App\Filament\Imports\SeriesImporter;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * PR feat/bulk-import-v2 — RFQ §3.1.3 Bulk Import UI v2.
 *
 * These tests exercise the five Importer classes (Authority, Series, Document,
 * Box, Batch) and the central EntityResolver. The HTTP / Livewire surface of
 * `FullImportAction` is covered indirectly: we exercise the same code paths
 * the action dispatches once a row reaches the importer — column-mapping,
 * `castState`, `fillRecord`, validation, and `afterSave` hooks.
 *
 * Several tests use the same "build a Filament Import row" pattern: we
 * instantiate the importer with a controlled `$data` array (what a parsed
 * spreadsheet row looks like) and assert the resulting model + side
 * effects. This is closer to how the real production code runs than a
 * Livewire / HTTP test would be, and it stays fast (no UI roundtrip).
 */
uses(RefreshDatabase::class);

/* ─── helpers ────────────────────────────────────────────────────────── */

function bi_seedRoles(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function bi_makeAdmin(?int $repoId = null): User
{
    bi_seedRoles();
    /** @var User $u */
    $u = User::factory()->create([
        'email' => 'bi-admin+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repoId,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function bi_makeEditor(int $repoId): User
{
    bi_seedRoles();
    /** @var User $u */
    $u = User::factory()->create([
        'email' => 'bi-editor+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repoId,
    ]);
    $u->assignRole('editor');
    $u->repositories()->attach($repoId);

    return $u;
}

function bi_repo(string $prefix = 'BI'): Repository
{
    return Repository::factory()->create([
        'code' => $prefix . '_' . substr(uniqid(), -6),
    ]);
}

function bi_series(string $code = 'REG'): Series
{
    return Series::firstOrCreate(
        ['code' => $code],
        ['title' => $code . ' title', 'is_active' => true],
    );
}

/**
 * Build a real `Filament\Actions\Imports\Models\Import` row, which is the
 * dependency the Importer constructor takes. The actual file/columns table
 * isn't touched — the test invokes columns directly.
 */
function bi_importModel(string $importerClass, int $userId): Import
{
    /** @var Import $row */
    $row = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'test.xlsx',
        'file_path' => '/tmp/test.xlsx',
        'importer' => $importerClass,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);

    return $row;
}

/**
 * Instantiate the importer with an inline data row and a column map that's
 * an identity mapping (column name → same key in $data). This is what
 * Filament's column-mapping step produces after the operator confirms.
 *
 * @param array<string, mixed> $data
 * @param array<string, string>|null $columnMap null → identity (every column maps to itself)
 */
function bi_runImporter(string $importerClass, array $data, int $userId, ?array $columnMap = null): object
{
    EntityResolver::flushMemo();
    /** @var Importer $importer */
    $row = bi_importModel($importerClass, $userId);

    if ($columnMap === null) {
        $columnMap = array_combine(array_keys($data), array_keys($data));
    }

    $importer = new $importerClass($row, $columnMap, []);
    // Drive the full pipeline ($importer is __invokable on $data).
    $importer($data);

    return $importer;
}

/* ─── EntityResolver ─────────────────────────────────────────────────── */

test('EntityResolver: authority by identifier returns single id', function () {
    bi_makeAdmin();
    $a = Authority::create([
        'identifier' => 'R-RES-1',
        'surname' => 'Abela',
        'entity_type' => 'PERSON',
    ]);

    EntityResolver::flushMemo();
    $res = EntityResolver::resolveAuthority('R-RES-1');

    expect($res)->toBe(['authority_id' => $a->id, 'method' => 'identifier']);
});

test('EntityResolver F-009: ambiguous surname returns ambiguous_count, never auto-assigns', function () {
    bi_makeAdmin();
    Authority::create(['identifier' => 'R-AMB-1', 'surname' => 'Abela', 'entity_type' => 'PERSON']);
    Authority::create(['identifier' => 'R-AMB-2', 'surname' => 'Abela', 'entity_type' => 'PERSON']);

    EntityResolver::flushMemo();
    $res = EntityResolver::resolveAuthority(null, null, 'Abela');

    expect($res)->toHaveKey('ambiguous_count')
        ->and($res['ambiguous_count'])->toBe(2)
        ->and($res)->toHaveKey('candidates')
        ->and(count($res['candidates']))->toBe(2)
        ->and(array_key_exists('authority_id', $res))->toBeFalse();
});

test('EntityResolver F-001: short surname tokens (<4 chars) never fuzzy-match', function () {
    bi_makeAdmin();
    // Plant a surname that contains the short token "Foo" — fuzzy LIKE
    // would otherwise return it. The F-001 guard makes the resolver
    // return null instead.
    Authority::create(['identifier' => 'R-F001', 'surname' => 'Foobarbaz', 'entity_type' => 'PERSON']);

    EntityResolver::flushMemo();
    $res = EntityResolver::resolveAuthority(null, null, 'Foo');

    expect($res)->toBeNull();
});

test('EntityResolver series resolves by code from "CODE: Title" format', function () {
    bi_makeAdmin();
    Series::create(['code' => 'REG', 'title' => 'Registers Private Practice', 'is_active' => true]);

    EntityResolver::flushMemo();
    $res = EntityResolver::resolveSeries('REG: Registers Private Practice');

    expect($res)->toHaveKey('series_id')
        ->and($res['series_id'])->toBeInt();
});

test('EntityResolver batch refuses forbidden numbers 33, 34, 36', function () {
    bi_makeAdmin();

    EntityResolver::flushMemo();
    expect(EntityResolver::resolveBatch(33))->toBe(['forbidden' => 33]);
    expect(EntityResolver::resolveBatch(34))->toBe(['forbidden' => 34]);
    expect(EntityResolver::resolveBatch(36))->toBe(['forbidden' => 36]);
});

/* ─── AuthorityImporter ──────────────────────────────────────────────── */

test('AuthorityImporter column declarations cover the sample headers verbatim', function () {
    $cols = AuthorityImporter::getColumns();
    $byName = [];
    foreach ($cols as $c) {
        $byName[$c->getName()] = $c;
    }

    // The columns we expect to map to from the sample headers.
    expect($byName)->toHaveKeys([
        'identifier', 'alternative_identifier', 'surname', 'given_names',
        'entity_type', 'practice_dates_active', 'ntg_dates_active',
        'name_suffix', 'maiden_surname',
    ]);

    // First-run defaults: when the operator drops the official sample
    // file, these exact header strings (from Authorities_Sample.xlsx)
    // must be in the `guess()` aliases so columns auto-map. The Filament
    // matcher lowercases + normalises everything, so we assert on the
    // post-normalisation form (which is what's actually compared at
    // runtime against the spreadsheet headers).
    expect($byName['identifier']->getGuesses())->toContain('identifier');
    expect($byName['alternative_identifier']->getGuesses())->toContain('alternative identifier');
    expect($byName['surname']->getGuesses())->toContain('creator surname');
    expect($byName['given_names']->getGuesses())->toContain('creator name');
    expect($byName['entity_type']->getGuesses())->toContain('type of entity');
    expect($byName['practice_dates_active']->getGuesses())->toContain('private practice dates active');
    expect($byName['ntg_dates_active']->getGuesses())->toContain('ntg dates active');
    expect($byName['name_suffix']->getGuesses())->toContain('name suffix');
    expect($byName['maiden_surname']->getGuesses())->toContain('maiden surname');
});

test('AuthorityImporter creates a new Authority with parsed year range', function () {
    $u = bi_makeAdmin();

    bi_runImporter(AuthorityImporter::class, [
        'identifier' => 'R-IMP-1',
        'surname' => 'Abela',
        'given_names' => 'Antonio',
        'entity_type' => 'Person',
        'practice_dates_active' => '1607-1629',
    ], $u->id);

    $a = Authority::where('identifier', 'R-IMP-1')->first();
    expect($a)->not->toBeNull()
        ->and($a->surname)->toBe('Abela')
        ->and($a->given_names)->toBe('Antonio')
        ->and($a->entity_type)->toBe('PERSON')
        ->and($a->practice_dates_start)->toBe(1607)
        ->and($a->practice_dates_end)->toBe(1629);
});

/* ─── SeriesImporter ─────────────────────────────────────────────────── */

test('SeriesImporter splits "REG: Registers Private Practice" → code REG', function () {
    $u = bi_makeAdmin();

    bi_runImporter(SeriesImporter::class, [
        'code' => 'REG: Registers Private Practice',
        'title' => 'Registers Private Practice',
    ], $u->id);

    $s = Series::where('code', 'REG')->first();
    expect($s)->not->toBeNull()
        ->and($s->title)->toBe('Registers Private Practice')
        ->and($s->is_wills_series)->toBeFalse();
});

test('SeriesImporter derives is_wills_series from code containing WL', function () {
    $u = bi_makeAdmin();

    bi_runImporter(SeriesImporter::class, [
        'code' => 'RWL',
        'title' => 'Registers Private Practice Public Wills',
    ], $u->id);

    $s = Series::where('code', 'RWL')->first();
    expect($s)->not->toBeNull()
        ->and($s->is_wills_series)->toBeTrue();
});

/* ─── DocumentImporter ───────────────────────────────────────────────── */

test('DocumentImporter resolves Series by full "CODE: Title" text', function () {
    $repo = bi_repo();
    $u = bi_makeAdmin($repo->id);
    $this->actingAs($u);
    $series = Series::create(['code' => 'REG', 'title' => 'Registers Private Practice', 'is_active' => true]);

    bi_runImporter(DocumentImporter::class, [
        'identifier' => 'DOC-IMP-1',
        'series' => 'REG: Registers Private Practice',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'DOC-IMP-1')->first();

    expect($doc)->not->toBeNull()
        ->and($doc->series_id)->toBe($series->id);
});

test('DocumentImporter resolves Authority by identifier (R-code), exact match', function () {
    $repo = bi_repo();
    $u = bi_makeAdmin($repo->id);
    $this->actingAs($u);
    $series = bi_series('REG');
    $authority = Authority::create([
        'identifier' => 'R-IMPDOC-1',
        'surname' => 'Abela',
        'entity_type' => 'PERSON',
    ]);

    bi_runImporter(DocumentImporter::class, [
        'identifier' => 'DOC-AUTHID-1',
        'series' => 'REG',
        'authority_identifier' => 'R-IMPDOC-1',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'DOC-AUTHID-1')->first();

    expect($doc)->not->toBeNull();
    expect($doc->authorities()->pluck('authorities.id')->all())
        ->toContain($authority->id);
});

test('DocumentImporter F-009: ambiguous Creator surname does NOT auto-assign and logs ambiguous_N', function () {
    $repo = bi_repo();
    $u = bi_makeAdmin($repo->id);
    $this->actingAs($u);
    bi_series('REG');
    // Two Abela → ambiguous on surname alone.
    Authority::create(['identifier' => 'R-AB-1', 'surname' => 'Abela', 'entity_type' => 'PERSON']);
    Authority::create(['identifier' => 'R-AB-2', 'surname' => 'Abela', 'entity_type' => 'PERSON']);

    bi_runImporter(DocumentImporter::class, [
        'identifier' => 'DOC-F009-1',
        'series' => 'REG',
        'creator_legacy_text' => 'Abela',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'DOC-F009-1')->first();

    expect($doc)->not->toBeNull();
    // NOT auto-assigned (the headline F-009 invariant).
    expect($doc->authorities()->count())->toBe(0);

    // ...but the ambiguity IS logged in extra for the operator to resolve.
    expect((string) ($doc->extra['creator_match_log'] ?? ''))->toBe('ambiguous_2_candidates');
    expect($doc->extra['ambiguous_candidates'])->toBeArray()
        ->and(count($doc->extra['ambiguous_candidates']))->toBe(2);
    // Free-text catalogator copy is preserved verbatim.
    expect((string) ($doc->extra['legacy_creator_text'] ?? ''))->toBe('Abela');
});

test('DocumentImporter F-001: short Creator token "Foo" does not fuzzy-match', function () {
    $repo = bi_repo();
    $u = bi_makeAdmin($repo->id);
    $this->actingAs($u);
    bi_series('REG');
    // Plant a surname that DOES contain "Foo" — fuzzy LIKE without F-001
    // would return it.
    Authority::create(['identifier' => 'R-F001-DOC', 'surname' => 'Foobarbaz', 'entity_type' => 'PERSON']);

    bi_runImporter(DocumentImporter::class, [
        'identifier' => 'DOC-F001-1',
        'series' => 'REG',
        'creator_legacy_text' => 'Foo',
    ], $u->id);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'DOC-F001-1')->first();

    expect($doc)->not->toBeNull();
    expect($doc->authorities()->count())->toBe(0);
    expect((string) ($doc->extra['creator_match_log'] ?? ''))->toBe('unresolved');
});

test('DocumentImporter rejects batch_number 33 (RFQ App.1 #1)', function () {
    $repo = bi_repo();
    $u = bi_makeAdmin($repo->id);
    $this->actingAs($u);
    bi_series('REG');

    try {
        bi_runImporter(DocumentImporter::class, [
            'identifier' => 'DOC-B33-1',
            'series' => 'REG',
            'batch_number' => 33,
        ], $u->id);
        $this->fail('Expected a validation exception for forbidden batch 33.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('batch_number');
    }

    // And no row should have been inserted.
    expect(
        Document::withoutGlobalScope(RepositoryScope::class)
            ->where('identifier', 'DOC-B33-1')->exists()
    )->toBeFalse();
});

test('DocumentImporter requires disinfestation_date when status is PERM_OUT', function () {
    $repo = bi_repo();
    $u = bi_makeAdmin($repo->id);
    $this->actingAs($u);
    bi_series('REG');

    // Bypass the column mapping for the legacy status — we drive
    // status_1 directly through the data array because there's no
    // dedicated ImportColumn for it (it's not on the canonical column
    // list; it'd be mapped by the operator on a per-spreadsheet basis).
    $row = Import::query()->create([
        'file_name' => 'test.xlsx',
        'file_path' => '/tmp/test.xlsx',
        'importer' => DocumentImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $u->id,
    ]);
    $importer = new DocumentImporter($row, [
        'identifier' => 'identifier',
        'series' => 'series',
    ], []);

    try {
        // We have to manually drive afterFill because the public path requires
        // ImportColumns to flow values; here we want to assert the rule logic.
        $reflection = new ReflectionClass($importer);
        $recordProp = $reflection->getProperty('record');
        $recordProp->setAccessible(true);
        $doc = new Document([
            'identifier' => 'DOC-PERMOUT-1',
            'series_id' => Series::first()->id,
            'repository_id' => $repo->id,
            'status_1' => 'PERM_OUT',
            // disinfestation_date intentionally missing
        ]);
        $recordProp->setValue($importer, $doc);

        $importer->afterFill();
        $this->fail('Expected ValidationException for PERM_OUT without disinfestation_date');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('disinfestation_date');
    }
});

/* ─── BatchImporter ──────────────────────────────────────────────────── */

test('BatchImporter rejects batch_number 33 / 34 / 36 with a validation error', function () {
    $repo = bi_repo();
    $u = bi_makeAdmin($repo->id);
    $this->actingAs($u);

    foreach ([33, 34, 36] as $forbidden) {
        try {
            bi_runImporter(BatchImporter::class, [
                'batch_number' => $forbidden,
                'type' => 'MAIN_COLLECTION',
            ], $u->id);
            $this->fail("Expected a validation exception for forbidden batch $forbidden.");
        } catch (ValidationException $e) {
            expect($e->errors())->toHaveKey('batch_number');
        }
    }

    // None should have been inserted.
    expect(
        Batch::withoutGlobalScope(RepositoryScope::class)
            ->whereIn('batch_number', [33, 34, 36])->count()
    )->toBe(0);
});

test('BatchImporter auto-derives type=NOTARY_ACCESSION for batch_number >= 30', function () {
    $repo = bi_repo();
    $u = bi_makeAdmin($repo->id);
    $this->actingAs($u);

    bi_runImporter(BatchImporter::class, [
        'batch_number' => 35,
    ], $u->id);

    $b = Batch::withoutGlobalScope(RepositoryScope::class)
        ->where('batch_number', 35)->first();
    expect($b)->not->toBeNull()
        ->and($b->type)->toBe('NOTARY_ACCESSION');
});

/* ─── BoxImporter ────────────────────────────────────────────────────── */

test('BoxImporter forces is_legacy=true for legacy MAV/STVC types (RFQ #4)', function () {
    $repo = bi_repo();
    $u = bi_makeAdmin($repo->id);
    $this->actingAs($u);
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 999,
        'type' => 'NOTARY_ACCESSION',
        'repository_id' => $repo->id,
        'is_active' => true,
    ]);

    bi_runImporter(BoxImporter::class, [
        'box_number' => 'MAV-1',
        'box_type' => 'MAV',
        'batch_number' => 999,
    ], $u->id);

    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->where('box_number', 'MAV-1')->first();
    expect($box)->not->toBeNull()
        ->and($box->box_type)->toBe('MAV')
        ->and($box->is_legacy)->toBeTrue();
});

test('BoxImporter rejects IN_SITU box without a parent RAS box (RFQ #3)', function () {
    $repo = bi_repo();
    $u = bi_makeAdmin($repo->id);
    $this->actingAs($u);
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 998,
        'type' => 'NOTARY_ACCESSION',
        'repository_id' => $repo->id,
        'is_active' => true,
    ]);

    try {
        bi_runImporter(BoxImporter::class, [
            'box_number' => 'INSITU-NO-PARENT',
            'box_type' => 'IN_SITU',
            'batch_number' => 998,
        ], $u->id);
        $this->fail('Expected ValidationException for IN_SITU without parent.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('parent_box_id');
    }
});

test('BoxImporter rejects PERM_OUT box without disinfestation_date (RFQ #5)', function () {
    $repo = bi_repo();
    $u = bi_makeAdmin($repo->id);
    $this->actingAs($u);
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 997,
        'type' => 'NOTARY_ACCESSION',
        'repository_id' => $repo->id,
        'is_active' => true,
    ]);

    try {
        bi_runImporter(BoxImporter::class, [
            'box_number' => 'PO-1',
            'box_type' => 'RAS',
            'batch_number' => 997,
            'barcode_status' => 'PERM_OUT',
        ], $u->id);
        $this->fail('Expected ValidationException for PERM_OUT without disinfestation_date.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('disinfestation_date');
    }
});
