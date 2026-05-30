<?php

declare(strict_types=1);

use App\Filament\Resources\AccessionResource;
use App\Filament\Resources\AuthorityResource\Pages\ListAuthorities;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\SeriesResource\Pages\CreateSeries as CreateSeriesPage;
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
 * Feedback1 Wave A — dashboard / table quick wins:
 *  - Authority list default-sorts by identifier.
 *  - Authority & Document tables expose View + Edit actions.
 *  - Series code uniqueness is enforced (validation).
 *  - Accession resource is relabelled "Notary Accession".
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function qw_actAsSuperAdmin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    $u = User::factory()->create([
        'email' => 'qw-sa+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => null,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function qw_authority(string $identifier): Authority
{
    return Authority::create([
        'identifier' => $identifier,
        'surname' => 'Surname' . substr(uniqid(), -4),
        'given_names' => 'Given',
        'entity_type' => 'Notary',
    ]);
}

it('default-sorts the Authority list by identifier', function () {
    $this->actingAs(qw_actAsSuperAdmin());

    $r3 = qw_authority('R30');
    $r1 = qw_authority('R10');
    $r2 = qw_authority('R20');

    Livewire::test(ListAuthorities::class)
        ->assertOk()
        // inOrder asserts the rows render in identifier-ascending order,
        // proving the resource's defaultSort('identifier').
        ->assertCanSeeTableRecords([$r1, $r2, $r3], inOrder: true);
});

it('exposes View and Edit actions on the Authority table', function () {
    $this->actingAs(qw_actAsSuperAdmin());

    $authority = qw_authority('R40');

    Livewire::test(ListAuthorities::class)
        ->assertOk()
        ->assertTableActionExists('view')
        ->assertTableActionExists('edit')
        ->assertTableActionVisible('view', $authority)
        ->assertTableActionVisible('edit', $authority);
});

it('exposes View and Edit actions on the Document table', function () {
    $this->actingAs(qw_actAsSuperAdmin());

    $repo = Repository::factory()->create(['code' => 'QW_' . strtoupper(substr(uniqid(), -6))]);
    $series = Series::firstOrCreate(
        ['code' => 'QWS_' . substr(uniqid(), -4)],
        ['title' => 'QW series', 'is_active' => true],
    );
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'QW-DOC-' . strtoupper(substr(uniqid(), -8)),
        'document_type' => 'TEST',
        'series_id' => $series->id,
        'repository_id' => $repo->id,
    ]);

    Livewire::test(ListDocuments::class)
        ->assertOk()
        ->assertTableActionExists('view')
        ->assertTableActionExists('edit')
        ->assertTableActionVisible('view', $doc)
        ->assertTableActionVisible('edit', $doc);
});

it('rejects a duplicate Series code with a validation error', function () {
    $this->actingAs(qw_actAsSuperAdmin());

    Series::create(['code' => 'QWDUP', 'title' => 'First', 'is_active' => true]);

    Livewire::test(CreateSeriesPage::class)
        ->fillForm([
            'code' => 'QWDUP',
            'title' => 'Second',
            'is_wills_series' => false,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasFormErrors(['code']);

    expect(Series::where('code', 'QWDUP')->count())->toBe(1);
});

it('relabels the Accession resource as "Notary Accession"', function () {
    expect(AccessionResource::getModelLabel())->toBe('Notary Accession')
        ->and(AccessionResource::getPluralModelLabel())->toBe('Notary Accessions')
        ->and(AccessionResource::getNavigationLabel())->toBe('Notary Accessions');
});
