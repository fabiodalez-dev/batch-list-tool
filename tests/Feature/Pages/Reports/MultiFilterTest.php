<?php

declare(strict_types=1);

use App\Filament\Pages\Reports\PendingDisinfestationReport;
use App\Models\Authority;
use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * RFQ §3.2 — Rich filter panels on the report pages.
 *
 * Asserts:
 *   1. Multi-select repository_id + series_id compose with AND semantics
 *   2. has_open_flags=true + uncatalogued=true narrow correctly
 *   3. authorities multi-select returns the UNION of authority matches
 *      (OR semantics across the picked authorities)
 *   4. Filters never bypass RepositoryScope
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function mft_seedRoles(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function mft_user(string $role = 'super_admin'): User
{
    mft_seedRoles();
    $u = User::factory()->create([
        'email' => 'mft-' . $role . '-' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole($role);

    return $u;
}

function mft_repo(string $prefix = 'MFT'): Repository
{
    return Repository::factory()->create(['code' => $prefix . '_' . substr(uniqid(), -6)]);
}

function mft_series(string $prefix = 'M'): Series
{
    return Series::firstOrCreate(
        ['code' => $prefix . '_' . substr(uniqid(), -4)],
        ['title' => $prefix . ' series', 'is_active' => true],
    );
}

function mft_doc(int $repoId, int $seriesId, array $attrs = []): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'MFT-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'TEST',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
        'disinfestation_date' => null,
    ], $attrs));
}

function mft_authority(string $surname): Authority
{
    return Authority::create([
        'identifier' => 'A-MFT-' . strtoupper(substr(uniqid(), -6)),
        'surname' => $surname,
        'entity_type' => 'PERSON',
    ]);
}

function mft_open_flag(int $docId, User $user): DocumentFlag
{
    return DocumentFlag::create([
        'document_id' => $docId,
        'type' => 'needs_review',
        'severity' => 'warning',
        'status' => 'open',
        'flagged_by_user_id' => $user->id,
        'flagged_at' => now(),
        'title' => 'Needs review',
        'description' => 'test flag',
    ]);
}

test('repository_id + series_id multi-select narrow correctly (AND semantics)', function () {
    $admin = mft_user();
    $this->actingAs($admin);

    $repoA = mft_repo('A');
    $repoB = mft_repo('B');
    $seriesX = mft_series('X');
    $seriesY = mft_series('Y');

    $hitId = mft_doc($repoA->id, $seriesX->id)->id;    // matches both filters
    $repoMiss = mft_doc($repoB->id, $seriesX->id)->id; // wrong repo
    $seriesMiss = mft_doc($repoA->id, $seriesY->id)->id; // wrong series

    Livewire::test(PendingDisinfestationReport::class)
        ->set('tableFilters.repository_id.values', [$repoA->id])
        ->set('tableFilters.series_id.values', [$seriesX->id])
        ->assertCanSeeTableRecords(Document::query()->where('id', $hitId)->get())
        ->assertCanNotSeeTableRecords(Document::query()->whereIn('id', [$repoMiss, $seriesMiss])->get());
});

test('has_open_flags=true + uncatalogued=true narrow further', function () {
    $admin = mft_user();
    $this->actingAs($admin);

    $repo = mft_repo();
    $series = mft_series();

    // matches both: open flag + null catalogue_identifier
    $hit = mft_doc($repo->id, $series->id, ['catalogue_identifier' => null]);
    mft_open_flag($hit->id, $admin);

    // open flag but already catalogued — should be excluded by uncatalogued=true
    $flaggedCatalogued = mft_doc($repo->id, $series->id, ['catalogue_identifier' => 'CAT-A']);
    mft_open_flag($flaggedCatalogued->id, $admin);

    // uncatalogued but no flag — should be excluded by has_open_flags=true
    $uncatNoFlag = mft_doc($repo->id, $series->id, ['catalogue_identifier' => null]);

    Livewire::test(PendingDisinfestationReport::class)
        ->set('tableFilters.has_open_flags.value', true)
        ->set('tableFilters.uncatalogued.value', true)
        ->assertCanSeeTableRecords(Document::query()->where('id', $hit->id)->get())
        ->assertCanNotSeeTableRecords(Document::query()
            ->whereIn('id', [$flaggedCatalogued->id, $uncatNoFlag->id])->get());
});

test('authorities multi-select returns the UNION of authority matches (OR semantics)', function () {
    $admin = mft_user();
    $this->actingAs($admin);

    $repo = mft_repo();
    $series = mft_series();

    $a1 = mft_authority('AlphaPicked');
    $a2 = mft_authority('BravoPicked');
    $a3 = mft_authority('CharlieNotPicked');

    $docA1 = mft_doc($repo->id, $series->id);
    $docA1->authorities()->attach($a1->id);

    $docA2 = mft_doc($repo->id, $series->id);
    $docA2->authorities()->attach($a2->id);

    $docA3 = mft_doc($repo->id, $series->id);
    $docA3->authorities()->attach($a3->id);

    Livewire::test(PendingDisinfestationReport::class)
        ->set('tableFilters.authorities.values', [$a1->id, $a2->id])
        ->assertCanSeeTableRecords(Document::query()->whereIn('id', [$docA1->id, $docA2->id])->get())
        ->assertCanNotSeeTableRecords(Document::query()->where('id', $docA3->id)->get());
});

test('filters do not bypass RepositoryScope (cross-tenant safety)', function () {
    mft_seedRoles();

    $repoA = mft_repo('A');
    $repoB = mft_repo('B');
    $series = mft_series();

    $docA = mft_doc($repoA->id, $series->id);
    $docB = mft_doc($repoB->id, $series->id);

    $editor = User::factory()->create([
        'email' => 'mft-editor-' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $editor->assignRole('editor');
    $editor->repositories()->attach($repoA->id);
    $editor->default_repository_id = $repoA->id;
    $editor->save();

    $this->actingAs($editor);

    // Editor attempts to filter by repoB — which they shouldn't see at all.
    // Even passing repoB in the filter must NOT leak its document into the table.
    Livewire::test(PendingDisinfestationReport::class)
        ->set('tableFilters.repository_id.values', [$repoB->id])
        ->assertCanNotSeeTableRecords(Document::query()->whereIn('id', [$docA->id, $docB->id])->get());

    // And without any filter, editor sees their tenant's doc only.
    Livewire::test(PendingDisinfestationReport::class)
        ->assertCanSeeTableRecords(Document::query()->where('id', $docA->id)->get())
        ->assertCanNotSeeTableRecords(Document::query()->where('id', $docB->id)->get());
});
