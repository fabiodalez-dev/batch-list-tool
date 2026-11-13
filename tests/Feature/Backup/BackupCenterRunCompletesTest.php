<?php

declare(strict_types=1);

use App\Filament\Pages\Settings\BackupHealthPage;
use App\Jobs\RunBackupFromBackupCenter;
use App\Models\BackupRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;

/*
 * 2026-11-12: a backup started from the Backup & Health page left its row on
 * "Running" for ever, and the outcome appeared as a second BACKUP row. The run
 * the page starts now ends with its own status and finish time.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

/**
 * Start a DB-only backup from the page with the queue faked, and return the
 * queued job and the "running" row it belongs to.
 *
 * @return array{0: RunBackupFromBackupCenter, 1: BackupRun}
 */
function startBackupFromPage(): array
{
    Queue::fake();

    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    test()->actingAs($admin);

    Livewire::test(BackupHealthPage::class)->callAction('runDbBackup');

    $job = null;
    Queue::assertPushed(RunBackupFromBackupCenter::class, function (RunBackupFromBackupCenter $pushed) use (&$job): bool {
        $job = $pushed;

        return true;
    });

    $run = BackupRun::query()->sole();
    expect($run->status)->toBe('running')
        ->and($job->backupRunId)->toBe($run->id)
        ->and($job->parameters)->toBe(['--only-db' => true]);

    return [$job, $run];
}

it('closes the page-started run as success, without a second row', function (): void {
    [$job, $run] = startBackupFromPage();

    // backup:run itself is replaced by what it does: fire spatie's event.
    Artisan::shouldReceive('call')->once()->with('backup:run', ['--only-db' => true])
        ->andReturnUsing(function (): int {
            event(new BackupWasSuccessful('local', 'Logistics Tool'));

            return 0;
        });

    $job->handle();

    expect(BackupRun::query()->count())->toBe(1);

    $run->refresh();
    expect($run->status)->toBe('success')
        ->and($run->type)->toBe('db')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->duration_seconds)->not->toBeNull()
        ->and($run->message)->toContain('completed');
});

it('closes the page-started run as failed with the error, without a second row', function (): void {
    [$job, $run] = startBackupFromPage();

    Artisan::shouldReceive('call')->once()
        ->andReturnUsing(function (): int {
            event(new BackupHasFailed(new RuntimeException('mysqldump: access denied'), 'local', 'Logistics Tool'));

            return 1;
        });

    $job->handle();

    expect(BackupRun::query()->count())->toBe(1);

    $run->refresh();
    expect($run->status)->toBe('failed')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->message)->toBe('mysqldump: access denied');
});

it('uses the exit code when the command reports no backup event', function (): void {
    [$job, $run] = startBackupFromPage();

    Artisan::shouldReceive('call')->once()->andReturn(1);
    Artisan::shouldReceive('output')->andReturn('No disks configured.');

    $job->handle();

    $run->refresh();
    expect($run->status)->toBe('failed')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->message)->toBe('No disks configured.');
});

it('marks the run failed when the job itself dies', function (): void {
    [$job, $run] = startBackupFromPage();

    $job->failed(new RuntimeException('Job timed out'));

    $run->refresh();
    expect($run->status)->toBe('failed')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->message)->toBe('Job timed out');
});

it('still records scheduled backups as their own rows', function (): void {
    event(new BackupWasSuccessful('local', 'Logistics Tool'));

    expect(BackupRun::query()->sole()->status)->toBe('success');
});
