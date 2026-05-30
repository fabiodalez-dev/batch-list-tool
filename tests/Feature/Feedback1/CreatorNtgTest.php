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
 * Feedback1 Wave C1.2 — Creator NTG (Notary to Government) date + "worked as
 * NTG" filter. Drives the real Filament pages.
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

it('saves the ntg_date through the create form', function () {
    $this->actingAs(ntg_actAsSuperAdmin());

    Livewire::test(CreateAuthority::class)
        ->fillForm(ntg_validForm(['identifier' => 'R31001', 'ntg_date' => '2010-05-20']))
        ->call('create')
        ->assertHasNoFormErrors();

    $authority = Authority::where('identifier', 'R31001')->first();
    expect($authority)->not->toBeNull()
        ->and($authority->ntg_date)->not->toBeNull()
        ->and($authority->ntg_date->toDateString())->toBe('2010-05-20');
});

it('allows a creator with no ntg_date (optional)', function () {
    $this->actingAs(ntg_actAsSuperAdmin());

    Livewire::test(CreateAuthority::class)
        ->fillForm(ntg_validForm(['identifier' => 'R31002', 'ntg_date' => null]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Authority::where('identifier', 'R31002')->first()?->ntg_date)->toBeNull();
});

it('filters creators that worked as NTG (true / false)', function () {
    $this->actingAs(ntg_actAsSuperAdmin());

    $withNtg = Authority::create(ntg_validForm(['identifier' => 'R32001', 'ntg_date' => '2005-01-01']));
    $withoutNtg = Authority::create(ntg_validForm(['identifier' => 'R32002', 'ntg_date' => null]));

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

it('exposes an ntg_date column on the list table', function () {
    $this->actingAs(ntg_actAsSuperAdmin());

    $a = Authority::create(ntg_validForm(['identifier' => 'R33001', 'ntg_date' => '2012-12-12']));

    Livewire::test(ListAuthorities::class)
        ->assertTableColumnExists('ntg_date')
        ->assertCanSeeTableRecords([$a]);
});
