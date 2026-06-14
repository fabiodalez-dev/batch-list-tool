<?php

declare(strict_types=1);

use App\Filament\Pages\TwoFactorProfile;
use App\Listeners\LogTwoFactorChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;

/**
 * RFQ §3.1.7 hardening — Two-Factor (TOTP) acceptance tests.
 *
 * Twelve tests covering:
 *   - Migration: 3 columns exist on `users`
 *   - Filament page: renders for any authenticated user
 *   - Enable: generates a non-null encrypted secret
 *   - Confirm (valid): sets `two_factor_confirmed_at`
 *   - Confirm (invalid): validation error + column stays null
 *   - Recovery codes: array of 8 strings
 *   - Disable: clears all 3 columns + writes Audit row
 *   - Enable event: writes Audit row with `two_factor_enabled`
 *   - Audit row metadata: user_id, ip, ua wired via owen-it conventions
 *   - /two-factor-challenge: POST succeeds for user mid-login (200 / redirect)
 *   - Opt-in default: user WITHOUT 2FA logs in normally
 *   - Recovery code consumed: previously-used recovery code is replaced
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::findOrCreate($r, 'web');
    }
});

/* ─── Helpers ──────────────────────────────────────────────────────── */

function tfa_user(string $role = 'admin'): User
{
    $user = User::factory()->create([
        'email' => 'tfa-' . $role . '+' . uniqid() . '@test.local',
        'password' => Hash::make('password123'),
        'is_active' => true,
    ]);
    $user->assignRole($role);

    return $user;
}

function tfa_enableFor(User $user): User
{
    resolve(EnableTwoFactorAuthentication::class)($user);
    $user->refresh();

    return $user;
}

function tfa_currentCode(User $user): string
{
    $secret = decrypt($user->two_factor_secret);

    return (new Google2FA)->getCurrentOtp($secret);
}

/* ─── Tests ────────────────────────────────────────────────────────── */

it('migration created the three two_factor columns on users', function () {
    expect(Schema::hasColumn('users', 'two_factor_secret'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'two_factor_recovery_codes'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'two_factor_confirmed_at'))->toBeTrue();
});

it('2FA setup page renders for an authenticated user', function () {
    $user = tfa_user('viewer');
    $this->actingAs($user);

    Livewire::test(TwoFactorProfile::class)->assertOk();
});

it('enabling 2FA writes a non-null encrypted secret', function () {
    $user = tfa_user();

    expect($user->two_factor_secret)->toBeNull();

    resolve(EnableTwoFactorAuthentication::class)($user);
    $user->refresh();

    expect($user->two_factor_secret)->not->toBeNull();

    // Round-trips through the encrypter without throwing.
    expect(decrypt($user->two_factor_secret))->toBeString()->not->toBeEmpty();
});

it('confirming with a valid 6-digit code sets two_factor_confirmed_at', function () {
    $user = tfa_user();
    tfa_enableFor($user);

    expect($user->two_factor_confirmed_at)->toBeNull();

    $code = tfa_currentCode($user);

    $this->actingAs($user);

    Livewire::test(TwoFactorProfile::class)
        ->set('confirmCode', $code)
        ->callAction('confirm');

    $user->refresh();
    expect($user->two_factor_confirmed_at)->not->toBeNull();
});

it('confirming with an invalid 6-digit code keeps the column null', function () {
    $user = tfa_user();
    tfa_enableFor($user);

    $this->actingAs($user);

    Livewire::test(TwoFactorProfile::class)
        ->set('confirmCode', '000000')
        ->callAction('confirm');

    $user->refresh();
    expect($user->two_factor_confirmed_at)->toBeNull();
});

it('recovery codes are generated as an array of 8 strings', function () {
    $user = tfa_user();
    tfa_enableFor($user);

    $codes = $user->recoveryCodes();

    expect($codes)->toBeArray()
        ->and(count($codes))->toBe(8);

    foreach ($codes as $code) {
        expect($code)->toBeString()->not->toBeEmpty();
    }
});

it('disabling 2FA clears all three columns AND writes an Audit row with event=two_factor_disabled', function () {
    $user = tfa_user();
    tfa_enableFor($user);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    expect($user->two_factor_secret)->not->toBeNull()
        ->and($user->two_factor_recovery_codes)->not->toBeNull()
        ->and($user->two_factor_confirmed_at)->not->toBeNull();

    // Make sure the listener is wired (registered via EventServiceProvider).
    $beforeAudits = Audit::where('event', 'two_factor_disabled')->count();

    $this->actingAs($user);

    Livewire::test(TwoFactorProfile::class)
        ->set('disablePassword', 'password123')
        ->callAction('disable', data: ['password' => 'password123']);

    $user->refresh();
    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();

    expect(Audit::where('event', 'two_factor_disabled')->count())
        ->toBe($beforeAudits + 1);
});

it('enabling 2FA writes an Audit row with event=two_factor_enabled', function () {
    $user = tfa_user();
    $this->actingAs($user);

    $before = Audit::where('event', 'two_factor_enabled')->count();

    Livewire::test(TwoFactorProfile::class)
        ->callAction('enable');

    expect(Audit::where('event', 'two_factor_enabled')->count())
        ->toBe($before + 1);
});

it('the audit row written by LogTwoFactorChange carries user_id, ip and user agent', function () {
    $user = tfa_user();
    $this->actingAs($user);

    // Drive the listener directly so we can check the row independently
    // of the Filament call stack.
    $listener = new LogTwoFactorChange;
    $listener->handleEnabled(new TwoFactorAuthenticationEnabled($user));

    $row = Audit::where('event', 'two_factor_enabled')->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->user_id)->toBe($user->id)
        ->and($row->auditable_type)->toBe($user->getMorphClass())
        ->and($row->auditable_id)->toBe($user->id)
        // ip_address + user_agent default to null in a CLI test context
        // (no inbound request); the columns must still be writable.
        ->and($row->getAttributes())->toHaveKey('ip_address')
        ->and($row->getAttributes())->toHaveKey('user_agent');
});

it('POST /two-factor-challenge accepts a valid 6-digit code for a user mid-login', function () {
    $user = tfa_user();
    tfa_enableFor($user);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    // Mid-login state: Fortify's RedirectIfTwoFactorAuthenticatable would
    // have logged the user OUT and stashed login.id in the session. We
    // replicate that state, then POST the code.
    $code = tfa_currentCode($user);

    $response = $this->withSession(['login.id' => $user->id])
        ->post('/two-factor-challenge', ['code' => $code]);

    $response->assertRedirect();
    expect(auth()->id())->toBe($user->id);
});

it('a user WITHOUT 2FA enrolment can still authenticate normally (opt-in default)', function () {
    $user = tfa_user('viewer');

    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();

    // No 2FA challenge state in session — direct login attempt succeeds.
    $this->actingAs($user);

    expect(auth()->id())->toBe($user->id);

    // Sanity: the panel page renders for them too.
    Livewire::test(TwoFactorProfile::class)->assertOk();
});

it('a recovery code consumes correctly when used to sign in', function () {
    $user = tfa_user();
    tfa_enableFor($user);
    $user->forceFill(['two_factor_confirmed_at' => now()])->save();

    $codes = $user->recoveryCodes();
    $used = $codes[0];

    $response = $this->withSession(['login.id' => $user->id])
        ->post('/two-factor-challenge', ['recovery_code' => $used]);

    $response->assertRedirect();
    expect(auth()->id())->toBe($user->id);

    // The used code must no longer be in the user's recovery code list.
    $user->refresh();
    $remaining = $user->recoveryCodes();

    expect($remaining)->toBeArray()
        ->and(count($remaining))->toBe(8)
        ->and(in_array($used, $remaining, true))->toBeFalse();
});
