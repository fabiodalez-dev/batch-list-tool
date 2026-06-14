<?php

declare(strict_types=1);

use App\Filament\Imports\BoxImporter;
use App\Filament\Resources\AccessionResource\Pages\CreateAccession;
use App\Filament\Resources\LocationResource;
use App\Filament\Resources\LocationResource\Pages\CreateLocation;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Location;
use App\Models\Practice;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * ReviewFixesUiTest — 2 focused tests per finding from the second UI review.
 *
 * Findings covered:
 *   F1     — Inputter/CreatorColumn present on Document, Authority, Series tables
 *   F04    — Authority field removed from Accession form
 *   F05    — BoxImporter has Seal Number and Location columns
 *   F4/F07 — LocationResource infolist: no depth/parent/breadcrumb; code → 'Identifier'
 *   F08    — LocationResource: sort_order hidden from form and infolist
 *   F5     — reorderableColumns() on Batch, Accession, Authority, Series, Location, Practice
 *   RFQ-3.1.7-A — Box PERM_OUT requires location
 *   F09    — Practice identifier + repository_id columns exist; resolvePractice works
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

// ─── Helpers ─────────────────────────────────────────────────────────────────

function rfu_sa(): User
{
    $repo = Repository::factory()->create([
        'code' => 'RFU-' . strtoupper(substr(uniqid(), -6)),
    ]);

    /** @var User $u */
    $u = User::factory()->create([
        'email' => 'rfu-sa+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repo->id,
    ]);
    $u->assignRole('super_admin');
    $u->repositories()->attach($repo->id, ['is_default' => true]);

    return $u;
}

function rfu_repo(string $prefix = 'RFU'): Repository
{
    return Repository::factory()->create([
        'code' => $prefix . '_' . strtoupper(substr(uniqid(), -6)),
    ]);
}

function rfu_batch(int $repoId, int $n = 1): Batch
{
    return Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => $n,
        'type' => 'MAIN_COLLECTION',
        'repository_id' => $repoId,
        'is_active' => true,
    ]);
}

function rfu_box(int $batchId, array $attrs = []): Box
{
    return Box::withoutGlobalScopes()->create(array_merge([
        'box_type' => 'RAS',
        'box_number' => 'B-' . substr(uniqid(), -6),
        'batch_id' => $batchId,
        'barcode' => 'BARC-' . strtoupper(substr(uniqid(), -8)),
        'barcode_status' => 'IN',
        'is_legacy' => false,
    ], $attrs));
}

function rfu_location(int $repoId, ?string $code = null): Location
{
    return Location::withoutGlobalScopes()->create([
        'name' => 'Loc-' . substr(uniqid(), -6),
        'type' => 'room',
        'repository_id' => $repoId,
        'code' => $code ?? ('LOC-' . strtoupper(substr(uniqid(), -6))),
        'is_active' => true,
    ]);
}

/**
 * Drive BoxImporter on a single row (same pattern as ReviewFixesImportTest).
 *
 * @param array<string, mixed> $data
 * @param array<string, string>|null $columnMap
 */
function rfu_box_import(array $data, int $userId, ?array $columnMap = null): Importer
{
    EntityResolver::flushMemo();

    /** @var Import $imp */
    $imp = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'test.xlsx',
        'file_path' => '/tmp/test.xlsx',
        'importer' => BoxImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);

    if ($columnMap === null) {
        $columnMap = array_combine(array_keys($data), array_keys($data));
    }

    $importer = new BoxImporter($imp, $columnMap, []);
    $importer($data);

    return $importer;
}

// ─── F1: CreatorColumn present on Document, Authority, Series tables ─────────

it('F1.1: DocumentResource and AuthorityResource tables declare an Inputter column (file-content proxy)', function (): void {
    $docSrc = (string) file_get_contents(
        base_path('app/Filament/Resources/DocumentResource.php')
    );
    $authSrc = (string) file_get_contents(
        base_path('app/Filament/Resources/AuthorityResource.php')
    );

    expect(str_contains($docSrc, 'CreatorColumn::make()'))->toBeTrue('DocumentResource missing CreatorColumn::make()')
        ->and(str_contains($authSrc, 'CreatorColumn::make()'))->toBeTrue('AuthorityResource missing CreatorColumn::make()');
});

it('F1.2: SeriesResource table declares an Inputter column (file-content proxy)', function (): void {
    $src = (string) file_get_contents(
        base_path('app/Filament/Resources/SeriesResource.php')
    );

    expect(str_contains($src, 'CreatorColumn::make()'))->toBeTrue('SeriesResource missing CreatorColumn::make()');
});

// ─── F04: Authority field removed from Accession form ────────────────────────

it('F04.1: AccessionResource form schema has no authority_id or authority field', function (): void {
    $user = rfu_sa();
    $this->actingAs($user);

    Livewire::test(CreateAccession::class)
        ->assertFormFieldDoesNotExist('authority_id')
        ->assertFormFieldDoesNotExist('authority');
});

it('F04.2: AccessionResource PHP source does not call SearchableSelects::authority in form()', function (): void {
    $src = (string) file_get_contents(
        base_path('app/Filament/Resources/AccessionResource.php')
    );

    // After the fix, SearchableSelects::authority should NOT appear in the form method.
    // We check that the authority select call is absent from the form definition.
    expect(str_contains($src, "SearchableSelects::authority('authority_id'"))->toBeFalse(
        "AccessionResource still renders SearchableSelects::authority('authority_id') in its form."
    );
});

// ─── F05: BoxImporter has Seal Number and Location columns ───────────────────

it('F05.1: BoxImporter::getColumns() includes seal_number and location columns', function (): void {
    $cols = collect(BoxImporter::getColumns())->map(fn ($c) => $c->getName())->all();

    expect($cols)->toContain('seal_number')
        ->and($cols)->toContain('location');
});

it('F05.2: TemplateGenerator box headers include Seal Number and Location', function (): void {
    $headers = TemplateGenerator::headersFor('box');

    expect($headers)->toContain('Seal Number')
        ->and($headers)->toContain('Location');
});

it('F05.3: BoxImporter seal_number column persists the value on the Box record', function (): void {
    $repo = rfu_repo('BOX_SN');
    $batch = rfu_batch($repo->id, 5);

    $user = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id]);
    $user->assignRole('super_admin');

    $data = [
        'box_number' => 'TEST-SN-001',
        'box_type' => 'RAS',
        'batch_number' => 5,
        'barcode' => 'SEALTEST' . strtoupper(substr(uniqid(), -6)),
        'barcode_status' => 'IN',
        'seal_number' => 'SEAL-42',
    ];

    rfu_box_import($data, $user->id);

    $box = Box::withoutGlobalScopes()->where('box_number', 'TEST-SN-001')->first();
    expect($box)->not->toBeNull()
        ->and($box->seal_number)->toBe('SEAL-42');
});

it('F05.4: BoxImporter location column resolves by code and sets location_id', function (): void {
    $repo = rfu_repo('BOX_LOC');
    $batch = rfu_batch($repo->id, 6);
    $loc = rfu_location($repo->id, 'SHELF-A1');

    $user = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id]);
    $user->assignRole('super_admin');

    $data = [
        'box_number' => 'LOC-BOX-001',
        'box_type' => 'RAS',
        'batch_number' => 6,
        'barcode' => 'LOCTEST' . strtoupper(substr(uniqid(), -6)),
        'barcode_status' => 'IN',
        'location' => 'SHELF-A1',
    ];

    rfu_box_import($data, $user->id);

    $box = Box::withoutGlobalScopes()->where('box_number', 'LOC-BOX-001')->first();
    expect($box)->not->toBeNull()
        ->and($box->location_id)->toBe($loc->id);
});

it('F05.5: BoxImporter location column throws for unknown code', function (): void {
    $repo = rfu_repo('BOX_UNKNLOC');
    $batch = rfu_batch($repo->id, 7);

    $user = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id]);
    $user->assignRole('super_admin');

    $data = [
        'box_number' => 'UNKNOWN-LOC-001',
        'box_type' => 'RAS',
        'batch_number' => 7,
        'barcode' => 'UNKNLOC' . strtoupper(substr(uniqid(), -6)),
        'barcode_status' => 'IN',
        'location' => 'NO-SUCH-LOCATION-CODE',
    ];

    $threw = false;

    try {
        rfu_box_import($data, $user->id);
    } catch (ValidationException) {
        $threw = true;
    } catch (Throwable $e) {
        $threw = str_contains($e->getMessage(), 'Unknown location code')
            || str_contains($e->getMessage(), 'location');
    }

    expect($threw)->toBeTrue();
});

// ─── F4/F07: LocationResource infolist has no depth/parent/breadcrumb ────────

it('F4/F07.1: LocationResource PHP source does not render depth, breadcrumb, or parent.name in infolist', function (): void {
    $src = (string) file_get_contents(
        base_path('app/Filament/Resources/LocationResource.php')
    );

    // All three entries must have been removed from the infolist() method.
    // We check the source so we are not sensitive to Filament component bootstrapping.
    expect(str_contains($src, "make('depth')"))->toBeFalse('infolist still renders depth')
        ->and(str_contains($src, "make('breadcrumb')"))->toBeFalse('infolist still renders breadcrumb')
        ->and(str_contains($src, "make('parent.name')"))->toBeFalse('infolist still renders parent.name');
});

it('F07.2: LocationResource create form code field is labelled Identifier', function (): void {
    $user = rfu_sa();
    $this->actingAs($user);

    // The form field 'code' must be labelled 'Identifier' (D3/D8 rename).
    Livewire::test(CreateLocation::class)
        ->assertFormFieldExists('code');

    // Verify the label in the PHP source (the form input at line 107 carries the label).
    $src = (string) file_get_contents(
        base_path('app/Filament/Resources/LocationResource.php')
    );
    expect(str_contains($src, "->label('Identifier')"))->toBeTrue('code field not relabelled Identifier in LocationResource');
});

// ─── F08: sort_order hidden from Location form and infolist ──────────────────

it('F08.1: LocationResource PHP source does not render sort_order in its form() method', function (): void {
    $src = (string) file_get_contents(
        base_path('app/Filament/Resources/LocationResource.php')
    );

    // sort_order must not appear as an Input in the form.
    expect(str_contains($src, "make('sort_order')"))->toBeFalse(
        'LocationResource still renders sort_order in form/infolist'
    );
});

it('F08.2: LocationResource create form does NOT have a sort_order field', function (): void {
    $user = rfu_sa();
    $this->actingAs($user);

    Livewire::test(CreateLocation::class)
        ->assertFormFieldDoesNotExist('sort_order');
});

// ─── F5: reorderableColumns() on all main resources ─────────────────────────

it('F5.1: BatchResource and AccessionResource PHP sources call ->reorderableColumns()', function (): void {
    $batchSrc = (string) file_get_contents(base_path('app/Filament/Resources/BatchResource.php'));
    $accessionSrc = (string) file_get_contents(base_path('app/Filament/Resources/AccessionResource.php'));

    expect(str_contains($batchSrc, '->reorderableColumns()'))->toBeTrue('BatchResource missing ->reorderableColumns()')
        ->and(str_contains($accessionSrc, '->reorderableColumns()'))->toBeTrue('AccessionResource missing ->reorderableColumns()');
});

it('F5.2: AuthorityResource and SeriesResource PHP sources call ->reorderableColumns()', function (): void {
    $authSrc = (string) file_get_contents(base_path('app/Filament/Resources/AuthorityResource.php'));
    $seriesSrc = (string) file_get_contents(base_path('app/Filament/Resources/SeriesResource.php'));

    expect(str_contains($authSrc, '->reorderableColumns()'))->toBeTrue('AuthorityResource missing ->reorderableColumns()')
        ->and(str_contains($seriesSrc, '->reorderableColumns()'))->toBeTrue('SeriesResource missing ->reorderableColumns()');
});

// ─── RFQ-3.1.7-A: Box PERM_OUT requires location_id ─────────────────────────

it('RFQ-3.1.7-A.1: transitioning IN→PERM_OUT without location throws ValidationException', function (): void {
    $repo = rfu_repo('PERMOUT_LOC');
    $batch = rfu_batch($repo->id, 10);
    $box = rfu_box($batch->id, [
        'disinfestation_date' => '2026-05-01',
        'location_id' => null,
    ]);

    expect(fn () => $box->update(['barcode_status' => 'PERM_OUT']))
        ->toThrow(ValidationException::class);
});

it('RFQ-3.1.7-A.2: transitioning IN→PERM_OUT with disinfestation_date AND location passes', function (): void {
    $repo = rfu_repo('PERMOUT_OK');
    $batch = rfu_batch($repo->id, 11);
    $loc = rfu_location($repo->id);
    $box = rfu_box($batch->id, [
        'disinfestation_date' => '2026-05-01',
        'location_id' => $loc->id,
    ]);

    $box->update(['barcode_status' => 'PERM_OUT']);
    $box->refresh();

    expect($box->barcode_status)->toBe('PERM_OUT');
});

it('RFQ-3.1.7-A.3: editing a legacy PERM_OUT box without touching barcode_status passes', function (): void {
    // Simulate a legacy box that is already PERM_OUT but lacks a location
    // (pre-existing data). Unrelated saves must not be blocked (isDirty guard).
    $repo = rfu_repo('LEGACY_PO');
    $batch = rfu_batch($repo->id, 12);

    // Bypass the saving hook by creating directly via DB to simulate legacy data.
    $boxId = DB::table('boxes')->insertGetId([
        'box_type' => 'RAS',
        'box_number' => 'LEGACY-PO-001',
        'batch_id' => $batch->id,
        'barcode' => 'LEGACYPO' . strtoupper(substr(uniqid(), -6)),
        'barcode_status' => 'PERM_OUT',
        'disinfestation_date' => '2024-01-01',
        'location_id' => null,
        'is_legacy' => false,
        'provenance_unknown' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $box = Box::withoutGlobalScopes()->find($boxId);

    // Updating an unrelated field (notes) must not throw.
    $box->notes = 'Legacy data — updated notes';
    $box->save();

    expect($box->notes)->toBe('Legacy data — updated notes');
});

// ─── F09: Practice identifier + repository_id schema ────────────────────────

it('F09.1: practices table has identifier and repository_id columns', function (): void {
    expect(Schema::hasColumn('practices', 'identifier'))->toBeTrue()
        ->and(Schema::hasColumn('practices', 'repository_id'))->toBeTrue();
});

it('F09.2: PracticeResource PHP source declares identifier and repository.code columns in table()', function (): void {
    $src = (string) file_get_contents(base_path('app/Filament/Resources/PracticeResource.php'));

    expect(str_contains($src, "make('identifier')"))->toBeTrue('PracticeResource missing identifier column')
        ->and(str_contains($src, "make('repository.code')"))->toBeTrue('PracticeResource missing repository.code column');
});

it('F09.3: EntityResolver::resolvePractice resolves by identifier then by name', function (): void {
    EntityResolver::flushMemo();

    $p1 = Practice::create(['name' => 'NTG-FIX', 'identifier' => 'NTG-ID', 'is_active' => true]);
    $p2 = Practice::create(['name' => 'PrivatePractice', 'identifier' => null, 'is_active' => true]);

    // Resolve by identifier (exact, case-insensitive)
    $res1 = EntityResolver::resolvePractice('ntg-id');
    expect($res1)->toHaveKey('practice_id')
        ->and($res1['practice_id'])->toBe($p1->id);

    // Resolve by name (identifier is null → falls through to name)
    $res2 = EntityResolver::resolvePractice('PrivatePractice');
    expect($res2)->toHaveKey('practice_id')
        ->and($res2['practice_id'])->toBe($p2->id);

    // Unknown value → null
    expect(EntityResolver::resolvePractice('DOES-NOT-EXIST'))->toBeNull();

    // ── Tenancy scoping (F026) ──────────────────────────────────────────────
    // A practice scoped to repository B must NOT be resolvable from an import
    // running in repository A's context (nor unscoped); it is only resolvable
    // when its own repository is supplied. A global (NULL repo) practice stays
    // resolvable from any scope. (`practices.identifier` and `practices.name`
    // are globally UNIQUE in the schema, so cross-tenant collisions on the same
    // token can't actually exist — the leak the fix closes is the *guess across
    // tenants* of an existing tenant-scoped row.)
    EntityResolver::flushMemo();

    $repoA = rfu_repo('PRACA');
    $repoB = rfu_repo('PRACB');

    $pa = Practice::create(['name' => 'Practice A', 'identifier' => 'PRAC-A', 'repository_id' => $repoA->id, 'is_active' => true]);
    $pb = Practice::create(['name' => 'Practice B', 'identifier' => 'PRAC-B', 'repository_id' => $repoB->id, 'is_active' => true]);
    $pg = Practice::create(['name' => 'Practice Global', 'identifier' => 'GLOB-X', 'repository_id' => null, 'is_active' => true]);

    // repoA's own practice resolves under repoA's scope.
    $resA = EntityResolver::resolvePractice('PRAC-A', $repoA->id);
    expect($resA)->toHaveKey('practice_id')
        ->and($resA['practice_id'])->toBe($pa->id);

    // repoB's own practice resolves under repoB's scope.
    EntityResolver::flushMemo();
    $resB = EntityResolver::resolvePractice('PRAC-B', $repoB->id);
    expect($resB)->toHaveKey('practice_id')
        ->and($resB['practice_id'])->toBe($pb->id);

    // Cross-tenant: repoB's practice is NEVER resolved from repoA's scope …
    EntityResolver::flushMemo();
    expect(EntityResolver::resolvePractice('PRAC-B', $repoA->id))->toBeNull();
    // … nor from an unscoped (global-only) call.
    EntityResolver::flushMemo();
    expect(EntityResolver::resolvePractice('PRAC-B'))->toBeNull();

    // Resolution by name is scoped identically (Strategy 2).
    EntityResolver::flushMemo();
    expect(EntityResolver::resolvePractice('Practice B', $repoA->id))->toBeNull();

    // The global practice is resolvable from both repository scopes.
    EntityResolver::flushMemo();
    expect(EntityResolver::resolvePractice('GLOB-X', $repoA->id)['practice_id'])->toBe($pg->id);
    EntityResolver::flushMemo();
    expect(EntityResolver::resolvePractice('GLOB-X', $repoB->id)['practice_id'])->toBe($pg->id);
});
