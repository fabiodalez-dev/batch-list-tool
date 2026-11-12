<?php

declare(strict_types=1);

use App\Filament\Pages\Settings\BackupHealthPage;
use App\Models\User;
use App\Settings\BackupSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Spatie\Health\Checks\Checks\BackupsCheck;
use Spatie\Health\Facades\Health;

/**
 * Task 10 — Backup & health page.
 *
 * Covers:
 *   - access gate (admin/super_admin in, viewer out)
 *   - dispatching a backup run via header action
 *   - persisting retention settings via the page form
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

// ─── helpers ────────────────────────────────────────────────────────────────

function actAsAdmin_backup(): User
{
    $u = User::factory()->create([
        'email' => 'backup-admin+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('admin');

    return $u;
}

function actAsViewer_backup(): User
{
    $u = User::factory()->create([
        'email' => 'backup-viewer+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('viewer');

    return $u;
}

// ─── tests ───────────────────────────────────────────────────────────────────

it('gates the page to admins', function () {
    // authenticated viewer → canAccess returns false (gated to admin/super_admin)
    $viewer = actAsViewer_backup();
    $this->actingAs($viewer);
    expect(BackupHealthPage::canAccess())->toBeFalse();

    // admin → canAccess returns true
    $admin = actAsAdmin_backup();
    $this->actingAs($admin);
    expect(BackupHealthPage::canAccess())->toBeTrue();
});

it('dispatches a backup run', function () {
    $admin = actAsAdmin_backup();
    $this->actingAs($admin);

    // Spy on Artisan::queue so we can assert it is called without
    // actually running a backup in the test environment.
    // The full-backup action queues `backup:run` with an (empty) parameters
    // array — queueBackup('full', 'backup:run') → Artisan::queue('backup:run', []).
    Artisan::shouldReceive('queue')
        ->once()
        ->with('backup:run', []);

    Livewire\Livewire::test(BackupHealthPage::class)
        ->callAction('runBackup')
        ->assertNotified();
});

it('persists retention settings', function () {
    $admin = actAsAdmin_backup();
    $this->actingAs($admin);

    Livewire\Livewire::test(BackupHealthPage::class)
        ->fillForm([
            'keep_daily' => 30,
            'keep_weekly' => 10,
            'keep_monthly' => 6,
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $fresh = app(BackupSettings::class)->refresh();
    expect($fresh->keep_daily)->toBe(30)
        ->and($fresh->keep_weekly)->toBe(10)
        ->and($fresh->keep_monthly)->toBe(6);
});

/*
 * 2026-11-12: the Backups health check was handed the backup folder instead of
 * a glob, so it globbed the folder itself and failed with "does not exist"
 * every five minutes, whatever was inside.
 */
it('the Backups health check finds the zips in the backup folder', function (): void {
    $dir = storage_path('app/private/' . config('backup.backup.name'));
    $made = ! is_dir($dir);
    if ($made) {
        mkdir($dir, 0777, true);
    }
    $zip = $dir . '/health-check-probe-' . uniqid() . '.zip';
    file_put_contents($zip, 'zip');

    try {
        $check = collect(Health::registeredChecks())
            ->first(fn ($c): bool => $c instanceof BackupsCheck);
        $result = $check->run();

        expect($result->status->value)->toBe('ok');
    } finally {
        File::delete($zip);
        if ($made) {
            File::deleteDirectory($dir);
        }
    }
});

it('leaves the logs out of the nightly backup', function (): void {
    expect(config('backup.backup.source.files.exclude'))->toContain(storage_path('logs'))
        ->and(config('backup.backup.source.files.include'))->toContain(base_path());
});

it('schedules the heartbeat the Schedule health check reads', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->map(fn ($event): string => (string) $event->command);

    expect($events->contains(fn (string $c): bool => str_contains($c, 'health:schedule-check-heartbeat')))->toBeTrue();
});
