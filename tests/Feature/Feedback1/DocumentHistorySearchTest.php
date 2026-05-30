<?php

declare(strict_types=1);

use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Models\Document;
use App\Models\DocumentIdentifierHistory;
use App\Models\DocumentType;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Feedback1 Wave C2.5 — searchable history of PAST document identifiers AND
 * volume numbers. A document found by a former identifier/volume even after
 * its current identifier/volume differs, plus the editable history Repeater.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function dhs_actAsSuperAdmin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    $repo = Repository::factory()->create();
    $u = User::factory()->create([
        'email' => 'dhs-sa+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repo->id,
    ]);
    $u->assignRole('super_admin');
    $u->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);

    return $u;
}

function dhs_makeSeries(): Series
{
    return Series::firstOrCreate(
        ['code' => 'DHS_' . substr(uniqid(), -4)],
        ['title' => 'DHS series', 'is_active' => true],
    );
}

function dhs_makeDoc(int $repoId, int $seriesId, array $attrs = []): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'identifier' => 'DHS-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'TEST',
        'series_id' => $seriesId,
        'repository_id' => $repoId,
    ], $attrs));
}

it('migration adds previous_volume and new_volume to identifier history', function () {
    expect(Schema::hasColumn('document_identifier_history', 'previous_volume'))->toBeTrue()
        ->and(Schema::hasColumn('document_identifier_history', 'new_volume'))->toBeTrue();
});

it('finds a document by a PAST identifier even though its current identifier differs', function () {
    $user = dhs_actAsSuperAdmin();
    $this->actingAs($user);

    $repo = Repository::find($user->default_repository_id);
    $series = dhs_makeSeries();

    $hit = dhs_makeDoc($repo->id, $series->id, ['identifier' => 'CURRENT-IDENT-A']);
    $miss = dhs_makeDoc($repo->id, $series->id, ['identifier' => 'UNRELATED-B']);

    DocumentIdentifierHistory::create([
        'document_id' => $hit->id,
        'previous_identifier' => 'OLD-IDENT-Z',
        'new_identifier' => 'CURRENT-IDENT-A',
        'repository_id' => $repo->id,
        'changed_at' => now(),
    ]);

    Livewire::test(ListDocuments::class)
        ->set('tableSearch', 'OLD-IDENT-Z')
        ->assertCanSeeTableRecords([$hit])
        ->assertCanNotSeeTableRecords([$miss]);
});

it('finds a document by a PAST volume number even though its current volume differs', function () {
    $user = dhs_actAsSuperAdmin();
    $this->actingAs($user);

    $repo = Repository::find($user->default_repository_id);
    $series = dhs_makeSeries();

    $hit = dhs_makeDoc($repo->id, $series->id, [
        'identifier' => 'VOL-DOC-A',
        'volume_label' => 'VOL-CURRENT-99',
    ]);
    $miss = dhs_makeDoc($repo->id, $series->id, ['identifier' => 'VOL-DOC-B']);

    DocumentIdentifierHistory::create([
        'document_id' => $hit->id,
        'previous_identifier' => 'VOL-DOC-A',
        'previous_volume' => 'VOL-OLD-7',
        'repository_id' => $repo->id,
        'changed_at' => now(),
    ]);

    Livewire::test(ListDocuments::class)
        ->set('tableSearch', 'VOL-OLD-7')
        ->assertCanSeeTableRecords([$hit])
        ->assertCanNotSeeTableRecords([$miss]);
});

it('still finds a document by its CURRENT identifier', function () {
    $user = dhs_actAsSuperAdmin();
    $this->actingAs($user);

    $repo = Repository::find($user->default_repository_id);
    $series = dhs_makeSeries();

    $hit = dhs_makeDoc($repo->id, $series->id, ['identifier' => 'STILL-CURRENT-X']);
    $miss = dhs_makeDoc($repo->id, $series->id, ['identifier' => 'STILL-OTHER-Y']);

    Livewire::test(ListDocuments::class)
        ->set('tableSearch', 'STILL-CURRENT-X')
        ->assertCanSeeTableRecords([$hit])
        ->assertCanNotSeeTableRecords([$miss]);
});

it('records a history row automatically when the identifier changes', function () {
    $user = dhs_actAsSuperAdmin();
    $this->actingAs($user);

    $repo = Repository::find($user->default_repository_id);
    $series = dhs_makeSeries();

    $doc = dhs_makeDoc($repo->id, $series->id, ['identifier' => 'IDENT-BEFORE']);
    expect($doc->identifierHistory()->count())->toBe(0);

    $doc->update(['identifier' => 'IDENT-AFTER']);

    $history = $doc->identifierHistory()->get();
    expect($history)->toHaveCount(1)
        ->and($history->first()->previous_identifier)->toBe('IDENT-BEFORE')
        ->and($history->first()->new_identifier)->toBe('IDENT-AFTER');
});

it('adds an editable history row (incl. past volume) through the document edit form', function () {
    $user = dhs_actAsSuperAdmin();
    $this->actingAs($user);

    $repo = Repository::find($user->default_repository_id);
    $series = dhs_makeSeries();

    // The edit form re-validates document_type against the active
    // DocumentType lookup, so seed a valid one and use it on the doc.
    DocumentType::firstOrCreate(['name' => 'TEST'], ['is_active' => true]);
    $doc = dhs_makeDoc($repo->id, $series->id, [
        'identifier' => 'EDIT-FORM-DOC',
        'document_type' => 'TEST',
    ]);

    Livewire::test(EditDocument::class, ['record' => $doc->getRouteKey()])
        ->fillForm([
            'identifierHistory' => [
                [
                    'previous_identifier' => 'MANUAL-OLD-ID',
                    'new_identifier' => 'EDIT-FORM-DOC',
                    'previous_volume' => 'MANUAL-OLD-VOL',
                    'new_volume' => null,
                    'changed_at' => now(),
                    'reason' => 'Back-fill prior identification',
                ],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $row = DocumentIdentifierHistory::where('document_id', $doc->id)
        ->where('previous_identifier', 'MANUAL-OLD-ID')
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->previous_volume)->toBe('MANUAL-OLD-VOL');

    // And it is searchable by the manually-entered past volume.
    Livewire::test(ListDocuments::class)
        ->set('tableSearch', 'MANUAL-OLD-VOL')
        ->assertCanSeeTableRecords([$doc]);
});
