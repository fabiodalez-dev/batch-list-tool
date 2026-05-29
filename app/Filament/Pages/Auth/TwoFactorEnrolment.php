<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\View\View;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;

/**
 * RFQ §3.1.7 hardening — User-facing TOTP enrolment page.
 *
 * The TwoFactorAuthenticatable trait from Fortify already wires the
 * model columns (`two_factor_secret`, `two_factor_recovery_codes`,
 * `two_factor_confirmed_at`) and stock Actions (Enable, Confirm,
 * Disable). This Page exposes those actions through a Filament-native
 * UI so users can self-enrol — without it the only way to enable 2FA
 * was hitting Fortify's JSON endpoints by hand.
 *
 * Flow:
 *   1. User clicks "Enable 2FA" → EnableTwoFactorAuthentication action
 *      generates a secret, stashed encrypted on the user row. The
 *      `unconfirmed` state is rendered as a QR code (SVG, no external
 *      assets — bacon-qr-code generates inline SVG locally) + manual
 *      setup key.
 *   2. User scans the QR with their authenticator app, enters the
 *      6-digit code → ConfirmTwoFactorAuthentication validates the
 *      code, sets `two_factor_confirmed_at`, surfaces recovery codes.
 *   3. Once confirmed the TwoFactorLogin subclass enforces the
 *      challenge on every subsequent login.
 *   4. "Disable 2FA" wipes all three columns.
 *
 * Tier B RFQ-2026-06 #105.
 *
 * @property-read Schema $form
 */
class TwoFactorEnrolment extends Page
{
    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    protected string $view = 'filament.pages.auth.two-factor-enrolment';

    protected static string|\UnitEnum|null $navigationGroup = 'My account';

    protected static ?int $navigationSort = 20;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Set up two-factor';

    protected static ?string $title = 'Two-factor authentication';

    protected static ?string $slug = 'two-factor-enrolment';

    /**
     * Hidden from the sidebar: it would be a confusing second "two-factor"
     * entry next to TwoFactorProfile. The enrolment flow stays reachable by
     * route (from the profile / management page and the forced-2FA login flow).
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                TextInput::make('code')
                    ->label('Six-digit code from your authenticator app')
                    ->numeric()
                    ->minLength(6)
                    ->maxLength(6)
                    ->autocomplete('off')
                    ->visible(fn (): bool => $this->isEnabledButUnconfirmed()),
            ]);
    }

    public function confirm(ConfirmTwoFactorAuthentication $confirm): void
    {
        // ValidationException already propagates naturally to Livewire +
        // surfaces under the `data.code` form field — no try/catch needed.
        $confirm($this->user(), (string) ($this->data['code'] ?? ''));

        Notification::make()
            ->title('Two-factor authentication confirmed')
            ->body('Your account is protected. Save your recovery codes shown below — they are the only way back in if you lose your device.')
            ->success()
            ->send();
        $this->data = [];
    }

    public function user(): User
    {
        /** @var User $u */
        $u = auth()->user();

        return $u;
    }

    public function isEnabled(): bool
    {
        return $this->user()->two_factor_secret !== null;
    }

    public function isConfirmed(): bool
    {
        return $this->user()->two_factor_confirmed_at !== null;
    }

    public function isEnabledButUnconfirmed(): bool
    {
        return $this->isEnabled() && ! $this->isConfirmed();
    }

    /**
     * SVG QR code for the otpauth URI. Inline SVG, no external requests
     * — bacon-qr-code renders fully locally per the no-CDN rule.
     *
     * Wraps `decrypt()` in a try/catch so a corrupt or APP_KEY-mismatched
     * `two_factor_secret` does not crash the enrolment page with HTTP 500.
     * On failure the operator gets a clear nudge to disable + re-enable.
     */
    public function qrSvg(): ?View
    {
        if (! $this->isEnabledButUnconfirmed()) {
            return null;
        }

        try {
            $secret = decrypt($this->user()->two_factor_secret);
        } catch (DecryptException) {
            Notification::make()
                ->title('Could not decrypt 2FA secret')
                ->body('Disable 2FA and re-enable it to regenerate the secret. This usually means APP_KEY was rotated after enrolment.')
                ->danger()
                ->send();

            return null;
        }

        return view('filament.pages.auth.partials.two-factor-qr', [
            'svg' => $this->user()->twoFactorQrCodeSvg(),
            'secret' => $secret,
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function recoveryCodes(): array
    {
        if (! $this->isConfirmed()) {
            return [];
        }

        try {
            $decrypted = decrypt($this->user()->two_factor_recovery_codes);
        } catch (DecryptException) {
            return [];
        }

        $decoded = json_decode($decrypted, true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('enable')
                ->label('Enable 2FA')
                ->color('primary')
                ->icon('heroicon-o-lock-closed')
                ->visible(fn (): bool => ! $this->isEnabled())
                ->requiresConfirmation()
                ->modalDescription('Generate a TOTP secret and start the enrolment. You will be asked for a 6-digit code from your authenticator app to confirm.')
                ->action(function (EnableTwoFactorAuthentication $enable): void {
                    $enable($this->user());
                    Notification::make()
                        ->title('2FA enabled — please confirm with a code below')
                        ->success()
                        ->send();
                }),

            Action::make('disable')
                ->label('Disable 2FA')
                ->color('danger')
                ->icon('heroicon-o-lock-open')
                ->visible(fn (): bool => $this->isEnabled())
                ->requiresConfirmation()
                ->modalDescription('Disabling 2FA removes the TOTP secret and recovery codes. You will not be challenged for a code at next login.')
                ->action(function (DisableTwoFactorAuthentication $disable): void {
                    $disable($this->user());
                    Notification::make()
                        ->title('2FA disabled')
                        ->success()
                        ->send();
                }),
        ];
    }
}
