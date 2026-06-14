<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EnsurePasswordChanged — Task 6 (RFQ-2026-06).
 *
 * Intercepts every authenticated panel request and redirects to the Filament
 * profile page if the user has `must_change_password = true`.
 *
 * Allow-list (pass through without redirect):
 *   - No authenticated user (unauthenticated requests are handled by Filament's
 *     own Authenticate middleware before this one runs).
 *   - `must_change_password === false`.
 *   - The profile page itself (`filament.admin.auth.profile`) so the user can
 *     actually submit the new password.
 *   - The logout route (`filament.admin.auth.logout`) so the user is never
 *     trapped in the session.
 *   - Any request whose path starts with `livewire/` — the Filament profile
 *     form submits via Livewire AJAX; redirecting those breaks the form.
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Not authenticated — let Filament's auth middleware handle it.
        if ($user === null || ! $user->must_change_password) {
            return $next($request);
        }

        // Allowed routes: profile page, logout, Livewire AJAX.
        if (
            $request->routeIs('filament.admin.auth.profile')
            || $request->routeIs('filament.admin.auth.logout')
            || $request->is('livewire/*')
        ) {
            return $next($request);
        }

        // Redirect to profile with a Filament warning notification. Filament
        // renders notifications flashed to the session via Notification::send(),
        // so we queue it here rather than using an arbitrary ->with() flash key
        // (which Filament does not pick up).
        Notification::make()
            ->title(__('You must change your password before continuing.'))
            ->warning()
            ->send();

        return to_route('filament.admin.auth.profile');
    }
}
