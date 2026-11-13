<?php

declare(strict_types=1);

use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\UserResource\Pages\ViewUser;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;

/*
 * 2026-11-12: an administrator had no way to reset the two-factor
 * authentication of a user who lost their phone. "Reset two-factor
 * authentication" on the Users list and the user page: super administrators
 * only, never on themselves, confirmed, audited.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

function rtf_user(string $role, bool $withTwoFactor = false): User
{
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($role);

    if ($withTwoFactor) {
        $user->forceFill([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb', 'cccc-dddd'])),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    return $user;
}

it('lets a super administrator reset another user\'s two-factor authentication, and audits it', function (): void {
    $admin = rtf_user('super_admin');
    $target = rtf_user('editor', withTwoFactor: true);
    $this->actingAs($admin);
    $auditsBefore = Audit::query()->count();

    Livewire::test(ListUsers::class)
        ->assertActionVisible(TestAction::make('resetTwoFactor')->table($target))
        ->callAction(TestAction::make('resetTwoFactor')->table($target))
        ->assertNotified('Two-factor authentication reset');

    $target->refresh();
    expect($target->two_factor_secret)->toBeNull()
        ->and($target->two_factor_recovery_codes)->toBeNull()
        ->and($target->two_factor_confirmed_at)->toBeNull();

    $audit = Audit::query()->where('event', 'two_factor_reset')->sole();
    expect($audit->user_id)->toBe($admin->id)
        ->and($audit->auditable_type)->toBe($target->getMorphClass())
        ->and($audit->auditable_id)->toBe($target->id)
        // One row for the reset, not an extra empty "updated" one.
        ->and(Audit::query()->count())->toBe($auditsBefore + 1);
});

it('asks for confirmation before resetting', function (): void {
    $this->actingAs(rtf_user('super_admin'));
    $target = rtf_user('viewer', withTwoFactor: true);

    Livewire::test(ListUsers::class)
        ->mountAction(TestAction::make('resetTwoFactor')->table($target))
        ->assertActionMounted(TestAction::make('resetTwoFactor')->table($target));

    // Mounted, not yet confirmed: nothing has changed.
    expect($target->fresh()->two_factor_secret)->not->toBeNull();
});

it('offers the reset on the user page too', function (string $page): void {
    $this->actingAs(rtf_user('super_admin'));
    $target = rtf_user('editor', withTwoFactor: true);

    Livewire::test($page, ['record' => $target->getRouteKey()])
        ->assertActionVisible('resetTwoFactor')
        ->callAction('resetTwoFactor');

    expect($target->fresh()->two_factor_secret)->toBeNull();
})->with([
    'view page' => [ViewUser::class],
    'edit page' => [EditUser::class],
]);

it('hides the reset from an administrator who is not a super administrator', function (): void {
    $this->actingAs(rtf_user('admin'));
    $target = rtf_user('editor', withTwoFactor: true);

    Livewire::test(ListUsers::class)
        ->assertActionHidden(TestAction::make('resetTwoFactor')->table($target));
});

it('hides the reset on the super administrator\'s own row', function (): void {
    $admin = rtf_user('super_admin', withTwoFactor: true);
    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->assertActionHidden(TestAction::make('resetTwoFactor')->table($admin));

    Livewire::test(ViewUser::class, ['record' => $admin->getRouteKey()])
        ->assertActionHidden('resetTwoFactor');
});

it('hides the reset for a user without two-factor authentication', function (): void {
    $this->actingAs(rtf_user('super_admin'));
    $target = rtf_user('editor');

    Livewire::test(ListUsers::class)
        ->assertActionHidden(TestAction::make('resetTwoFactor')->table($target));
});
