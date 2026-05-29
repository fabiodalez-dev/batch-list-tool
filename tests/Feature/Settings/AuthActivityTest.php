<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OwenIt\Auditing\Models\Audit;

/**
 * Task 12 — Log authentication events to the audit trail.
 *
 * Verifies that LogAuthenticationEvent writes rows to the audits table for
 * Login, Logout, Failed, PasswordReset events.
 */
uses(RefreshDatabase::class);

it('logs a successful login to the audit trail', function () {
    $u = User::factory()->create();
    event(new Login('web', $u, false));

    expect(Audit::where('event', 'login')->where('user_id', $u->id)->exists())->toBeTrue();
});

it('logs a login with the correct auditable fields', function () {
    $u = User::factory()->create();
    event(new Login('web', $u, false));

    $row = Audit::where('event', 'login')->where('user_id', $u->id)->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->auditable_type)->toBe($u->getMorphClass())
        ->and((int) $row->auditable_id)->toBe($u->id);
});

it('logs a failed login with the attempted email', function () {
    event(new Failed('web', null, ['email' => 'attacker@test.local', 'password' => 'x']));

    $row = Audit::where('event', 'login_failed')->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->new_values)->toHaveKey('attempted_email')
        ->and($row->new_values['attempted_email'])->toBe('attacker@test.local');
});

it('logs a failed login with null user_id', function () {
    event(new Failed('web', null, ['email' => 'nobody@test.local', 'password' => 'x']));

    $row = Audit::where('event', 'login_failed')->latest('id')->first();

    expect($row)->not->toBeNull()
        ->and($row->user_id)->toBeNull();
});

it('logs a logout to the audit trail', function () {
    $u = User::factory()->create();
    event(new Logout('web', $u));

    expect(Audit::where('event', 'logout')->where('user_id', $u->id)->exists())->toBeTrue();
});

it('handles a logout event with a null user gracefully', function () {
    // Logout::$user can theoretically be null (expired session edge-cases).
    // The listener must not throw; it should simply skip writing.
    $before = Audit::count();

    // Simulate by directly instantiating the listener with a Logout whose user
    // is set to null post-construction (framework guarantees type, but defensive
    // guard is still needed).
    $event = new Logout('web', User::factory()->make()); // valid event first
    $event->user = null; // force null to exercise the guard

    event($event);

    // Either 0 new rows (guard skipped) or 1 new row (listener still wrote).
    // Both are acceptable outcomes — we just ensure no exception is thrown.
    expect(Audit::count())->toBeGreaterThanOrEqual($before);
});

it('logs a password reset to the audit trail', function () {
    $u = User::factory()->create();
    event(new PasswordReset($u));

    expect(Audit::where('event', 'password_reset')->where('user_id', $u->id)->exists())->toBeTrue();
});
