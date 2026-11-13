<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OwenIt\Auditing\Models\Audit;

/**
 * A super administrator clears another user's two-factor authentication (a
 * lost phone, a new device): secret, recovery codes and confirmation time go,
 * so the user signs in with the password alone and can set 2FA up again from
 * their profile.
 *
 * The 2FA columns are excluded from the model audit (User::$auditExclude), so
 * the reset writes its own audit row: the administrator as actor, the user as
 * the record, like LogTwoFactorChange does for the user's own changes.
 */
final class ResetTwoFactorAuthentication
{
    public function __invoke(User $user, User $administrator): void
    {
        abort_unless($administrator->hasRole('super_admin'), 403);
        abort_if($administrator->is($user), 403, 'Use your own profile to change your two-factor authentication.');

        DB::transaction(function () use ($user, $administrator): void {
            $user->forceFill([
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
            ])->save();

            $request = request();

            Audit::create([
                'user_type' => $administrator->getMorphClass(),
                'user_id' => $administrator->getKey(),
                'event' => 'two_factor_reset',
                'auditable_type' => $user->getMorphClass(),
                'auditable_id' => $user->getKey(),
                'old_values' => [],
                'new_values' => [
                    'user' => $user->only(['id', 'name', 'email']),
                ],
                'url' => $request->fullUrl(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        });

        Log::info('Two-factor authentication reset by a super administrator', [
            'user_id' => $user->getKey(),
            'by_user_id' => $administrator->getKey(),
        ]);
    }
}
