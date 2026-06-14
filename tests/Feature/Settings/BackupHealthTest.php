<?php

declare(strict_types=1);

use App\Filament\Pages\Settings\BackupHealthPage;
use App\Models\User;
use App\Settings\BackupSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

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

    $fresh = resolve(BackupSettings::class)->refresh();
    expect($fresh->keep_daily)->toBe(30)
        ->and($fresh->keep_weekly)->toBe(10)
        ->and($fresh->keep_monthly)->toBe(6);
});
