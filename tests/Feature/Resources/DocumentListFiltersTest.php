<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
 * RFQ APP2-xviii and APP2-viii / REQ-3.2.2.
 *
 * Two new ternary filters on /admin/documents:
 *   - has_open_flags: narrows the list to docs carrying at least one flag
 *     with status IN ('open','acknowledged'); the false branch surfaces docs
 *     that are either flag-free or whose flags have all been resolved.
 *   - uncatalogued: narrows the list by the NULL-ness of catalogue_identifier.
 *
 * The tests exercise the Livewire-mediated filter contract via Filament's
 * Pages\ListDocuments so the integration touches the same code path that the
 * operator triggers in the browser.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

function filt_rolesExist(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function filt_actAsAdmin(): User
{
    filt_rolesExist();
    $u = User::factory()->create([
        'email' => 'filt-admin+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function filt_makeRepo(): Repository
{
    return Repository::factory()->create([
        'code' => 'FLT_' . substr(uniqid(), -6),
    ]);
}

function filt_makeSeries(): Series
{
    return Series::firstOrCreate(
        ['code' => 'FLTS_' . substr(uniqid(), -4)],
        ['title' => 'FLT series', 'is_active' => true],
    );
}

function filt_makeDoc(int $repoId, int $seriesId, array $attrs = []): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'FLT-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'TEST',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
    ], $attrs));
}

/*
 * Test 1 — has_open_flags = true.
 *
 * Returns only docs with at least one flag in ('open','acknowledged').
 * Docs whose flags are exclusively resolved/dismissed must NOT appear,
 * nor must flag-free docs.
 */
test('filter has_open_flags=true returns only docs with open or acknowledged flags', function (): void {
    $this->actingAs(filt_actAsAdmin());

    $repo = filt_makeRepo();
    $series = filt_makeSeries();

    $withOpen = filt_makeDoc($repo->id, $series->id, ['identifier' => 'FLAG-OPEN']);
    $withAck = filt_makeDoc($repo->id, $series->id, ['identifier' => 'FLAG-ACK']);
    $withResolved = filt_makeDoc($repo->id, $series->id, ['identifier' => 'FLAG-RESOLVED']);
    $withDismissed = filt_makeDoc($repo->id, $series->id, ['identifier' => 'FLAG-DISMISSED']);
    $flagless = filt_makeDoc($repo->id, $series->id, ['identifier' => 'FLAG-NONE']);

    DocumentFlag::factory()->create([
        'document_id' => $withOpen->id,
        'status' => 'open',
        'type' => 'needs_review',
    ]);
    DocumentFlag::factory()->acknowledged()->create([
        'document_id' => $withAck->id,
        'type' => 'needs_review',
    ]);
    DocumentFlag::factory()->resolved()->create([
        'document_id' => $withResolved->id,
        'type' => 'needs_review',
    ]);
    DocumentFlag::factory()->dismissed()->create([
        'document_id' => $withDismissed->id,
        'type' => 'needs_review',
    ]);

    Livewire::test(ListDocuments::class)
        ->filterTable('has_open_flags', true)
        ->assertCanSeeTableRecords([$withOpen, $withAck])
        ->assertCanNotSeeTableRecords([$withResolved, $withDismissed, $flagless]);
});

/*
 * Test 2 — has_open_flags = false.
 *
 * Returns only docs with NO open/acknowledged flags. Docs that have only
 * resolved/dismissed flags must appear (the workflow is closed for them),
 * along with flag-free docs.
 */
test('filter has_open_flags=false returns only docs with no open or acknowledged flags', function (): void {
    $this->actingAs(filt_actAsAdmin());

    $repo = filt_makeRepo();
    $series = filt_makeSeries();

    $withOpen = filt_makeDoc($repo->id, $series->id, ['identifier' => 'FLAG-OPEN-2']);
    $withAck = filt_makeDoc($repo->id, $series->id, ['identifier' => 'FLAG-ACK-2']);
    $withResolved = filt_makeDoc($repo->id, $series->id, ['identifier' => 'FLAG-RESOLVED-2']);
    $withDismissed = filt_makeDoc($repo->id, $series->id, ['identifier' => 'FLAG-DISMISSED-2']);
    $flagless = filt_makeDoc($repo->id, $series->id, ['identifier' => 'FLAG-NONE-2']);

    DocumentFlag::factory()->create([
        'document_id' => $withOpen->id,
        'status' => 'open',
        'type' => 'needs_review',
    ]);
    DocumentFlag::factory()->acknowledged()->create([
        'document_id' => $withAck->id,
        'type' => 'needs_review',
    ]);
    DocumentFlag::factory()->resolved()->create([
        'document_id' => $withResolved->id,
        'type' => 'needs_review',
    ]);
    DocumentFlag::factory()->dismissed()->create([
        'document_id' => $withDismissed->id,
        'type' => 'needs_review',
    ]);

    Livewire::test(ListDocuments::class)
        ->filterTable('has_open_flags', false)
        ->assertCanSeeTableRecords([$withResolved, $withDismissed, $flagless])
        ->assertCanNotSeeTableRecords([$withOpen, $withAck]);
});

/*
 * Test 3 — uncatalogued = true.
 *
 * RFQ APP2-viii requires the operator to triage docs that still need a
 * catalogue identifier assigned. "true" = catalogued (catalogue_identifier
 * IS NOT NULL); "false" = uncatalogued (catalogue_identifier IS NULL).
 *
 * The ternary's `true` branch should narrow to catalogued-only.
 */
test('filter uncatalogued=true returns only docs with a catalogue_identifier', function (): void {
    $this->actingAs(filt_actAsAdmin());

    $repo = filt_makeRepo();
    $series = filt_makeSeries();

    $catalogued = filt_makeDoc($repo->id, $series->id, [
        'identifier' => 'CAT-YES-1',
        'catalogue_identifier' => 'CAT-001',
    ]);
    $cataloguedB = filt_makeDoc($repo->id, $series->id, [
        'identifier' => 'CAT-YES-2',
        'catalogue_identifier' => 'CAT-002',
    ]);
    $uncatalogued = filt_makeDoc($repo->id, $series->id, [
        'identifier' => 'CAT-NO-1',
        'catalogue_identifier' => null,
    ]);

    Livewire::test(ListDocuments::class)
        ->filterTable('uncatalogued', true)
        ->assertCanSeeTableRecords([$catalogued, $cataloguedB])
        ->assertCanNotSeeTableRecords([$uncatalogued]);
});

/*
 * Test 4 — uncatalogued = false.
 *
 * The ternary's `false` branch should narrow to uncatalogued-only
 * (catalogue_identifier IS NULL).
 */
test('filter uncatalogued=false returns only docs without a catalogue_identifier', function (): void {
    $this->actingAs(filt_actAsAdmin());

    $repo = filt_makeRepo();
    $series = filt_makeSeries();

    $catalogued = filt_makeDoc($repo->id, $series->id, [
        'identifier' => 'CAT-YES-3',
        'catalogue_identifier' => 'CAT-003',
    ]);
    $uncatalogued = filt_makeDoc($repo->id, $series->id, [
        'identifier' => 'CAT-NO-2',
        'catalogue_identifier' => null,
    ]);
    $uncataloguedB = filt_makeDoc($repo->id, $series->id, [
        'identifier' => 'CAT-NO-3',
        'catalogue_identifier' => null,
    ]);

    Livewire::test(ListDocuments::class)
        ->filterTable('uncatalogued', false)
        ->assertCanSeeTableRecords([$uncatalogued, $uncataloguedB])
        ->assertCanNotSeeTableRecords([$catalogued]);
});
