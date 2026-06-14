<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;

/**
 * RFQ-2026-06 §3.4.2 — daily automated backups.
 *
 * These tests pin the cron schedule for the spatie/laravel-backup
 * artisan commands. The schedule lives in routes/console.php and is
 * the only thing that guarantees `backup:run` fires unattended on
 * production. If anyone removes or retimes those entries the gap
 * re-opens silently; these assertions catch that in CI.
 *
 * See docs/operations/backup.md for the operational runbook.
 */
/**
 * Collect every Schedule\Event whose command string contains the
 * given Artisan command name. Laravel wraps the artisan invocation
 * in a quoted shell command, so a simple `str_contains` is the most
 * stable match (vs. equality, which depends on the PHP binary path).
 */
function backupScheduledEvents(string $command): Collection
{
    /** @var Schedule $schedule */
    $schedule = resolve(Schedule::class);

    return collect($schedule->events())
        ->filter(fn ($event) => is_string($event->command)
            && preg_match('/\bartisan\b[\'"]?\s+' . preg_quote($command, '/') . '\b/', $event->command) === 1);
}

it('schedules backup:run daily at 02:30', function () {
    $events = backupScheduledEvents('backup:run');

    expect($events)->toHaveCount(1);
    expect($events->first()->expression)->toBe('30 2 * * *');
});

it('schedules backup:clean daily at 03:00', function () {
    $events = backupScheduledEvents('backup:clean');

    expect($events)->toHaveCount(1);
    expect($events->first()->expression)->toBe('0 3 * * *');
});

it('schedules backup:monitor weekly on Monday at 08:00', function () {
    $events = backupScheduledEvents('backup:monitor');

    expect($events)->toHaveCount(1);
    expect($events->first()->expression)->toBe('0 8 * * 1');
});
