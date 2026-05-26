<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Lab404\Impersonate\Events\LeaveImpersonation;
use Lab404\Impersonate\Events\TakeImpersonation;
use OwenIt\Auditing\Models\Audit;

/**
 * Audit every impersonation start/leave. RFQ §3.1.5 requires every privileged
 * action to land in the audit trail; impersonation is the most sensitive of
 * those because the actor and the target diverge for the duration.
 *
 * Listener bound in AppServiceProvider for both Lab404 events.
 */
class LogImpersonation
{
    public function handleTake(TakeImpersonation $event): void
    {
        $this->writeAudit(
            event: 'impersonation_started',
            actor: $event->impersonator,
            target: $event->impersonated,
        );

        Log::info('Impersonation started', [
            'actor_id' => $event->impersonator->id,
            'target_id' => $event->impersonated->id,
            'ip' => request()->ip(),
        ]);
    }

    public function handleLeave(LeaveImpersonation $event): void
    {
        $this->writeAudit(
            event: 'impersonation_ended',
            actor: $event->impersonator,
            target: $event->impersonated,
        );

        Log::info('Impersonation ended', [
            'actor_id' => $event->impersonator->id,
            'target_id' => $event->impersonated->id,
            'ip' => request()->ip(),
        ]);
    }

    private function writeAudit(string $event, User $actor, User $target): void
    {
        Audit::create([
            'user_type' => $actor->getMorphClass(),
            'user_id' => $actor->id,
            'event' => $event,
            'auditable_type' => $target->getMorphClass(),
            'auditable_id' => $target->id,
            'old_values' => ['actor' => $actor->only(['id', 'name', 'email'])],
            'new_values' => ['target' => $target->only(['id', 'name', 'email'])],
            'url' => request()->fullUrl(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
