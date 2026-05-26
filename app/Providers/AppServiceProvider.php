<?php

namespace App\Providers;

use App\Listeners\LogImpersonation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Lab404\Impersonate\Events\LeaveImpersonation;
use Lab404\Impersonate\Events\TakeImpersonation;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Audit every impersonation start/leave (RFQ §3.1.5)
        Event::listen(TakeImpersonation::class, [LogImpersonation::class, 'handleTake']);
        Event::listen(LeaveImpersonation::class, [LogImpersonation::class, 'handleLeave']);
    }
}
