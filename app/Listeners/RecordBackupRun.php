<?php

namespace App\Listeners;

use App\Models\BackupRun;
use Illuminate\Support\Facades\Log;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\CleanupWasSuccessful;

/**
 * Records spatie/laravel-backup lifecycle events into the backup_runs table so
 * the Backup Center can show a run history.
 *
 * Spatie's events are intentionally thin — the success/cleanup events only carry
 * the disk name and backup name (no size, no timestamps), and the failure events
 * carry the thrown Exception plus optional disk/backup names. We therefore:
 *   - reconstruct a BackupDestination from the disk + backup name to read the
 *     newest backup's size, when available;
 *   - capture the exception message verbatim on failure.
 *
 * Every handler is wrapped defensively: a missing field or an unreadable disk
 * must never let a listener crash the backup run that triggered it. Registered
 * in AppServiceProvider via Event::listen() calls, mirroring LogAuthenticationEvent.
 */
class RecordBackupRun
{
    public function handleBackupWasSuccessful(BackupWasSuccessful $event): void
    {
        try {
            $sizeBytes = $this->newestBackupSize($event->diskName, $event->backupName);

            BackupRun::create([
                'type' => 'backup',
                'destination_disk' => $event->diskName,
                'status' => 'success',
                'started_at' => now(),
                'finished_at' => now(),
                'size_bytes' => $sizeBytes,
                'message' => "Backup '{$event->backupName}' completed on disk '{$event->diskName}'.",
            ]);
        } catch (\Throwable $e) {
            Log::warning('RecordBackupRun::handleBackupWasSuccessful failed', ['error' => $e->getMessage()]);
        }
    }

    public function handleBackupHasFailed(BackupHasFailed $event): void
    {
        try {
            BackupRun::create([
                'type' => 'backup',
                'destination_disk' => $event->diskName,
                'status' => 'failed',
                'started_at' => now(),
                'finished_at' => now(),
                'message' => $this->throwableMessage($event->exception ?? null),
            ]);
        } catch (\Throwable $e) {
            Log::warning('RecordBackupRun::handleBackupHasFailed failed', ['error' => $e->getMessage()]);
        }
    }

    public function handleCleanupWasSuccessful(CleanupWasSuccessful $event): void
    {
        try {
            BackupRun::create([
                'type' => 'cleanup',
                'destination_disk' => $event->diskName,
                'status' => 'success',
                'started_at' => now(),
                'finished_at' => now(),
                'message' => "Cleanup of '{$event->backupName}' completed on disk '{$event->diskName}'.",
            ]);
        } catch (\Throwable $e) {
            Log::warning('RecordBackupRun::handleCleanupWasSuccessful failed', ['error' => $e->getMessage()]);
        }
    }

    public function handleCleanupHasFailed(CleanupHasFailed $event): void
    {
        try {
            BackupRun::create([
                'type' => 'cleanup',
                'destination_disk' => $event->diskName,
                'status' => 'failed',
                'started_at' => now(),
                'finished_at' => now(),
                'message' => $this->throwableMessage($event->exception ?? null),
            ]);
        } catch (\Throwable $e) {
            Log::warning('RecordBackupRun::handleCleanupHasFailed failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Read the newest backup's size in bytes from the destination disk, or null
     * if it can't be determined (disk unreadable, no backups yet, etc.).
     */
    private function newestBackupSize(?string $diskName, ?string $backupName): ?int
    {
        if ($diskName === null || $backupName === null) {
            return null;
        }

        try {
            $destination = BackupDestination::create($diskName, $backupName);
            $newest = $destination->newestBackup();

            // sizeInBytes() returns a float; cast to int for storage. Null when
            // there is no backup on the disk yet.
            $size = $newest?->sizeInBytes();

            return $size !== null ? (int) $size : null;
        } catch (\Throwable) {
            // Disk not configured / unreadable in this context — size stays null.
            return null;
        }
    }

    /**
     * Extract a short, safe message from a throwable for storage.
     */
    private function throwableMessage(?\Throwable $throwable): string
    {
        if (! $throwable instanceof \Throwable) {
            return 'Backup failed (no exception detail available).';
        }

        $message = trim($throwable->getMessage());

        return $message !== '' ? $message : $throwable::class;
    }
}
