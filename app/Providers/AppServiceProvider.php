<?php

namespace App\Providers;

use App\Models\Document;
use App\Models\User;
use App\Observers\DocumentObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Telescope\TelescopeServiceProvider;
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

        // Laravel Pulse dashboard access: restrict /pulse to super_admin and admin
        // roles only. Pulse's PulseServiceProvider checks Gate::check('viewPulse')
        // on its dashboard route.
        Gate::define('viewPulse', function (?User $user): bool {
            return $user !== null && $user->hasAnyRole(['super_admin', 'admin']);
        });

        $this->registerHealthChecks();
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
