<?php

declare(strict_types=1);

use App\Listeners\LogAuthenticationEvent;
use App\Listeners\RecordBackupRun;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use OwenIt\Auditing\Models\Audit;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\CleanupWasSuccessful;

/**
 * Seen on production, 2026-10-01: every sign-in left two identical "login"
 * rows in the audit trail, in the same second. The auth and backup listeners
 * were registered by hand in AppServiceProvider AND by listener discovery, so
 * each ran twice — the backup history got two rows per run as well.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => config(['audit.enabled' => true]));

it('writes one audit row per sign-in', function (): void {
    $user = User::factory()->create(['is_active' => true]);

    event(new Login('web', $user, false));

    expect(Audit::query()->where('event', 'login')->where('user_id', $user->id)->count())->toBe(1);
});

it('writes one audit row per failed sign-in', function (): void {
    event(new Failed('web', null, ['email' => 'someone@example.test']));

    expect(Audit::query()->where('event', 'login_failed')->count())->toBe(1);
});

it('has exactly one audit listener on each auth event, and one history listener on each backup event', function (string $event, string $listener): void {
    $mine = array_filter(
        Event::getRawListeners()[$event] ?? [],
        static fn (mixed $l): bool => is_string($l) ? str_starts_with($l, $listener) : (is_array($l) && ($l[0] ?? null) === $listener),
    );

    expect($mine)->toHaveCount(1);
})->with([
    'Login' => [Login::class, LogAuthenticationEvent::class],
    'Logout' => [Logout::class, LogAuthenticationEvent::class],
    'Failed' => [Failed::class, LogAuthenticationEvent::class],
    'Lockout' => [Lockout::class, LogAuthenticationEvent::class],
    'PasswordReset' => [PasswordReset::class, LogAuthenticationEvent::class],
    'BackupWasSuccessful' => [BackupWasSuccessful::class, RecordBackupRun::class],
    'BackupHasFailed' => [BackupHasFailed::class, RecordBackupRun::class],
    'CleanupWasSuccessful' => [CleanupWasSuccessful::class, RecordBackupRun::class],
    'CleanupHasFailed' => [CleanupHasFailed::class, RecordBackupRun::class],
]);
