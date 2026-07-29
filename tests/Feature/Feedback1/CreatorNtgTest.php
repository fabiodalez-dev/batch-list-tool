<?php

declare(strict_types=1);

use App\Filament\Resources\AuthorityResource\Pages\CreateAuthority;
use App\Filament\Resources\AuthorityResource\Pages\ListAuthorities;
use App\Models\Authority;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Creator NTG (Notari tal-Gvern / Notary to Government) dates + "worked as NTG"
 * filter. Drives the real Filament pages.
 *
 * NTG is a YEAR RANGE (ntg_dates_start / ntg_dates_end), mirroring the private
 * practice dates — the client's "NTG Dates Active" column carries ranges like
 * "1882-1893". The earlier single-date `ntg_date` column could not hold a range
 * (so NTG dates never imported); it was replaced by the two-year-column shape.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function ntg_actAsSuperAdmin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    $u = User::factory()->create([
        'email' => 'ntg-sa+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function ntg_validForm(array $overrides = []): array
{
    return array_merge([
        'identifier' => 'R' . random_int(10000, 99999),
        'surname' => 'Borg',
        'given_names' => 'Joseph',
        'entity_type' => 'Notary',
    ], $overrides);
}

it('saves the NTG year range through the create form', function () {
    $this->actingAs(ntg_actAsSuperAdmin());

    Livewire::test(CreateAuthority::class)
        ->fillForm(ntg_validForm([
            'identifier' => 'R31001',
            'ntg_dates_start' => '1882',
            'ntg_dates_end' => '1893',
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $authority = Authority::where('identifier', 'R31001')->first();
    expect($authority)->not->toBeNull()
        ->and($authority->ntg_dates_start)->toBe(1882)
        ->and($authority->ntg_dates_end)->toBe(1893);
});

it('allows a creator with no NTG dates (optional)', function () {
    $this->actingAs(ntg_actAsSuperAdmin());

    Livewire::test(CreateAuthority::class)
        ->fillForm(ntg_validForm([
            'identifier' => 'R31002',
            'ntg_dates_start' => null,
            'ntg_dates_end' => null,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    $a = Authority::where('identifier', 'R31002')->first();
    expect($a?->ntg_dates_start)->toBeNull()
        ->and($a?->ntg_dates_end)->toBeNull();
});

it('rejects an NTG end year earlier than the start year', function () {
    $this->actingAs(ntg_actAsSuperAdmin());

    Livewire::test(CreateAuthority::class)
        ->fillForm(ntg_validForm([
            'identifier' => 'R31003',
            'ntg_dates_start' => '1893',
            'ntg_dates_end' => '1882',
        ]))
        ->call('create')
        ->assertHasFormErrors(['ntg_dates_end']);
});

it('filters creators that worked as NTG (true / false)', function () {
    $this->actingAs(ntg_actAsSuperAdmin());

    $withNtg = Authority::create(ntg_validForm([
        'identifier' => 'R32001', 'ntg_dates_start' => 1882, 'ntg_dates_end' => 1893,
    ]));
    $withoutNtg = Authority::create(ntg_validForm([
        'identifier' => 'R32002', 'ntg_dates_start' => null, 'ntg_dates_end' => null,
    ]));

    // true → only the NTG creator
    Livewire::test(ListAuthorities::class)
        ->filterTable('worked_as_ntg', true)
        ->assertCanSeeTableRecords([$withNtg])
        ->assertCanNotSeeTableRecords([$withoutNtg]);

    // false → only the non-NTG creator
    Livewire::test(ListAuthorities::class)
        ->filterTable('worked_as_ntg', false)
        ->assertCanSeeTableRecords([$withoutNtg])
        ->assertCanNotSeeTableRecords([$withNtg]);
});

it('exposes an NTG dates column on the list table', function () {
    $this->actingAs(ntg_actAsSuperAdmin());

    $a = Authority::create(ntg_validForm([
        'identifier' => 'R33001', 'ntg_dates_start' => 1900, 'ntg_dates_end' => 1910,
    ]));

    Livewire::test(ListAuthorities::class)
        ->assertTableColumnExists('ntg_dates_display')
        ->assertCanSeeTableRecords([$a]);
});
