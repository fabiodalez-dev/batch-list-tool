<?php

namespace App\Http\Middleware;

use App\Support\ActiveRepository;
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
 *   - locale       → app()->setLocale($user->locale)
 *   - timezone     → FilamentTimezone::set($user->timezone)
 *   - active repo  → ActiveRepository::restoreFromPreference() — once per
 *                    session, restores the user's last EXPLICIT repository
 *                    choice from users.active_repository_id into the session.
 *                    Guarded: runs only when the session key is absent, so it
 *                    is a no-op on every request after the first one.
 *                    Admin/super_admin who never chose (column null) → session
 *                    gets null → id() returns null → they see ALL repositories
 *                    (EXPAND-NEVER-RESTRICT / admin-override behaviour preserved).
 *
 * Both applications are wrapped in defensive guards (null checks + try/catch)
 * so a bad value can never 500 a page.
 *
 * Page-size is applied in AppServiceProvider via Table::configureUsing().
 */
class ApplyUserPreferences
{
    public function __construct(private readonly ActiveRepository $activeRepository) {}

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

                // Restore the user's last EXPLICIT repository choice from the
                // persisted preference (users.active_repository_id) into the
                // session — ONCE per session, when the session key is absent.
                //
                // restoreFromPreference() is internally guarded: it is a no-op
                // when the session key is already present, so subsequent requests
                // within the same session are unaffected.
                //
                // Admin-override behaviour is preserved: for privileged users who
                // never explicitly chose a repository (column = null), this writes
                // null into the session, which is identical to no selection →
                // id() returns null → they see all repositories.
                $this->activeRepository->restoreFromPreference();
            }
        } catch (\Throwable) {
            // Never let a bad preference crash a page.
        }

        return $next($request);
    }
}
