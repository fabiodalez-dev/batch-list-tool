<?php

declare(strict_types=1);

use App\Filament\Resources\AuthorityResource\Pages\CreateAuthority;
use App\Filament\Resources\AuthorityResource\Pages\EditAuthority;
use App\Models\Authority;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Feedback1 Wave A — Creator (Authority) form validations.
 *
 * Drives the real Filament CreateAuthority / EditAuthority Livewire pages
 * and asserts the validation surface: identifier required + must start with
 * R/I + unique; alternative_identifier optional but MS-prefixed + unique;
 * given name required; practice end >= start; entity_type Notary/Interventor
 * vocabulary that preserves a pre-existing legacy value on edit.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function cv_actAsSuperAdmin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    $u = User::factory()->create([
        'email' => 'cv-sa+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * Minimal valid form payload; individual tests override the field under test.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function cv_validForm(array $overrides = []): array
{
    return array_merge([
        'identifier' => 'R' . random_int(10000, 99999),
        'surname' => 'Borg',
        'given_names' => 'Joseph',
        'entity_type' => 'Notary',
    ], $overrides);
}

it('rejects an identifier that does not start with R or I (validation, not SQL)', function () {
    $this->actingAs(cv_actAsSuperAdmin());

    Livewire::test(CreateAuthority::class)
        ->fillForm(cv_validForm(['identifier' => 'X1']))
        ->call('create')
        ->assertHasFormErrors(['identifier']);

    expect(Authority::where('identifier', 'X1')->exists())->toBeFalse();
});

it('accepts an identifier starting with R', function () {
    $this->actingAs(cv_actAsSuperAdmin());

    Livewire::test(CreateAuthority::class)
        ->fillForm(cv_validForm(['identifier' => 'R5']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Authority::where('identifier', 'R5')->exists())->toBeTrue();
});

it('accepts an identifier starting with I', function () {
    $this->actingAs(cv_actAsSuperAdmin());

    Livewire::test(CreateAuthority::class)
        ->fillForm(cv_validForm(['identifier' => 'I3']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Authority::where('identifier', 'I3')->exists())->toBeTrue();
});

it('requires the identifier', function () {
    $this->actingAs(cv_actAsSuperAdmin());

    Livewire::test(CreateAuthority::class)
        ->fillForm(cv_validForm(['identifier' => null]))
        ->call('create')
        ->assertHasFormErrors(['identifier']);
});

it('rejects a duplicate identifier with a validation error (not a SQL exception)', function () {
    $this->actingAs(cv_actAsSuperAdmin());

    Authority::create(['identifier' => 'R777', 'surname' => 'Existing', 'given_names' => 'A', 'entity_type' => 'Notary']);

    Livewire::test(CreateAuthority::class)
        ->fillForm(cv_validForm(['identifier' => 'R777']))
        ->call('create')
        ->assertHasFormErrors(['identifier']);

    expect(Authority::where('identifier', 'R777')->count())->toBe(1);
});

it('rejects a duplicate alternative_identifier with a validation error', function () {
    $this->actingAs(cv_actAsSuperAdmin());

    Authority::create([
        'identifier' => 'R800', 'surname' => 'Has', 'given_names' => 'Alt',
        'entity_type' => 'Notary', 'alternative_identifier' => 'MS900',
    ]);

    Livewire::test(CreateAuthority::class)
        ->fillForm(cv_validForm(['alternative_identifier' => 'MS900']))
        ->call('create')
        ->assertHasFormErrors(['alternative_identifier']);
});

it('rejects an alternative_identifier that does not start with MS when filled', function () {
    $this->actingAs(cv_actAsSuperAdmin());

    Livewire::test(CreateAuthority::class)
        ->fillForm(cv_validForm(['alternative_identifier' => 'ZZ1']))
        ->call('create')
        ->assertHasFormErrors(['alternative_identifier']);
});

it('accepts an MS-prefixed alternative_identifier', function () {
    $this->actingAs(cv_actAsSuperAdmin());

    Livewire::test(CreateAuthority::class)
        ->fillForm(cv_validForm(['identifier' => 'R901', 'alternative_identifier' => 'MS123']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Authority::where('alternative_identifier', 'MS123')->exists())->toBeTrue();
});

it('allows an empty alternative_identifier (optional)', function () {
    $this->actingAs(cv_actAsSuperAdmin());

    Livewire::test(CreateAuthority::class)
        ->fillForm(cv_validForm(['identifier' => 'R902', 'alternative_identifier' => null]))
        ->call('create')
        ->assertHasNoFormErrors();
});

it('requires the given name', function () {
    $this->actingAs(cv_actAsSuperAdmin());

    Livewire::test(CreateAuthority::class)
        ->fillForm(cv_validForm(['given_names' => null]))
        ->call('create')
        ->assertHasFormErrors(['given_names']);
});

it('rejects practice end year earlier than start year', function () {
    $this->actingAs(cv_actAsSuperAdmin());

    Livewire::test(CreateAuthority::class)
        ->fillForm(cv_validForm([
            'identifier' => 'R903',
            'practice_dates_start' => 1700,
            'practice_dates_end' => 1650,
        ]))
        ->call('create')
        ->assertHasFormErrors(['practice_dates_end']);
});

it('accepts practice end year greater than or equal to start year', function () {
    $this->actingAs(cv_actAsSuperAdmin());

    Livewire::test(CreateAuthority::class)
        ->fillForm(cv_validForm([
            'identifier' => 'R904',
            'practice_dates_start' => 1650,
            'practice_dates_end' => 1700,
        ]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Authority::where('identifier', 'R904')->exists())->toBeTrue();
});

it('accepts the Notary and Interventor entity_type options', function () {
    $this->actingAs(cv_actAsSuperAdmin());

    Livewire::test(CreateAuthority::class)
        ->fillForm(cv_validForm(['identifier' => 'R905', 'entity_type' => 'Interventor']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Authority::where('identifier', 'R905')->first()?->entity_type)->toBe('Interventor');
});

it('preserves a pre-existing legacy entity_type value on edit', function () {
    $this->actingAs(cv_actAsSuperAdmin());

    // Legacy row stored before the Notary/Interventor vocabulary existed.
    $authority = Authority::create([
        'identifier' => 'R906', 'surname' => 'Legacy', 'given_names' => 'Old',
        'entity_type' => 'PERSON',
    ]);

    Livewire::test(EditAuthority::class, ['record' => $authority->getRouteKey()])
        ->assertOk()
        // The legacy value is merged into the options so it stays selected
        // and saveable without forcing the operator to change it.
        ->assertFormSet(['entity_type' => 'PERSON'])
        ->fillForm(['surname' => 'LegacyEdited'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($authority->refresh()->entity_type)->toBe('PERSON')
        ->and($authority->surname)->toBe('LegacyEdited');
});
