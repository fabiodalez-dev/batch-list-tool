<?php

use App\Listeners\RecordBackupRun;
use App\Models\BackupRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\CleanupWasSuccessful;

// TestCase is bound to tests/Feature via tests/Pest.php, but RefreshDatabase is
// not (only the Browser suite binds it globally), so opt in here per the task.
uses(RefreshDatabase::class);

it('records a successful backup run when BackupWasSuccessful fires', function () {
    // The success event only carries the disk + backup names. We dispatch the
    // real spatie event; the listener derives the size from the destination
    // disk when readable, and null otherwise (no real disk here -> null size).
    event(new BackupWasSuccessful('local', 'Test Backup'));

    $run = BackupRun::query()->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run->type)->toBe('backup')
        ->and($run->status)->toBe('success')
        ->and($run->destination_disk)->toBe('local')
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull();
});

it('records a failed backup run capturing the exception message', function () {
    $message = 'Disk quota exceeded while writing archive';

    event(new BackupHasFailed(new Exception($message), 'local', 'Test Backup'));

    $run = BackupRun::query()->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run->type)->toBe('backup')
        ->and($run->status)->toBe('failed')
        ->and($run->destination_disk)->toBe('local')
        ->and($run->message)->toBe($message);
});

it('records a cleanup run with type=cleanup on success', function () {
    event(new CleanupWasSuccessful('local', 'Test Backup'));

    $run = BackupRun::query()->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run->type)->toBe('cleanup')
        ->and($run->status)->toBe('success');
});

it('records a failed cleanup run capturing the exception message', function () {
    $message = 'Could not list old backups';

    event(new CleanupHasFailed(new Exception($message), 'local', 'Test Backup'));

    $run = BackupRun::query()->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run->type)->toBe('cleanup')
        ->and($run->status)->toBe('failed')
        ->and($run->message)->toBe($message);
});

it('falls back to a generic message when the failure event has no exception detail', function () {
    // Defensive path: an exception with an empty message must still produce a
    // non-empty, stored message (the throwable class name).
    $listener = new RecordBackupRun;
    $listener->handleBackupHasFailed(new BackupHasFailed(new RuntimeException(''), 'local', 'Test Backup'));

    $run = BackupRun::query()->latest('id')->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe('failed')
        ->and($run->message)->toBe(RuntimeException::class);
});
