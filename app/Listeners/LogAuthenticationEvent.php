<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use OwenIt\Auditing\Models\Audit;

/**
 * Records authentication lifecycle events to the audit trail.
 *
 * Covers: login, logout, login_failed, login_lockout, password_reset.
 * Mirrors the LogImpersonation approach: direct Audit::create() call with the
 * canonical field shape. Each handler is wrapped in try/catch so a logging
 * failure can NEVER prevent authentication from completing.
 *
 * Registered in AppServiceProvider via Event::listen() calls.
 */
class LogAuthenticationEvent
{
    public function handleLogin(Login $event): void
    {
        try {
            $user = $event->user;
            $this->writeAudit(
                event: 'login',
                actor: $user,
                auditable: $user,
                newValues: ['guard' => $event->guard],
            );
        } catch (\Throwable $e) {
            Log::warning('LogAuthenticationEvent::handleLogin failed', ['error' => $e->getMessage()]);
        }
    }

    public function handleLogout(Logout $event): void
    {
        try {
            $user = $event->user;

            if ($user === null) {
                // Null user can occur on expired sessions; skip silently.
                return;
            }

            $this->writeAudit(
                event: 'logout',
                actor: $user,
                auditable: $user,
                newValues: ['guard' => $event->guard],
            );
        } catch (\Throwable $e) {
            Log::warning('LogAuthenticationEvent::handleLogout failed', ['error' => $e->getMessage()]);
        }
    }

    public function handleFailed(Failed $event): void
    {
        try {
            if (Audit::$auditingGloballyDisabled || ! config('audit.enabled', true)) {
                return;
            }

            $attemptedEmail = $event->credentials['email'] ?? null;

            $newValues = ['guard' => $event->guard];
            if ($attemptedEmail !== null) {
                $newValues['attempted_email'] = $attemptedEmail;
            }

            // auditable_type/id columns are NOT nullable (morphs() migration);
            // use 'system' / 0 as sentinels for events with no authenticated subject.
            Audit::create([
                'user_type' => null,
                'user_id' => null,
                'event' => 'login_failed',
                'auditable_type' => 'system',
                'auditable_id' => 0,
                'old_values' => [],
                'new_values' => $newValues,
                'url' => request()->fullUrl(),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'tags' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('LogAuthenticationEvent::handleFailed failed', ['error' => $e->getMessage()]);
        }
    }

    public function handleLockout(Lockout $event): void
    {
        try {
            if (Audit::$auditingGloballyDisabled || ! config('audit.enabled', true)) {
                return;
            }

            $req = $event->request;
            $attemptedEmail = $req->input('email');

            $newValues = [];
            if ($attemptedEmail !== null) {
                $newValues['attempted_email'] = $attemptedEmail;
            }

            Audit::create([
                'user_type' => null,
                'user_id' => null,
                'event' => 'login_lockout',
                'auditable_type' => 'system',
                'auditable_id' => 0,
                'old_values' => [],
                'new_values' => $newValues,
                'url' => $req->fullUrl(),
                'ip_address' => $req->ip(),
                'user_agent' => $req->userAgent(),
                'tags' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('LogAuthenticationEvent::handleLockout failed', ['error' => $e->getMessage()]);
        }
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        try {
            $user = $event->user;
            $this->writeAudit(
                event: 'password_reset',
                actor: $user,
                auditable: $user,
                newValues: [],
            );
        } catch (\Throwable $e) {
            Log::warning('LogAuthenticationEvent::handlePasswordReset failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Write a canonical audit row for events where we have an authenticated user.
     */
    private function writeAudit(
        string $event,
        Authenticatable $actor,
        Authenticatable $auditable,
        array $newValues = [],
        array $oldValues = [],
    ): void {
        if (Audit::$auditingGloballyDisabled || ! config('audit.enabled', true)) {
            return;
        }

        Audit::create([
            'user_type' => $actor->getMorphClass(),
            'user_id' => $actor->getAuthIdentifier(),
            'event' => $event,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => $auditable->getAuthIdentifier(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'url' => request()->fullUrl(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'tags' => null,
        ]);
    }
}
