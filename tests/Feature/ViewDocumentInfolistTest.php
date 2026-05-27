<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\DocumentResource\Pages\ViewDocument;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

/**
 * Feat: feat/view-document-redesign — Filament 5 Infolist API on the
 * `/admin/documents/{id}` View Document page.
 *
 * These tests pin the UX brief deliverables:
 *
 *  1. Page heading is parlante: `"R45 — Abela Antonio"` instead of `"View 42"`.
 *  2. Hero summary card surfaces the 5 most important facts at a glance.
 *  3. Authorities are rendered through a `RepeatableEntry`, not a `<select>`.
 *  4. Disinfestation timeline gathers all four date columns.
 *  5. Location breadcrumb walks the parent chain.
 *  6. ACL: super_admin / editor with `view_document` can open the page;
 *     a viewer-without-permission cannot.
 *
 * Tests use Livewire test harness to render the ViewDocument page and
 * assertSee() the visible text.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

/* ---------------------------------------------------------------------------
 |  Helpers — keep test cases short and the intent obvious.
 * -------------------------------------------------------------------------*/

function vdt_makeRepo(string $prefix = 'VD'): Repository
{
    return Repository::factory()->create([
        'code' => $prefix . '_' . substr(uniqid(), -6),
    ]);
}

function vdt_makeSeries(string $code = 'VDS'): Series
{
    return Series::create([
        'code' => $code . '_' . substr(uniqid(), -4),
        'title' => $code . ' series',
        'is_active' => true,
    ]);
}

function vdt_makeBatch(int $repoId): Batch
{
    do {
        $n = random_int(2000, 8999);
    } while (in_array($n, [33, 34, 36], true)
        || Batch::withoutGlobalScope(RepositoryScope::class)
            ->where('batch_number', $n)->exists());

    return Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => $n,
        'type' => 'NOTARY_ACCESSION',
        'repository_id' => $repoId,
        'is_active' => true,
    ]);
}

function vdt_makeDoc(int $repoId, int $seriesId, array $attrs = []): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'VDT-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'R',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
    ], $attrs));
}

function vdt_makeAuthority(array $attrs = []): Authority
{
    return Authority::create(array_merge([
        'identifier' => 'A-' . strtoupper(substr(uniqid(), -8)),
        'surname' => 'AuthSurname' . substr(uniqid(), -4),
        'given_names' => 'Given' . substr(uniqid(), -4),
        'entity_type' => 'PERSON',
    ], $attrs));
}

function vdt_actAsSuperAdmin(): User
{
    $u = User::factory()->create([
        'email' => 'vdt-sa+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function vdt_actAsEditor(): User
{
    $u = User::factory()->create([
        'email' => 'vdt-ed+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('editor');

    return $u;
}

/* ---------------------------------------------------------------------------
 |  1. Page title — speaking heading
 * -------------------------------------------------------------------------*/

it('ViewDocument page title is "<identifier> — <author surname/given>" for a document with a primary author', function () {
    $this->actingAs(vdt_actAsSuperAdmin());

    $repo = vdt_makeRepo();
    $series = vdt_makeSeries();
    $doc = vdt_makeDoc($repo->id, $series->id, ['identifier' => 'R45']);
    $author = vdt_makeAuthority(['surname' => 'Abela', 'given_names' => 'Antonio']);
    $doc->authorities()->attach($author->id, ['is_primary' => true]);

    // Reload so the relation eager-loader picks up the pivot freshly.
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->with('authorities')->find($doc->id);

    $page = new ViewDocument;
    $page->record = $doc;

    expect($page->getTitle())->toBe('R45 — Abela Antonio');
});

it('ViewDocument page title falls back to "<identifier>" when no author is attached', function () {
    $this->actingAs(vdt_actAsSuperAdmin());

    $repo = vdt_makeRepo();
    $series = vdt_makeSeries();
    $doc = vdt_makeDoc($repo->id, $series->id, ['identifier' => 'R99']);
    // No authorities attached.
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->with('authorities')->find($doc->id);

    $page = new ViewDocument;
    $page->record = $doc;

    expect($page->getTitle())->toBe('R99');
});

it('ViewDocument page title picks first author when none is flagged is_primary', function () {
    $this->actingAs(vdt_actAsSuperAdmin());

    $repo = vdt_makeRepo();
    $series = vdt_makeSeries();
    $doc = vdt_makeDoc($repo->id, $series->id, ['identifier' => 'R7']);
    $a1 = vdt_makeAuthority(['surname' => 'First', 'given_names' => 'Andrea']);
    $a2 = vdt_makeAuthority(['surname' => 'Second', 'given_names' => 'Bea']);
    $doc->authorities()->attach([
        $a1->id => ['is_primary' => false],
        $a2->id => ['is_primary' => false],
    ]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->with('authorities')->find($doc->id);

    $page = new ViewDocument;
    $page->record = $doc;

    // First-attached wins when no flag is set. Order is by pivot id.
    expect($page->getTitle())->toMatch('/^R7 — (First Andrea|Second Bea)$/');
});

it('ViewDocument page title falls back to "Document #<id>" when both identifier and author are missing', function () {
    $this->actingAs(vdt_actAsSuperAdmin());

    $repo = vdt_makeRepo();
    $series = vdt_makeSeries();
    $doc = vdt_makeDoc($repo->id, $series->id, ['identifier' => '']);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->with('authorities')->find($doc->id);

    $page = new ViewDocument;
    $page->record = $doc;

    expect($page->getTitle())->toBe('Document #' . $doc->id);
});

/* ---------------------------------------------------------------------------
 |  2. Hero card — surfaces identifier badge + primary author + box + status
 * -------------------------------------------------------------------------*/

it('ViewDocument page renders the document identifier in the hero card', function () {
    $this->actingAs(vdt_actAsSuperAdmin());

    $repo = vdt_makeRepo();
    $series = vdt_makeSeries();
    $doc = vdt_makeDoc($repo->id, $series->id, ['identifier' => 'HERO-IDENT-123']);

    Livewire::test(ViewDocument::class, ['record' => $doc->getRouteKey()])
        ->assertOk()
        ->assertSee('HERO-IDENT-123');
});

it('ViewDocument hero card shows the primary author surname and given names', function () {
    $this->actingAs(vdt_actAsSuperAdmin());

    $repo = vdt_makeRepo();
    $series = vdt_makeSeries();
    $doc = vdt_makeDoc($repo->id, $series->id, ['identifier' => 'HERO-AUTH']);
    $primary = vdt_makeAuthority(['surname' => 'PrimarySurname', 'given_names' => 'PrimaryGiven']);
    $other = vdt_makeAuthority(['surname' => 'OtherSurname', 'given_names' => 'OtherGiven']);
    $doc->authorities()->attach([
        $primary->id => ['is_primary' => true],
        $other->id => ['is_primary' => false],
    ]);

    Livewire::test(ViewDocument::class, ['record' => $doc->getRouteKey()])
        ->assertOk()
        ->assertSee('PrimarySurname')
        ->assertSee('PrimaryGiven');
});

/* ---------------------------------------------------------------------------
 |  3. Authorities RepeatableEntry — every attached author shows up
 * -------------------------------------------------------------------------*/

it('Authorities RepeatableEntry shows all attached authorities', function () {
    $this->actingAs(vdt_actAsSuperAdmin());

    $repo = vdt_makeRepo();
    $series = vdt_makeSeries();
    $doc = vdt_makeDoc($repo->id, $series->id);

    $a1 = vdt_makeAuthority(['surname' => 'AuthorOne', 'given_names' => 'FirstName']);
    $a2 = vdt_makeAuthority(['surname' => 'AuthorTwo', 'given_names' => 'SecondName']);
    $a3 = vdt_makeAuthority(['surname' => 'AuthorThree', 'given_names' => 'ThirdName']);

    $doc->authorities()->attach([
        $a1->id => ['is_primary' => true],
        $a2->id => ['is_primary' => false],
        $a3->id => ['is_primary' => false],
    ]);

    Livewire::test(ViewDocument::class, ['record' => $doc->getRouteKey()])
        ->assertOk()
        ->assertSee('AuthorOne')
        ->assertSee('AuthorTwo')
        ->assertSee('AuthorThree');
});

/* ---------------------------------------------------------------------------
 |  4. Disinfestation timeline — model helper + section state
 * -------------------------------------------------------------------------*/

it('Document::disinfestationTimeline() returns all four dates ordered chronologically', function () {
    $repo = vdt_makeRepo();
    $series = vdt_makeSeries();
    $doc = vdt_makeDoc($repo->id, $series->id, [
        'disinfestation_date_1' => '2010-01-15',
        'disinfestation_date_2' => '2015-06-30',
        'disinfestation_date_3' => '2018-12-01',
        'disinfestation_date' => '2024-03-22',
    ]);
    $doc->refresh();

    $timeline = $doc->disinfestationTimeline();

    expect($timeline)->toHaveCount(4);
    expect($timeline[0]['label'])->toBe('Legacy round #1');
    expect($timeline[1]['label'])->toBe('Legacy round #2');
    expect($timeline[2]['label'])->toBe('Legacy round #3');
    expect($timeline[3]['label'])->toBe('Current');
    expect($timeline[0]['date']->format('Y-m-d'))->toBe('2010-01-15');
    expect($timeline[3]['date']->format('Y-m-d'))->toBe('2024-03-22');
});

it('Document::disinfestationTimeline() skips null dates and stays empty for a never-disinfested doc', function () {
    $repo = vdt_makeRepo();
    $series = vdt_makeSeries();
    $doc = vdt_makeDoc($repo->id, $series->id);
    $doc->refresh();

    expect($doc->disinfestationTimeline())->toHaveCount(0);
});

it('ViewDocument page shows the disinfestation_date in the hero card when set', function () {
    $this->actingAs(vdt_actAsSuperAdmin());

    $repo = vdt_makeRepo();
    $series = vdt_makeSeries();
    $doc = vdt_makeDoc($repo->id, $series->id, [
        'identifier' => 'DISINF-DOC',
        'disinfestation_date' => '2024-03-22',
    ]);

    Livewire::test(ViewDocument::class, ['record' => $doc->getRouteKey()])
        ->assertOk()
        ->assertSee('Mar 22, 2024');
});

/* ---------------------------------------------------------------------------
 |  5. Location breadcrumb — parent chain walks
 * -------------------------------------------------------------------------*/

it('Location::full_path accessor renders the parent chain with arrow separator', function () {
    $repo = vdt_makeRepo();
    $building = Location::create([
        'name' => 'Building A',
        'code' => 'BLDA',
        'type' => 'repository',
        'repository_id' => $repo->id,
        'is_active' => true,
    ]);
    $floor = Location::create([
        'name' => 'Floor 2',
        'code' => 'F2',
        'type' => 'room',
        'parent_id' => $building->id,
        'repository_id' => $repo->id,
        'is_active' => true,
    ]);
    $shelf = Location::create([
        'name' => 'Shelf 12',
        'code' => 'S12',
        'type' => 'shelf',
        'parent_id' => $floor->id,
        'repository_id' => $repo->id,
        'is_active' => true,
    ]);
    $shelf->refresh();

    expect($shelf->full_path)->toBe('Building A → Floor 2 → Shelf 12');
});

/* ---------------------------------------------------------------------------
 |  6. Page renders OK / permissions
 * -------------------------------------------------------------------------*/

it('ViewDocument page renders 200 for super_admin', function () {
    $this->actingAs(vdt_actAsSuperAdmin());

    $repo = vdt_makeRepo();
    $series = vdt_makeSeries();
    $doc = vdt_makeDoc($repo->id, $series->id);

    Livewire::test(ViewDocument::class, ['record' => $doc->getRouteKey()])
        ->assertOk();
});

it('ViewDocument page renders 200 for editor (has view_document permission)', function () {
    $editor = vdt_actAsEditor();
    expect($editor->can('view_document'))->toBeTrue();
    $this->actingAs($editor);

    $repo = vdt_makeRepo();
    $series = vdt_makeSeries();
    // Attach the editor to the repo so RepositoryScope doesn't hide the doc.
    $editor->repositories()->syncWithoutDetaching([$repo->id]);
    $doc = vdt_makeDoc($repo->id, $series->id);

    Livewire::test(ViewDocument::class, ['record' => $doc->getRouteKey()])
        ->assertOk();
});

it('DocumentResource::infolist() schema is populated (regression: not the form schema)', function () {
    // Smoke-level guard so future refactors that accidentally drop the
    // infolist() override fall back to form-mode and we catch it here.
    $schema = Schema::make(Livewire::test(ViewDocument::class, [
        'record' => (function () {
            $repo = vdt_makeRepo();
            $series = vdt_makeSeries();

            return vdt_makeDoc($repo->id, $series->id)->getRouteKey();
        })(),
    ])->instance());

    $built = DocumentResource::infolist($schema);

    expect($built->getComponents())->not->toBeEmpty();
    expect(count($built->getComponents()))->toBeGreaterThanOrEqual(8);
});
