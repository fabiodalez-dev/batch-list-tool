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
 * and asserts the validation surface.
 *
 * The two identifiers swapped roles on 2026-09-25, when the client wrote that
 * "the Citing Reference Code is the primary key" and "the NAM Authority
 * Reference Code is optional" — her file carries the Citing code on all 676
 * creators and the NAM code on 80. So: Citing required + unique; NAM optional
 * + unique where given; no format rule on either, because the two that existed
 * ("starts with R or I", "starts with MS") each described the other column.
 *
 * Unchanged: given name required; practice end >= start; entity_type
 * Notary/Interventor vocabulary that preserves a pre-existing legacy value.
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
        'alternative_identifier' => 'R' . random_int(10000, 99999),
        'surname' => 'Borg',
        'given_names' => 'Joseph',
        'entity_type' => 'Notary',
    ], $overrides);
}

it('accepts a Citing Reference Code of any shape', function () {
    $this->actingAs(cv_actAsSuperAdmin());

    // Until 2026-09-25 this asserted the opposite: a code not starting with R
    // or I was rejected. That rule sat on the NAM column, whose values read
    // "MT AF-P000058", and it is gone — a shape pinned in code is what sends
    // the next unusual code back to us for a release.
    Livewire::test(CreateAuthority::class)
        ->fillForm(cv_validForm(['alternative_identifier' => 'X-900']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Authority::where('alternative_identifier', 'X-900')->exists())->toBeTrue();
});

it('requires the Citing Reference Code', function () {
    $this->actingAs(cv_actAsSuperAdmin());

    Livewire::test(CreateAuthority::class)
        ->fillForm(cv_validForm(['alternative_identifier' => null]))
        ->call('create')
        ->assertHasFormErrors(['alternative_identifier']);
});

it('does not require the NAM Authority Reference Code', function () {
    $this->actingAs(cv_actAsSuperAdmin());

    // 596 of the client's 676 creators have no NAM code. Requiring it is the
    // defect she reported, in the form as much as in the importer.
    Livewire::test(CreateAuthority::class)
        ->fillForm(cv_validForm(['identifier' => null]))
        ->call('create')
        ->assertHasNoFormErrors();
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

it('rejects a duplicate Citing Reference Code with a validation error', function () {
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

it('accepts a NAM Authority Reference Code in the shape the archive uses', function () {
    $this->actingAs(cv_actAsSuperAdmin());

    // "MS123" used to be the only accepted shape here. The real values look
    // like this instead, which the old rule would have rejected outright.
    Livewire::test(CreateAuthority::class)
        ->fillForm(cv_validForm(['identifier' => 'MT AF-P000058']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Authority::where('identifier', 'MT AF-P000058')->exists())->toBeTrue();
});

it('allows an empty NAM Authority Reference Code (optional)', function () {
    $this->actingAs(cv_actAsSuperAdmin());

    Livewire::test(CreateAuthority::class)
        ->fillForm(cv_validForm(['identifier' => null]))
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
        'alternative_identifier' => 'R906', 'surname' => 'Legacy', 'given_names' => 'Old',
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
