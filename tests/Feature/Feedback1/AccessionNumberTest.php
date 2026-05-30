<?php

declare(strict_types=1);

use App\Filament\Resources\AccessionResource\Pages\CreateAccession;
use App\Filament\Resources\AccessionResource\Pages\EditAccession;
use App\Filament\Resources\AccessionResource\Pages\ListAccessions;
use App\Models\Accession;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Feedback1 Wave C1.3 — Notary Accession Number (YYYY-NNN) on Accession.
 * Drives the real Filament Create/Edit/List pages.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function acn_actAsSuperAdmin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    $u = User::factory()->create([
        'email' => 'acn-sa+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function acn_makeRepo(): Repository
{
    return Repository::factory()->create([
        'code' => 'ACN_' . substr(uniqid(), -6),
    ]);
}

it('saves a valid Notary Accession Number through the create form', function () {
    $this->actingAs(acn_actAsSuperAdmin());
    $repo = acn_makeRepo();

    Livewire::test(CreateAccession::class)
        ->fillForm([
            'code' => 'ACC-' . substr(uniqid(), -5),
            'repository_id' => $repo->id,
            'accession_number' => '2025-124',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Accession::where('accession_number', '2025-124')->exists())->toBeTrue();
});

it('rejects a malformed accession number', function () {
    $this->actingAs(acn_actAsSuperAdmin());
    $repo = acn_makeRepo();

    Livewire::test(CreateAccession::class)
        ->fillForm([
            'code' => 'ACC-' . substr(uniqid(), -5),
            'repository_id' => $repo->id,
            'accession_number' => '25/124',
        ])
        ->call('create')
        ->assertHasFormErrors(['accession_number']);

    expect(Accession::where('accession_number', '25/124')->exists())->toBeFalse();
});

it('accepts an accession without a number (optional)', function () {
    $this->actingAs(acn_actAsSuperAdmin());
    $repo = acn_makeRepo();
    $code = 'ACC-' . substr(uniqid(), -5);

    Livewire::test(CreateAccession::class)
        ->fillForm([
            'code' => $code,
            'repository_id' => $repo->id,
            'accession_number' => null,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Accession::where('code', $code)->first()?->accession_number)->toBeNull();
});

it('loads and re-saves an existing accession that has no number', function () {
    $this->actingAs(acn_actAsSuperAdmin());
    $repo = acn_makeRepo();

    // Legacy row created before the column existed (no number).
    $accession = Accession::create([
        'code' => 'LEGACY-' . substr(uniqid(), -5),
        'repository_id' => $repo->id,
    ]);

    Livewire::test(EditAccession::class, ['record' => $accession->getRouteKey()])
        ->assertOk()
        ->fillForm(['accession_number' => '2024-001'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($accession->refresh()->accession_number)->toBe('2024-001');
});

it('exposes the accession_number column and it is searchable', function () {
    $this->actingAs(acn_actAsSuperAdmin());
    $repo = acn_makeRepo();

    $match = Accession::create([
        'code' => 'M-' . substr(uniqid(), -5),
        'repository_id' => $repo->id,
        'accession_number' => '2025-777',
    ]);
    $other = Accession::create([
        'code' => 'O-' . substr(uniqid(), -5),
        'repository_id' => $repo->id,
        'accession_number' => '2025-888',
    ]);

    Livewire::test(ListAccessions::class)
        ->assertTableColumnExists('accession_number')
        ->searchTable('2025-777')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other]);
});
