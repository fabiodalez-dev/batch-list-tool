<?php

declare(strict_types=1);

use App\Filament\Pages\Settings\BackupHealthPage;
use App\Models\User;
use App\Settings\BackupSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Backup\Commands\CleanupCommand;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Tasks\Cleanup\CleanupStrategy;

/*
 * 2026-11-12: "Save retention" on the Backup & Health page wrote BackupSettings,
 * but backup:clean kept reading the fixed numbers in config/backup.php, so the
 * page promised a policy the cleanup never applied.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

/**
 * The retention values the cleanup strategy will use, read the way a fresh
 * `php artisan backup:clean` process builds them: a new Config and a new
 * strategy from the container.
 *
 * @return array{daily: int, weekly: int, monthly: int}
 */
function cleanupRetentionInUse(): array
{
    app()->forgetInstance(Config::class);

    $strategy = app(CleanupStrategy::class);
    $config = (fn (): Config => $this->config)->call($strategy);
    $values = $config->cleanup->defaultStrategy;

    return [
        'daily' => $values->keepDailyBackupsForDays,
        'weekly' => $values->keepWeeklyBackupsForWeeks,
        'monthly' => $values->keepMonthlyBackupsForMonths,
    ];
}

it('makes the cleanup use the retention saved on the Backup & Health page', function (): void {
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(BackupHealthPage::class)
        ->fillForm(['keep_daily' => 30, 'keep_weekly' => 12, 'keep_monthly' => 9])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(cleanupRetentionInUse())->toBe(['daily' => 30, 'weekly' => 12, 'monthly' => 9]);
});

it('follows a later change of the settings', function (): void {
    $settings = app(BackupSettings::class);
    $settings->keep_daily = 5;
    $settings->keep_weekly = 3;
    $settings->keep_monthly = 2;
    $settings->save();

    expect(cleanupRetentionInUse())->toBe(['daily' => 5, 'weekly' => 3, 'monthly' => 2]);

    $settings->keep_daily = 40;
    $settings->save();

    expect(cleanupRetentionInUse()['daily'])->toBe(40);
});

it('hands the stored retention to the backup:clean command itself', function (): void {
    $settings = app(BackupSettings::class);
    $settings->keep_daily = 21;
    $settings->save();

    app()->forgetInstance(Config::class);
    $command = app(CleanupCommand::class);
    $strategy = (fn (): CleanupStrategy => $this->strategy)->call($command);
    $config = (fn (): Config => $this->config)->call($strategy);

    expect($config->cleanup->defaultStrategy->keepDailyBackupsForDays)->toBe(21);
});

it('falls back to config/backup.php when a stored value is not usable', function (): void {
    $settings = app(BackupSettings::class);
    $settings->keep_daily = 0;
    $settings->keep_weekly = 6;
    $settings->save();

    expect(cleanupRetentionInUse())->toBe([
        'daily' => (int) config('backup.cleanup.default_strategy.keep_daily_backups_for_days'),
        'weekly' => 6,
        'monthly' => app(BackupSettings::class)->keep_monthly,
    ]);
});
