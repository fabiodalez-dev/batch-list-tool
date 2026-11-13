<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\TwoFactorEnrolment;
use App\Filament\Pages\TwoFactorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;

/*
 * Security audit 2026-11-13, lead 4: a signed-in session alone (an unlocked
 * workstation, a stolen cookie) must not be enough to switch off the second
 * factor or to read or replace the recovery codes. Fortify asks for the
 * password on these steps (confirmPassword => true); the two Filament pages
 * must ask too.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function sa_tfaConfirmedUser(): User
{
    /** @var User $user */
    $user = User::factory()->create(['password' => Hash::make('right-password'), 'is_active' => true]);
    $user->assignRole('editor');
    app(EnableTwoFactorAuthentication::class)($user);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    return $user->refresh();
}

it('does not show the recovery codes again on the enrolment page', function () {
    $user = sa_tfaConfirmedUser();

    $body = $this->actingAs($user)->get('/admin/two-factor-enrolment')->assertOk()->getContent();

    foreach ($user->recoveryCodes() as $code) {
        expect($body)->not->toContain($code);
    }
});

it('shows the recovery codes once, right after the code is confirmed', function () {
    /** @var User $user */
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole('editor');
    app(EnableTwoFactorAuthentication::class)($user);
    $user->refresh();
    $this->actingAs($user);

    $component = Livewire::test(TwoFactorEnrolment::class)
        ->set('data.code', (new Google2FA)->getCurrentOtp(decrypt($user->two_factor_secret)))
        ->call('confirm');

    foreach ($user->fresh()->recoveryCodes() as $code) {
        $component->assertSee($code);
    }
});

it('does not disable 2FA from the enrolment page without the password', function () {
    $user = sa_tfaConfirmedUser();
    $this->actingAs($user);

    Livewire::test(TwoFactorEnrolment::class)
        ->callAction('disable', data: ['password' => 'wrong-password']);

    expect($user->fresh()->two_factor_secret)->not->toBeNull()
        ->and($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

it('disables 2FA from the enrolment page with the right password', function () {
    $user = sa_tfaConfirmedUser();
    $this->actingAs($user);

    Livewire::test(TwoFactorEnrolment::class)
        ->callAction('disable', data: ['password' => 'right-password']);

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

it('does not regenerate the recovery codes without the password', function () {
    $user = sa_tfaConfirmedUser();
    $before = $user->recoveryCodes();
    $this->actingAs($user);

    Livewire::test(TwoFactorProfile::class)
        ->callAction('regenerateRecoveryCodes', data: ['password' => 'wrong-password'])
        ->assertSet('showRecoveryCodes', null);

    expect($user->fresh()->recoveryCodes())->toBe($before);
});

it('regenerates the recovery codes with the right password', function () {
    $user = sa_tfaConfirmedUser();
    $before = $user->recoveryCodes();
    $this->actingAs($user);

    Livewire::test(TwoFactorProfile::class)
        ->callAction('regenerateRecoveryCodes', data: ['password' => 'right-password']);

    expect($user->fresh()->recoveryCodes())->not->toBe($before);
});
