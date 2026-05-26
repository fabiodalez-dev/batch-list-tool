<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Listeners\LogTwoFactorChange;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

/**
 * RFQ §3.1.7 hardening — Two-Factor (TOTP) self-service page.
 *
 * Per-user enrolment/management for time-based one-time-password 2FA.
 * Fortify supplies the heavy lifting (secret generation, QR rendering,
 * verification, recovery codes); this page only wraps it in a
 * Filament-shaped UX:
 *
 *   - "Enable" → calls EnableTwoFactorAuthentication, stores ciphertext
 *     for the secret + 8 recovery codes, then renders the QR + 6-digit
 *     confirmation input. The codes are shown ONCE; on subsequent
 *     visits the recovery panel is hidden.
 *
 *   - "Confirm" → calls ConfirmTwoFactorAuthentication; on success the
 *     user's `two_factor_confirmed_at` is set and (via the
 *     {@see LogTwoFactorChange} listener auto-discovered
 *     from app/Listeners) an audit row with
 *     event=`two_factor_enabled` is written.
 *
 *   - "Regenerate recovery codes" → calls GenerateNewRecoveryCodes and
 *     re-shows the panel once.
 *
 *   - "Disable" → password-confirmed; calls
 *     DisableTwoFactorAuthentication which clears all three columns and
 *     fires TwoFactorAuthenticationDisabled → audit row.
 *
 * Opt-in by default. RFQ §3.1.7 ("recommended for admin / super_admin")
 * is communicated in the page copy, not enforced at the auth layer —
 * mandatory enrolment is a separate Tier B decision.
 */
class TwoFactorProfile extends Page
{
    /**
     * Recovery codes are kept in component state ONLY for the duration of
     * the request that just generated/regenerated them — never persisted
     * to the session or cached. On subsequent visits the user has to
     * regenerate the codes explicitly to see them again, which guarantees
     * they cannot be casually leaked from a stale browser tab.
     *
     * @var array<int, string>|null
     */
    public ?array $showRecoveryCodes = null;

    /**
     * 6-digit TOTP code the user types during enrolment confirmation.
     */
    public string $confirmCode = '';

    /**
     * Plaintext password collected by the "Disable 2FA" confirmation modal.
     */
    public string $disablePassword = '';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 20;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $title = 'Two-factor authentication';

    protected static ?string $slug = 'profile/two-factor';

    protected string $view = 'filament.pages.two-factor-profile';

    public function getHeading(): string|Htmlable
    {
        return 'Two-factor authentication';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Add an authenticator-app code to your sign-in. Strongly recommended for users with admin or super_admin roles.';
    }

    /**
     * Self-service page — every authenticated panel user may manage their
     * OWN second factor regardless of role. No Shield permission gate.
     */
    public static function canAccess(): bool
    {
        return auth()->check();
    }

    /**
     * Filament discovers this page automatically. We hide it from the
     * sidebar because it lives under "your profile" — operators reach it
     * from a future profile-menu link or by URL.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public function enableAction(): Action
    {
        return Action::make('enable')
            ->label('Enable two-factor authentication')
            ->color('primary')
            ->icon('heroicon-m-shield-check')
            ->visible(fn (): bool => ! $this->user()->two_factor_secret)
            ->requiresConfirmation()
            ->modalHeading('Enable two-factor authentication')
            ->modalDescription('This will generate a secret you can scan with an authenticator app (1Password, Authy, Google Authenticator, etc).')
            ->action(function (): void {
                app(EnableTwoFactorAuthentication::class)($this->user());

                // Reveal recovery codes for this request only.
                $this->user()->refresh();
                $this->showRecoveryCodes = $this->user()->recoveryCodes();

                Notification::make()
                    ->title('Secret generated — scan the QR code, then enter the 6-digit code to finish enrolment.')
                    ->success()
                    ->send();
            });
    }

    public function confirmAction(): Action
    {
        return Action::make('confirm')
            ->label('Confirm enrolment')
            ->color('primary')
            ->icon('heroicon-m-check-badge')
            ->visible(fn (): bool => $this->user()->two_factor_secret
                && ! $this->user()->two_factor_confirmed_at)
            ->action(function (): void {
                try {
                    app(ConfirmTwoFactorAuthentication::class)(
                        $this->user(),
                        trim($this->confirmCode),
                    );
                } catch (ValidationException $e) {
                    Notification::make()
                        ->title('The code you entered is not valid. Please try again.')
                        ->danger()
                        ->send();

                    return;
                }

                $this->confirmCode = '';

                Notification::make()
                    ->title('Two-factor authentication enabled.')
                    ->body('You will be asked for a 6-digit code on your next sign-in.')
                    ->success()
                    ->send();
            });
    }

    public function regenerateRecoveryCodesAction(): Action
    {
        return Action::make('regenerateRecoveryCodes')
            ->label('Regenerate recovery codes')
            ->color('warning')
            ->icon('heroicon-m-arrow-path')
            ->visible(fn (): bool => (bool) $this->user()->two_factor_confirmed_at)
            ->requiresConfirmation()
            ->modalHeading('Regenerate recovery codes')
            ->modalDescription('Your old recovery codes will stop working immediately. Save the new codes somewhere safe before leaving this page.')
            ->action(function (): void {
                app(GenerateNewRecoveryCodes::class)($this->user());

                $this->user()->refresh();
                $this->showRecoveryCodes = $this->user()->recoveryCodes();

                Notification::make()
                    ->title('Recovery codes regenerated.')
                    ->success()
                    ->send();
            });
    }

    public function disableAction(): Action
    {
        return Action::make('disable')
            ->label('Disable two-factor authentication')
            ->color('danger')
            ->icon('heroicon-m-shield-exclamation')
            ->visible(fn (): bool => (bool) $this->user()->two_factor_secret)
            ->requiresConfirmation()
            ->modalHeading('Disable two-factor authentication')
            ->modalDescription('Enter your password to confirm. Your second-factor secret and recovery codes will be wiped.')
            ->form([
                TextInput::make('password')
                    ->label('Password')
                    ->password()
                    ->required(),
            ])
            ->action(function (array $data): void {
                if (! Hash::check($data['password'] ?? '', (string) $this->user()->password)) {
                    Notification::make()
                        ->title('Incorrect password.')
                        ->danger()
                        ->send();

                    return;
                }

                app(DisableTwoFactorAuthentication::class)($this->user());

                $this->showRecoveryCodes = null;
                $this->confirmCode = '';

                Notification::make()
                    ->title('Two-factor authentication disabled.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Data exposed to the blade view.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = $this->user();

        $hasSecret = (bool) $user->two_factor_secret;
        $isConfirmed = (bool) $user->two_factor_confirmed_at;

        return [
            'user' => $user,
            'hasSecret' => $hasSecret,
            'isConfirmed' => $isConfirmed,
            'qrSvg' => $hasSecret ? $user->twoFactorQrCodeSvg() : null,
            'qrUrl' => $hasSecret ? $user->twoFactorQrCodeUrl() : null,
            'showRecoveryCodes' => $this->showRecoveryCodes,
        ];
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
