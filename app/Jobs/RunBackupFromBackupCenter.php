<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Listeners\RecordBackupRun;
use App\Models\BackupRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * Runs a backup started from the Backup & Health page and closes the
 * "running" BackupRun row the page created for it.
 *
 * While the command runs, RecordBackupRun writes spatie's success/failure
 * events onto that row instead of adding a second one, so the history shows
 * one line per run with its real outcome and finish time. If the command
 * ends without either event (it could not start), the exit code decides.
 */
class RunBackupFromBackupCenter implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** A backup is not retried automatically: the operator starts another. */
    public int $tries = 1;

    /** A full backup (database dump + files + zip) takes minutes, not seconds. */
    public int $timeout = 1800;

    /**
     * @param array<string, mixed> $parameters backup:run options, e.g. ['--only-db' => true]
     */
    public function __construct(
        public int $backupRunId,
        public array $parameters = [],
    ) {}

    public function handle(): void
    {
        $exitCode = RecordBackupRun::recordingInto(
            $this->backupRunId,
            fn (): int => Artisan::call('backup:run', $this->parameters),
        );

        $run = BackupRun::query()->find($this->backupRunId);

        if (! $run instanceof BackupRun) {
            return;
        }

        if ($run->status === 'running') {
            $output = trim(Artisan::output());

            $run->fill([
                'status' => $exitCode === 0 ? 'success' : 'failed',
                'message' => $exitCode === 0
                    ? 'Backup completed.'
                    : ($output !== '' ? Str::limit($output, 500) : "backup:run ended with exit code {$exitCode}."),
            ]);
        }

        self::close($run);
    }

    public function failed(?\Throwable $exception): void
    {
        $run = BackupRun::query()->find($this->backupRunId);

        if (! $run instanceof BackupRun || $run->status !== 'running') {
            return;
        }

        $run->fill([
            'status' => 'failed',
            'message' => $exception instanceof \Throwable && trim($exception->getMessage()) !== ''
                ? Str::limit(trim($exception->getMessage()), 500)
                : 'The backup job stopped before the backup finished.',
        ]);

        self::close($run);
    }

    /**
     * Stamp the finish time and duration and save.
     */
    private static function close(BackupRun $run): void
    {
        $finishedAt = $run->finished_at ?? now();

        $run->finished_at = $finishedAt;
        $run->duration_seconds = $run->started_at !== null
            ? max(0, (int) $run->started_at->diffInSeconds($finishedAt))
            : null;

        $run->save();
    }
}
