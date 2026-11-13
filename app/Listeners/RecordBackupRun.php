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
 * by Laravel's listener discovery (one handle* method per event).
 *
 * A backup started from the Backup & Health page already has a "running" row:
 * while RunBackupFromBackupCenter runs the command inside recordingInto(), the
 * backup events complete that row instead of adding a second one.
 */
class RecordBackupRun
{
    /** The page-started BackupRun the running backup reports into, if any. */
    private static ?int $currentRunId = null;

    /**
     * Run $callback with backup events written onto the BackupRun $runId.
     *
     * @template T
     *
     * @param callable(): T $callback
     * @return T
     */
    public static function recordingInto(int $runId, callable $callback): mixed
    {
        $previous = self::$currentRunId;
        self::$currentRunId = $runId;

        try {
            return $callback();
        } finally {
            self::$currentRunId = $previous;
        }
    }

    public function handleBackupWasSuccessful(BackupWasSuccessful $event): void
    {
        try {
            $sizeBytes = $this->newestBackupSize($event->diskName, $event->backupName);
            $message = "Backup '{$event->backupName}' completed on disk '{$event->diskName}'.";

            $current = $this->currentRun();
            if ($current instanceof BackupRun) {
                // With several destination disks spatie reports each one; a
                // failure on one disk must not be overwritten by a later success.
                $current->fill([
                    'destination_disk' => $event->diskName,
                    'status' => $current->status === 'failed' ? 'failed' : 'success',
                    'finished_at' => now(),
                    'size_bytes' => $sizeBytes ?? $current->size_bytes,
                    'message' => $current->status === 'failed' ? $current->message : $message,
                ])->save();

                return;
            }

            BackupRun::create([
                'type' => 'backup',
                'destination_disk' => $event->diskName,
                'status' => 'success',
                'started_at' => now(),
                'finished_at' => now(),
                'size_bytes' => $sizeBytes,
                'message' => $message,
            ]);
        } catch (\Throwable $throwable) {
            Log::warning('RecordBackupRun::handleBackupWasSuccessful failed', ['error' => $throwable->getMessage()]);
        }
    }

    public function handleBackupHasFailed(BackupHasFailed $event): void
    {
        try {
            $current = $this->currentRun();
            if ($current instanceof BackupRun) {
                $current->fill([
                    'destination_disk' => $event->diskName ?? $current->destination_disk,
                    'status' => 'failed',
                    'finished_at' => now(),
                    'message' => $this->throwableMessage($event->exception ?? null),
                ])->save();

                return;
            }

            BackupRun::create([
                'type' => 'backup',
                'destination_disk' => $event->diskName,
                'status' => 'failed',
                'started_at' => now(),
                'finished_at' => now(),
                'message' => $this->throwableMessage($event->exception ?? null),
            ]);
        } catch (\Throwable $throwable) {
            Log::warning('RecordBackupRun::handleBackupHasFailed failed', ['error' => $throwable->getMessage()]);
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
        } catch (\Throwable $throwable) {
            Log::warning('RecordBackupRun::handleCleanupWasSuccessful failed', ['error' => $throwable->getMessage()]);
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
        } catch (\Throwable $throwable) {
            Log::warning('RecordBackupRun::handleCleanupHasFailed failed', ['error' => $throwable->getMessage()]);
        }
    }

    private function currentRun(): ?BackupRun
    {
        return self::$currentRunId !== null
            ? BackupRun::query()->find(self::$currentRunId)
            : null;
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
