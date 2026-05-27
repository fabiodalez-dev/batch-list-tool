<?php

namespace App\Providers;

use App\Models\Document;
use App\Models\User;
use App\Observers\DocumentObserver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Telescope\TelescopeServiceProvider;

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
    }
}
