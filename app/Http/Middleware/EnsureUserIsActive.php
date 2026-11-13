<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out a deactivated account (is_active = false) on every web request.
 *
 * The panel already refuses such a user through User::canAccessPanel(), but
 * the routes outside it (backup download, attachments, the Fortify /user/*
 * endpoints) only ask for `auth`. Without this, an account deactivated while
 * signed in, or one that signs in through Fortify's POST /login, passkeys or
 * a remember cookie, keeps reaching them. Runs again after the request so a
 * login made by the request itself never survives it either.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $this->signOutIfInactive($request);

        $response = $next($request);

        $this->signOutIfInactive($request);

        return $response;
    }

    private function signOutIfInactive(Request $request): void
    {
        $user = Auth::guard('web')->user();

        // Only an explicit false counts: the column defaults to true, so an
        // in-memory model created without the attribute is an active account.
        if (! $user instanceof User || $user->is_active !== false) {
            return;
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
