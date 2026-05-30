<?php

namespace App\Providers;

use App\Listeners\LogAuthenticationEvent;
use App\Listeners\RecordBackupRun;
use App\Models\Document;
use App\Models\User;
use App\Observers\DocumentObserver;
use App\Settings\AuditSettings;
use App\Settings\BackupSettings;
use App\Support\BackupDestinations;
use Filament\Tables\Table;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Telescope\TelescopeServiceProvider;
use OwenIt\Auditing\Models\Audit;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\CleanupHasFailed;
use Spatie\Backup\Events\CleanupWasSuccessful;
use Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification;
use Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification;
use Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification;
use Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification;
use Spatie\Health\Checks\Checks\BackupsCheck;
use Spatie\Health\Checks\Checks\DatabaseCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Checks\Checks\UsedDiskSpaceCheck;
use Spatie\Health\Facades\Health;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope is a require-dev package; only register its provider
        // when the class is actually autoloadable (i.e. NOT in production
        // where `composer install --no-dev` ships without Telescope).
        if ($this->app->environment('local') && class_exists(TelescopeServiceProvider::class)) {
            $this->app->register(\App\Providers\TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Document::observe(DocumentObserver::class);

        // Apply each user's preferred_page_size as the default pagination page
        // option for every Filament table. The closure runs at table-render time
        // (after the request is bootstrapped), so auth() is available.
        // Wrapped defensively: a null/invalid value must never break a page.
        Table::configureUsing(function (Table $table): void {
            try {
                $size = auth()->user()?->preferred_page_size;
                if ($size && is_int($size) && $size > 0) {
                    $table->defaultPaginationPageOption($size);
                }
            } catch (\Throwable) {
                // Never let a missing preference crash a page.
            }
        });

        // Authentication event listeners — write every login lifecycle event to
        // the audit trail (RFQ §3.1.5). Registered here to mirror the
        // LogImpersonation pattern (no separate EventServiceProvider needed).
        Event::listen(Login::class, [LogAuthenticationEvent::class, 'handleLogin']);
        Event::listen(Logout::class, [LogAuthenticationEvent::class, 'handleLogout']);
        Event::listen(Failed::class, [LogAuthenticationEvent::class, 'handleFailed']);
        Event::listen(Lockout::class, [LogAuthenticationEvent::class, 'handleLockout']);
        Event::listen(PasswordReset::class, [LogAuthenticationEvent::class, 'handlePasswordReset']);

        // Backup lifecycle listeners — record every spatie/laravel-backup run
        // (and cleanup) into backup_runs so the Backup Center can show history.
        // Same Event::listen() pattern as the auth listeners above.
        Event::listen(BackupWasSuccessful::class, [RecordBackupRun::class, 'handleBackupWasSuccessful']);
        Event::listen(BackupHasFailed::class, [RecordBackupRun::class, 'handleBackupHasFailed']);
        Event::listen(CleanupWasSuccessful::class, [RecordBackupRun::class, 'handleCleanupWasSuccessful']);
        Event::listen(CleanupHasFailed::class, [RecordBackupRun::class, 'handleCleanupHasFailed']);

        $this->configureBackupNotifications();

        // Mirror AuditSettings::enabled at runtime so that toggling the Audit
        // settings page actually stops new audit rows being written.
        //
        // owen-it/laravel-auditing only checks config('audit.enabled') at model
        // boot (to decide whether to register the observer). After boot, the
        // runtime on/off switch is Audit::$auditingGloballyDisabled. We set
        // both so that:
        //   - Fresh boots with auditing off never register the observer.
        //   - Long-running processes (Octane, queue workers) honour the setting
        //     without needing a restart.
        //
        // Wrapped in a defensive try/catch: during early boot or fresh installs
        // the settings table may not yet exist. In that case we leave both
        // values at their defaults rather than crashing the entire request.
        try {
            $auditEnabled = app(AuditSettings::class)->enabled;
            config(['audit.enabled' => $auditEnabled]);
            Audit::$auditingGloballyDisabled = ! $auditEnabled;
        } catch (\Throwable) {
            // Settings table unavailable (migration not yet run, test isolation,
            // etc.) — fall back to the defaults in config/audit.php.
        }

        // Laravel Pulse dashboard access: restrict /pulse to super_admin and admin
        // roles only. Pulse's PulseServiceProvider checks Gate::check('viewPulse')
        // on its dashboard route.
        Gate::define('viewPulse', function (?User $user): bool {
            return $user !== null && $user->hasAnyRole(['super_admin', 'admin']);
        });

        $this->registerHealthChecks();

        // Register user-defined backup destinations (DB-backed) as runtime
        // filesystem disks and wire them into spatie/laravel-backup's disk list.
        // Self-guards on the backup_destinations table, so it is safe to call
        // here even on a fresh install before migrations have run.
        BackupDestinations::register();
    }

    /**
     * Wire spatie/laravel-backup mail notifications from BackupSettings.
     *
     * Sets the recipient list (config('backup.notifications.mail.to')) from the
     * admin-configured BackupSettings::notify_emails. Honours the per-event
     * toggles notify_on_success / notify_on_failure by adjusting the per-event
     * notification channel map (config('backup.notifications.notifications')):
     * a disabled event gets its channels cleared so spatie sends nothing for it.
     *
     * Wrapped defensively: on a fresh install (settings table not yet migrated)
     * we leave the config/backup.php defaults untouched rather than crash boot.
     */
    protected function configureBackupNotifications(): void
    {
        try {
            $settings = app(BackupSettings::class);

            $recipients = array_values(array_filter(
                $settings->notify_emails,
                static fn ($email): bool => is_string($email) && $email !== '',
            ));

            config(['backup.notifications.mail.to' => $recipients]);

            // Per-event toggles: clear the channel list for any event the admin
            // has switched off so spatie skips sending that notification.
            $map = (array) config('backup.notifications.notifications', []);

            $successNotifications = [
                BackupWasSuccessfulNotification::class,
                CleanupWasSuccessfulNotification::class,
                HealthyBackupWasFoundNotification::class,
            ];
            $failureNotifications = [
                BackupHasFailedNotification::class,
                CleanupHasFailedNotification::class,
                UnhealthyBackupWasFoundNotification::class,
            ];

            if (! $settings->notify_on_success) {
                foreach ($successNotifications as $notification) {
                    if (array_key_exists($notification, $map)) {
                        $map[$notification] = [];
                    }
                }
            }

            if (! $settings->notify_on_failure) {
                foreach ($failureNotifications as $notification) {
                    if (array_key_exists($notification, $map)) {
                        $map[$notification] = [];
                    }
                }
            }

            config(['backup.notifications.notifications' => $map]);
        } catch (\Throwable) {
            // Settings table unavailable (fresh install / test isolation) —
            // fall back to the config/backup.php defaults.
        }
    }

    /**
     * Register the application health checks exposed via /health.
     *
     * Four standard checks for NAF IT monitoring / uptime probes (RFQ-2026-06 §3.4.1):
     *   - Database connectivity
     *   - Used disk space (warn 80%, fail 95%)
     *   - Laravel schedule running (verifies `schedule:run` cron is firing)
     *   - Recent backup present (younger than 2 days) under the local-disk
     *     spatie/laravel-backup destination
     */
    protected function registerHealthChecks(): void
    {
        // The local-disk path where spatie/laravel-backup stores its zip archives.
        // `config('backup.backup.name')` resolves to APP_NAME by default, and the
        // 'local' filesystem disk roots at storage/app/private — so backups land
        // under storage/app/private/<APP_NAME>/.
        $backupName = (string) config('backup.backup.name', 'laravel-backup');
        $backupPath = storage_path('app/private/' . $backupName);

        Health::checks([
            DatabaseCheck::new(),
            UsedDiskSpaceCheck::new()
                ->warnWhenUsedSpaceIsAbovePercentage(80)
                ->failWhenUsedSpaceIsAbovePercentage(95),
            ScheduleCheck::new(),
            BackupsCheck::new()
                ->locatedAt($backupPath)
                ->youngestBackShouldHaveBeenMadeBefore(now()->subDays(2)),
        ]);
    }
}
