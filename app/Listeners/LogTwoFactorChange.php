<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use OwenIt\Auditing\Models\Audit;

/**
 * RFQ §3.1.7 hardening — write an audit row every time a user enables or
 * disables their TOTP-based second factor.
 *
 * The User model already uses owen-it's Auditable trait, but the change is
 * triggered by Fortify writing directly to the `users` table via forceFill()
 * — that path bypasses Eloquent events and therefore the standard
 * owen-it auto-audit. We bridge it explicitly here so the security trail
 * stays complete: actor, target (same user), timestamp, IP, UA.
 *
 * Auto-discovered by Laravel 11's event-listener discovery: any
 * `app/Listeners/*` class with a public `handle*` method typehinting an
 * event is registered automatically — no manual mapping required.
 */
class LogTwoFactorChange
{
    public function handleEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        /** @var User $user */
        $user = $event->user;

        $this->writeAudit('two_factor_enabled', $user);

        Log::info('Two-factor authentication enabled', [
            'user_id' => $user->id,
            'ip' => request()->ip(),
        ]);
    }

    public function handleDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        /** @var User $user */
        $user = $event->user;

        $this->writeAudit('two_factor_disabled', $user);

        Log::info('Two-factor authentication disabled', [
            'user_id' => $user->id,
            'ip' => request()->ip(),
        ]);
    }

    private function writeAudit(string $event, User $user): void
    {
        $request = request();

        Audit::create([
            'user_type' => $user->getMorphClass(),
            'user_id' => $user->id,
            'event' => $event,
            'auditable_type' => $user->getMorphClass(),
            'auditable_id' => $user->id,
            'old_values' => [],
            'new_values' => [
                'user' => $user->only(['id', 'name', 'email']),
            ],
            'url' => $request->fullUrl(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
