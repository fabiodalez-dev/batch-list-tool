<?php

declare(strict_types=1);

use App\Filament\Resources\AuthorityResource\Pages\ListAuthorities;
use App\Models\Authority;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Feedback1 Wave A — Delete guard: a creator that still has documents
 * attached must NOT be deletable (row action hidden), and the bulk delete
 * must skip such creators so documents are never orphaned.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function dg_actAsSuperAdmin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    $u = User::factory()->create([
        'email' => 'dg-sa+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function dg_authority(): Authority
{
    return Authority::create([
        'identifier' => 'R' . random_int(10000, 99999),
        'surname' => 'Surname' . substr(uniqid(), -4),
        'given_names' => 'Given',
        'entity_type' => 'Notary',
    ]);
}

function dg_attachDocument(Authority $authority): Document
{
    $repo = Repository::factory()->create(['code' => 'DG_' . strtoupper(substr(uniqid(), -6))]);
    $series = Series::firstOrCreate(
        ['code' => 'DGS_' . substr(uniqid(), -4)],
        ['title' => 'DG series', 'is_active' => true],
    );

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'DG-DOC-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'TEST',
        'series_id' => $series->id,
        'repository_id' => $repo->id,
    ]);
    $authority->documents()->attach($doc->id);

    return $doc;
}

it('hides the row Delete action when the creator has documents', function () {
    $this->actingAs(dg_actAsSuperAdmin());

    $withDocs = dg_authority();
    dg_attachDocument($withDocs);

    Livewire::test(ListAuthorities::class)
        ->assertOk()
        ->assertTableActionHidden('delete', $withDocs);
});

it('shows the row Delete action when the creator has no documents', function () {
    $this->actingAs(dg_actAsSuperAdmin());

    $free = dg_authority();

    Livewire::test(ListAuthorities::class)
        ->assertOk()
        ->assertTableActionVisible('delete', $free);
});

it('bulk delete keeps creators that have documents and deletes the document-free ones', function () {
    $this->actingAs(dg_actAsSuperAdmin());

    $withDocs = dg_authority();
    dg_attachDocument($withDocs);

    $free = dg_authority();

    Livewire::test(ListAuthorities::class)
        ->assertOk()
        ->callTableBulkAction('delete', [$withDocs, $free]);

    // The free creator is soft-deleted; the one with documents survives.
    expect(Authority::find($free->id))->toBeNull()
        ->and(Authority::find($withDocs->id))->not->toBeNull();
});
