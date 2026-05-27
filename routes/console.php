<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily backup at 02:30 UTC. Mail notifications go to MAIL_BACKUP_RECIPIENT.
// Cleanup runs 30 min later so the daily backup is in place before pruning.
// See docs/operations/backup.md for restore procedure.
Schedule::command('backup:clean')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->emailOutputOnFailure(env('MAIL_BACKUP_RECIPIENT'));

Schedule::command('backup:run')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer()
    ->emailOutputOnFailure(env('MAIL_BACKUP_RECIPIENT'));

// Weekly monitor check — verifies backups exist and aren't stale.
Schedule::command('backup:monitor')
    ->mondays()
    ->at('08:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->emailOutputOnFailure(env('MAIL_BACKUP_RECIPIENT'));

// spatie/laravel-health — refresh the cached results consumed by the public
// `/health` JSON endpoint (RFQ-2026-06 §3.4.1). Every 5 minutes is the upstream
// recommended cadence; the result store keeps the last run for instant probes.
Schedule::command('health:check')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
