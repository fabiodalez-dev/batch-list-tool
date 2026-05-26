<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * RFQ §3.1.7 hardening — Filament login subclass that enforces a TOTP
 * second-factor challenge for users who have completed 2FA enrolment.
 *
 * Behaviour:
 *
 *   1. Password is validated by `parent::authenticate()` (Filament's
 *      stock flow, including throttling and Shield panel-access gate).
 *
 *   2. Once the session is authenticated we inspect the user. If
 *      `two_factor_confirmed_at` is null (the user has NOT enrolled),
 *      the LoginResponse is returned unchanged — login finishes here.
 *      This is the "opt-in by default" stance promised in the bid.
 *
 *   3. If 2FA IS enrolled, we immediately log the user back OUT,
 *      stash their id under Fortify's conventional `login.id` session
 *      key, and redirect to `/two-factor-challenge` (the GET view we
 *      register in routes/web.php; the POST is provided by Fortify).
 *      They are only fully signed in after submitting a valid 6-digit
 *      TOTP code or a recovery code.
 */
class TwoFactorLogin extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        $response = parent::authenticate();

        $this->challengeTwoFactorIfEnrolled();

        return $response;
    }

    protected function challengeTwoFactorIfEnrolled(): void
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User || ! $user->two_factor_confirmed_at) {
            return;
        }

        $remember = (bool) ($this->data['remember'] ?? false);

        Filament::auth()->logout();

        session()->put([
            'login.id' => $user->getKey(),
            'login.remember' => $remember,
        ]);

        throw new HttpResponseException(
            redirect()->to(url('/two-factor-challenge')),
        );
    }
}
