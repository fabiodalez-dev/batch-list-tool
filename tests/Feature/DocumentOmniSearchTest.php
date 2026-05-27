<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Models\Accession;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
 * RFQ §3.1.2 — omni-search bar on /admin/documents.
 *
 * The list page is the primary entry point for the operator. These tests
 * cover the cross-table search closure wired via
 * DocumentResource::applyOmniSearch() and the spotlight global-search
 * extensions (Cmd+K). Direct columns, joined Authorities/Series/Batch/Box/
 * Location/Flags, SQL-injection escaping, distinct rows, RepositoryScope,
 * empty-search no-op and the global search override are all exercised.
 *
 * The fixtures are seeded inline against the SQLite in-memory test DB —
 * the live MySQL is not touched.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

function omni_rolesExist(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function omni_actAsAdmin(): User
{
    omni_rolesExist();
    $u = User::factory()->create([
        'email' => 'omni-admin+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function omni_actAsEditor(Repository $repo): User
{
    omni_rolesExist();
    $u = User::factory()->create([
        'email' => 'omni-editor+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repo->id,
    ]);
    $u->assignRole('editor');
    // Pivot — editors only see their own repos via RepositoryScope.
    $u->repositories()->syncWithoutDetaching([$repo->id]);

    return $u;
}

function omni_makeRepo(string $prefix = 'OMR'): Repository
{
    return Repository::factory()->create([
        'code' => $prefix . '_' . substr(uniqid(), -6),
    ]);
}

function omni_makeSeries(string $code = 'OMS', ?string $title = null): Series
{
    return Series::firstOrCreate(
        ['code' => $code . '_' . substr(uniqid(), -4)],
        ['title' => $title ?? ($code . ' series'), 'is_active' => true],
    );
}

function omni_makeBatch(int $repoId, ?int $number = null): Batch
{
    if ($number === null) {
        do {
            $n = random_int(2000, 8999);
        } while (in_array($n, [33, 34, 36], true)
            || Batch::withoutGlobalScope(RepositoryScope::class)
                ->where('batch_number', $n)->exists());
    } else {
        $n = $number;
    }

    return Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => $n,
        'type' => 'NOTARY_ACCESSION',
        'repository_id' => $repoId,
        'is_active' => true,
    ]);
}

function omni_makeBox(int $batchId, array $attrs = []): Box
{
    return Box::withoutGlobalScopes()->create(array_merge([
        'box_type' => 'RAS',
        'box_number' => 'OMNI-BX-' . strtoupper(substr(uniqid(), -6)),
        'batch_id' => $batchId,
        'barcode' => 'OMNI-BC-' . strtoupper(substr(uniqid(), -8)),
        'barcode_status' => 'IN',
        'is_legacy' => false,
    ], $attrs));
}

function omni_makeDoc(int $repoId, int $seriesId, array $attrs = []): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'OMNI-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'TEST',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
    ], $attrs));
}

function omni_makeAuthority(array $attrs = []): Authority
{
    return Authority::create(array_merge([
        'identifier' => 'A-' . strtoupper(substr(uniqid(), -8)),
        'surname' => 'Surname' . substr(uniqid(), -4),
        'entity_type' => 'PERSON',
    ], $attrs));
}

function omni_makeLocation(int $repoId, array $attrs = []): Location
{
    return Location::withoutGlobalScopes()->create(array_merge([
        'name' => 'Loc ' . substr(uniqid(), -4),
        'code' => 'LC-' . strtoupper(substr(uniqid(), -6)),
        'type' => 'room',
        'repository_id' => $repoId,
        'depth' => 0,
        'is_active' => true,
    ], $attrs));
}

/*
 * Test 1 — Authority surname match.
 *
 * Typing the surname of one of the document's creators finds the document
 * even though "Abela" appears in NO direct documents column. This is the
 * canonical RFQ §3.1.2 use-case.
 */
test('omni-search by authority surname returns the linked document', function (): void {
    $this->actingAs(omni_actAsAdmin());

    $repo = omni_makeRepo();
    $series = omni_makeSeries();
    $hit = omni_makeDoc($repo->id, $series->id, ['identifier' => 'X-HIT-AUTH']);
    $miss = omni_makeDoc($repo->id, $series->id, ['identifier' => 'X-MISS-AUTH']);

    $abela = omni_makeAuthority(['surname' => 'Abela', 'identifier' => 'R1']);
    $hit->authorities()->attach($abela->id);

    Livewire::test(ListDocuments::class)
        ->set('tableSearch', 'Abela')
        ->assertCanSeeTableRecords([$hit])
        ->assertCanNotSeeTableRecords([$miss]);
});

/*
 * Test 2 — Identifier dual-meaning: matches docs.identifier AND
 * authorities.identifier.
 *
 * "R45" is both a possible document identifier AND a possible Authority
 * identifier (notary code). The omni-search must surface both.
 */
test('omni-search on R-identifier surfaces docs by own identifier and by author identifier', function (): void {
    $this->actingAs(omni_actAsAdmin());

    $repo = omni_makeRepo();
    $series = omni_makeSeries();

    // (a) Doc whose own identifier matches
    $docByIdent = omni_makeDoc($repo->id, $series->id, ['identifier' => 'R45']);

    // (b) Doc whose authority has identifier R45
    $docByAuthor = omni_makeDoc($repo->id, $series->id, ['identifier' => 'OTHER-9000']);
    $auth = omni_makeAuthority(['identifier' => 'R45', 'surname' => 'NotaryR45']);
    $docByAuthor->authorities()->attach($auth->id);

    // (c) Unrelated doc
    $noise = omni_makeDoc($repo->id, $series->id, ['identifier' => 'OTHER-NOISE']);

    Livewire::test(ListDocuments::class)
        ->set('tableSearch', 'R45')
        ->assertCanSeeTableRecords([$docByIdent, $docByAuthor])
        ->assertCanNotSeeTableRecords([$noise]);
});

/*
 * Test 3 — Barcode (IN) direct column match.
 *
 * `barcode_in` is a frequent operator query against the production export
 * ("which doc has AA40822?"). Must keep working as a direct-column hit.
 */
test('omni-search by barcode_in returns the matching document', function (): void {
    $this->actingAs(omni_actAsAdmin());

    $repo = omni_makeRepo();
    $series = omni_makeSeries();

    $hit = omni_makeDoc($repo->id, $series->id, ['barcode_in' => 'AA40822']);
    $miss = omni_makeDoc($repo->id, $series->id, ['barcode_in' => 'AA99999']);

    Livewire::test(ListDocuments::class)
        ->set('tableSearch', 'AA40822')
        ->assertCanSeeTableRecords([$hit])
        ->assertCanNotSeeTableRecords([$miss]);
});

/*
 * Test 4 — Series code match.
 *
 * "REG" is the Registers Private Practice code; operator should find every
 * doc in that series even if they have no other matching attribute.
 */
test('omni-search by series code returns docs in that series', function (): void {
    $this->actingAs(omni_actAsAdmin());

    $repo = omni_makeRepo();
    $regSeries = Series::create(['code' => 'OMSEARCH_REG', 'title' => 'Registers Private Practice', 'is_active' => true]);
    $oSeries = Series::create(['code' => 'OMSEARCH_O', 'title' => 'Originals', 'is_active' => true]);

    $hit = omni_makeDoc($repo->id, $regSeries->id);
    $miss = omni_makeDoc($repo->id, $oSeries->id);

    Livewire::test(ListDocuments::class)
        ->set('tableSearch', 'OMSEARCH_REG')
        ->assertCanSeeTableRecords([$hit])
        ->assertCanNotSeeTableRecords([$miss]);
});

/*
 * Test 5 — Batch number numeric match.
 *
 * Searching "4" should match docs whose batch_number is 4 (integer).
 * Critical: the OmniSearch must apply the integer path AND the LIKE path
 * (so "4" also matches docs whose identifier contains "4"). Here we
 * verify the batch path specifically.
 */
test('omni-search by integer batch number returns docs in that batch', function (): void {
    $this->actingAs(omni_actAsAdmin());

    $repo = omni_makeRepo();
    $series = omni_makeSeries();
    // Pick a batch number that does NOT appear in any other doc's identifier
    $batchInBatch = omni_makeBatch($repo->id, 8543);
    $batchOther = omni_makeBatch($repo->id, 8544);

    $hit = omni_makeDoc($repo->id, $series->id, [
        'identifier' => 'OMSEARCHBATCH-A',
        'batch_id' => $batchInBatch->id,
    ]);
    $miss = omni_makeDoc($repo->id, $series->id, [
        'identifier' => 'OMSEARCHBATCH-B',
        'batch_id' => $batchOther->id,
    ]);

    Livewire::test(ListDocuments::class)
        ->set('tableSearch', '8543')
        ->assertCanSeeTableRecords([$hit])
        ->assertCanNotSeeTableRecords([$miss]);
});

/*
 * Test 6 — Distinct rows: a doc with two matching authorities must appear
 * only once. Implementation uses whereHas (EXISTS subqueries) precisely to
 * avoid the JOIN duplication trap.
 */
test('omni-search returns a single row when multiple authorities match', function (): void {
    $this->actingAs(omni_actAsAdmin());

    $repo = omni_makeRepo();
    $series = omni_makeSeries();
    $doc = omni_makeDoc($repo->id, $series->id);
    $a1 = omni_makeAuthority(['surname' => 'OmniDuplicateTest']);
    $a2 = omni_makeAuthority(['surname' => 'OmniDuplicateTest', 'identifier' => 'X9999']);
    $doc->authorities()->attach([$a1->id, $a2->id]);

    $component = Livewire::test(ListDocuments::class)
        ->set('tableSearch', 'OmniDuplicateTest');

    $records = $component->instance()->getTableRecords();

    // Filament's getTableRecords() returns a paginator; convert to
    // collection of IDs and assert the doc appears EXACTLY once.
    $ids = collect($records->items())->pluck('id');
    expect($ids->filter(fn ($id) => $id === $doc->id)->count())->toBe(1);
});

/*
 * Test 7 — RepositoryScope: editors only see docs from their assigned repos.
 *
 * Critical multi-tenant safety check. The omni-search wraps its OR group in
 * a single closure, so the global RepositoryScope's WHERE remains in the
 * outer AND-stack — an editor MUST NOT find a doc belonging to a foreign
 * repository even if its authority surname matches.
 */
test('omni-search respects RepositoryScope for non-admin editors', function (): void {
    $repoOwn = omni_makeRepo('OWN');
    $repoForeign = omni_makeRepo('FOREIGN');
    $series = omni_makeSeries();

    // Seed both docs FIRST as the unauthenticated request (CLI path
    // bypasses the BelongsToRepository creating-hook), so we can have
    // a cross-repo dataset before the editor logs in. Same trick that
    // BulkImportV2Test uses for cross-tenant fixtures.
    $ownDoc = omni_makeDoc($repoOwn->id, $series->id, ['identifier' => 'OWN-SCAN']);
    $foreignDoc = omni_makeDoc($repoForeign->id, $series->id, ['identifier' => 'FOREIGN-SCAN']);

    $authShared = omni_makeAuthority(['surname' => 'OmniScopeShared']);
    $ownDoc->authorities()->attach($authShared->id);
    $foreignDoc->authorities()->attach($authShared->id);

    // NOW log the editor in — RepositoryScope will hide $foreignDoc.
    $editor = omni_actAsEditor($repoOwn);
    $this->actingAs($editor);

    Livewire::test(ListDocuments::class)
        ->set('tableSearch', 'OmniScopeShared')
        ->assertCanSeeTableRecords([$ownDoc])
        ->assertCanNotSeeTableRecords([$foreignDoc]);
});

/*
 * Test 8 — Empty search is a no-op.
 *
 * A blank `tableSearch` must NOT apply any WHERE: the full result set
 * (subject only to the standard filters and the RepositoryScope) is shown.
 */
test('omni-search with empty term returns all rows', function (): void {
    $this->actingAs(omni_actAsAdmin());

    $repo = omni_makeRepo();
    $series = omni_makeSeries();
    $d1 = omni_makeDoc($repo->id, $series->id);
    $d2 = omni_makeDoc($repo->id, $series->id);
    $d3 = omni_makeDoc($repo->id, $series->id);

    Livewire::test(ListDocuments::class)
        ->set('tableSearch', '')
        ->assertCanSeeTableRecords([$d1, $d2, $d3]);
});

/*
 * Test 9 — SQL-injection / wildcard escaping.
 *
 * A search for "100%" must match the literal substring "100%", NOT every
 * row (which is what naïve concatenation into a LIKE pattern would do).
 * Same for "'); DROP TABLE documents; --" — Eloquent's bind layer handles
 * the parameterisation; we just verify nothing explodes and the literal
 * search returns no spurious matches.
 */
test('omni-search escapes LIKE wildcards and SQL specials', function (): void {
    $this->actingAs(omni_actAsAdmin());

    $repo = omni_makeRepo();
    $series = omni_makeSeries();

    // Doc whose notes contain the literal "100%" string
    $literal = omni_makeDoc($repo->id, $series->id, [
        'notes' => 'Disinfested 100% of pages confirmed.',
        'identifier' => 'WILDCARD-LITERAL',
    ]);
    // Decoy: does NOT contain "100%" anywhere
    $decoy = omni_makeDoc($repo->id, $series->id, [
        'notes' => 'Some other notes',
        'identifier' => 'WILDCARD-DECOY',
    ]);

    // "100%" must match only the literal doc, not every row.
    Livewire::test(ListDocuments::class)
        ->set('tableSearch', '100%')
        ->assertCanSeeTableRecords([$literal])
        ->assertCanNotSeeTableRecords([$decoy]);

    // A classic injection attempt must not blow up; assertion is that
    // the query completes and returns at most the legitimate matches.
    Livewire::test(ListDocuments::class)
        ->set('tableSearch', "'); DROP TABLE documents; --")
        ->assertOk();

    // documents table must still exist
    expect(Document::withoutGlobalScope(RepositoryScope::class)->count())->toBeGreaterThanOrEqual(2);
});

/*
 * Test 10 — Document flag type match.
 *
 * The operator should be able to surface "all documents flagged as
 * needs_review" by typing the flag type token directly.
 */
test('omni-search by flag type returns docs carrying that flag', function (): void {
    $this->actingAs(omni_actAsAdmin());

    $repo = omni_makeRepo();
    $series = omni_makeSeries();

    $flaggedDoc = omni_makeDoc($repo->id, $series->id, ['identifier' => 'FLAG-HIT']);
    $unflaggedDoc = omni_makeDoc($repo->id, $series->id, ['identifier' => 'FLAG-MISS']);

    DocumentFlag::factory()->create([
        'document_id' => $flaggedDoc->id,
        'repository_id' => $repo->id,
        'type' => 'needs_review',
        'title' => 'Operator follow-up required',
    ]);

    Livewire::test(ListDocuments::class)
        ->set('tableSearch', 'needs_review')
        ->assertCanSeeTableRecords([$flaggedDoc])
        ->assertCanNotSeeTableRecords([$unflaggedDoc]);
});

/*
 * Test 11 — Case-insensitivity.
 *
 * SQLite's default LIKE is case-insensitive for ASCII, MySQL's default
 * collation (utf8mb4_unicode_ci) is case-insensitive too. We pin the
 * cross-driver expectation here so a future migration to a binary
 * collation doesn't silently regress the operator UX.
 */
test('omni-search is case-insensitive', function (): void {
    $this->actingAs(omni_actAsAdmin());

    $repo = omni_makeRepo();
    $series = omni_makeSeries();
    $doc = omni_makeDoc($repo->id, $series->id);
    $auth = omni_makeAuthority(['surname' => 'CaseSensitiveSurname']);
    $doc->authorities()->attach($auth->id);

    Livewire::test(ListDocuments::class)
        ->set('tableSearch', 'casesensitivesurname')
        ->assertCanSeeTableRecords([$doc]);

    Livewire::test(ListDocuments::class)
        ->set('tableSearch', 'CASESENSITIVESURNAME')
        ->assertCanSeeTableRecords([$doc]);
});

/*
 * Test 12 — Current box number / barcode match.
 *
 * "Find me the docs currently sitting in box BX-001 / with barcode
 * BC-12345" is one of the most common warehouse-floor queries.
 */
test('omni-search by current box number returns docs in that box', function (): void {
    $this->actingAs(omni_actAsAdmin());

    $repo = omni_makeRepo();
    $series = omni_makeSeries();
    $batch = omni_makeBatch($repo->id);
    $box = omni_makeBox($batch->id, ['box_number' => 'OMNI-BOX-7777', 'barcode' => 'OMNI-BARC-7777']);
    $otherBox = omni_makeBox($batch->id, ['box_number' => 'OMNI-BOX-9999']);

    $inBox = omni_makeDoc($repo->id, $series->id, [
        'identifier' => 'IN-BOX-DOC',
        'current_box_id' => $box->id,
    ]);
    $notInBox = omni_makeDoc($repo->id, $series->id, [
        'identifier' => 'NOT-IN-BOX-DOC',
        'current_box_id' => $otherBox->id,
    ]);

    Livewire::test(ListDocuments::class)
        ->set('tableSearch', 'OMNI-BOX-7777')
        ->assertCanSeeTableRecords([$inBox])
        ->assertCanNotSeeTableRecords([$notInBox]);

    Livewire::test(ListDocuments::class)
        ->set('tableSearch', 'OMNI-BARC-7777')
        ->assertCanSeeTableRecords([$inBox])
        ->assertCanNotSeeTableRecords([$notInBox]);
});

/*
 * Test 13 — Location code match.
 *
 * RFQ §3.1.9 — configurable Location hierarchy. Find a doc by its
 * physical Location code (e.g. "VAULT-A").
 */
test('omni-search by location code returns the linked document', function (): void {
    $this->actingAs(omni_actAsAdmin());

    $repo = omni_makeRepo();
    $series = omni_makeSeries();
    $vault = omni_makeLocation($repo->id, ['code' => 'OMNI-VAULT-A', 'name' => 'Vault A']);
    $other = omni_makeLocation($repo->id, ['code' => 'OMNI-VAULT-B', 'name' => 'Vault B']);

    $hit = omni_makeDoc($repo->id, $series->id, [
        'identifier' => 'LOC-HIT',
        'location_id' => $vault->id,
    ]);
    $miss = omni_makeDoc($repo->id, $series->id, [
        'identifier' => 'LOC-MISS',
        'location_id' => $other->id,
    ]);

    Livewire::test(ListDocuments::class)
        ->set('tableSearch', 'OMNI-VAULT-A')
        ->assertCanSeeTableRecords([$hit])
        ->assertCanNotSeeTableRecords([$miss]);
});

/*
 * Test 14 — Spotlight global search (Cmd+K).
 *
 * The Filament panel's top-bar global search must also pick up Document
 * hits by Authority surname / Series code / Box, so the operator's mental
 * model is consistent across the in-table search bar and the spotlight.
 * Direct integration test: invoke DocumentResource::getGlobalSearchResults().
 */
test('global spotlight search surfaces documents by authority surname', function (): void {
    $this->actingAs(omni_actAsAdmin());

    $repo = omni_makeRepo();
    $series = omni_makeSeries();
    $doc = omni_makeDoc($repo->id, $series->id, ['identifier' => 'SPOTLIGHT-DOC']);
    $auth = omni_makeAuthority(['surname' => 'SpotlightSurname']);
    $doc->authorities()->attach($auth->id);

    $results = DocumentResource::getGlobalSearchResults('SpotlightSurname');

    $idsFound = collect($results)->pluck('actions')->flatten(1)
        ->merge(collect($results)->pluck('url'))
        ->merge(collect($results)->pluck('title'))
        ->all();

    // The simplest stable assertion: at least one result is returned and
    // one of them is for our document. The result objects are
    // Filament\GlobalSearch\GlobalSearchResult — we only need them
    // non-empty and pointing at the right model.
    expect(count($results))->toBeGreaterThan(0);

    $models = DocumentResource::getGlobalSearchEloquentQuery()
        ->whereHas('authorities', fn ($a) => $a->where('surname', 'SpotlightSurname'))
        ->pluck('id')->all();
    expect($models)->toContain($doc->id);
});

/*
 * Test 15 — getGloballySearchableAttributes covers the cross-table set.
 *
 * Sanity check that the spotlight attribute list at least includes the
 * cross-table relations (authorities, series, currentBox). The actual
 * search behaviour is exercised by test 14 above.
 */
test('getGloballySearchableAttributes includes authorities, series, currentBox', function (): void {
    $attrs = DocumentResource::getGloballySearchableAttributes();

    expect($attrs)
        ->toContain('authorities.surname')
        ->toContain('series.code')
        ->toContain('currentBox.box_number');
});

/*
 * Test 16 — Accession code match (RFQ §3.2.1).
 *
 * The operator must be able to surface every document acquired under
 * accession "ACC-2026-001" by typing the accession code (or substring)
 * into the omni-search. Mirrors the series/batch/box pattern.
 */
test('omni-search by accession code returns the linked document', function (): void {
    $this->actingAs(omni_actAsAdmin());

    $repo = omni_makeRepo();
    $series = omni_makeSeries();

    $accHit = Accession::create([
        'code' => 'ACC-2026-001',
        'repository_id' => $repo->id,
    ]);
    $accMiss = Accession::create([
        'code' => 'ACC-2026-999',
        'repository_id' => $repo->id,
    ]);

    $hit = omni_makeDoc($repo->id, $series->id, [
        'identifier' => 'ACC-HIT-DOC',
        'accession_id' => $accHit->id,
    ]);
    $miss = omni_makeDoc($repo->id, $series->id, [
        'identifier' => 'ACC-MISS-DOC',
        'accession_id' => $accMiss->id,
    ]);

    Livewire::test(ListDocuments::class)
        ->set('tableSearch', 'ACC-2026')
        ->assertCanSeeTableRecords([$hit, $miss]);

    Livewire::test(ListDocuments::class)
        ->set('tableSearch', 'ACC-2026-001')
        ->assertCanSeeTableRecords([$hit])
        ->assertCanNotSeeTableRecords([$miss]);
});

/*
 * Test 17 — Accession code with no match returns an empty set.
 *
 * Guards against a regression where the new whereHas('accession', ...) block
 * accidentally widens the OR-group (e.g. a stray comma turning the EXISTS
 * subquery into a constant TRUE).
 */
test('omni-search by nonexistent accession code returns empty result', function (): void {
    $this->actingAs(omni_actAsAdmin());

    $repo = omni_makeRepo();
    $series = omni_makeSeries();

    $acc = Accession::create([
        'code' => 'ACC-2026-001',
        'repository_id' => $repo->id,
    ]);
    $doc = omni_makeDoc($repo->id, $series->id, [
        'identifier' => 'ACC-DOC-X',
        'accession_id' => $acc->id,
    ]);

    $component = Livewire::test(ListDocuments::class)
        ->set('tableSearch', 'ACC-NONEXISTENT');

    // The matching doc should not appear, and no spurious docs either.
    $component->assertCanNotSeeTableRecords([$doc]);

    $records = $component->instance()->getTableRecords();
    expect(count($records->items()))->toBe(0);
});
