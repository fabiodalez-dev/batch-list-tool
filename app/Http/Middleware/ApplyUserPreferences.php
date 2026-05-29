<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ApplyUserPreferences middleware.
 *
 * Runs on every authenticated panel request and applies the current user's
 * display preferences:
 *
 *   - locale  → app()->setLocale($user->locale)
 *   - timezone → FilamentTimezone::set($user->timezone)
 *
 * Both applications are wrapped in defensive guards (null checks + try/catch)
 * so a bad value can never 500 a page.
 *
 * Page-size is applied in AppServiceProvider via Table::configureUsing().
 */
class ApplyUserPreferences
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $user = auth()->user();

            if ($user !== null) {
                // Apply locale preference.
                if (! empty($user->locale)) {
                    app()->setLocale($user->locale);
                }

                // Apply display timezone via FilamentTimezone facade.
                if (! empty($user->timezone)) {
                    FilamentTimezone::set($user->timezone);
                }
            }
        } catch (\Throwable) {
            // Never let a bad preference crash a page.
        }

        return $next($request);
    }
}
