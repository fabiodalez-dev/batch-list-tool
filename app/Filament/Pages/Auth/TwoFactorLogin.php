<?php

declare(strict_types=1);

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

/**
 * RFQ §3.1.7 hardening — Filament login subclass that enforces:
 *
 *   - A server-rendered math CAPTCHA at form render time (no CDN; no JS;
 *     answer stored in the session and verified at submit). RFQ-2026-06
 *     Tier B #106: prevents credential-stuffing bots from brute-forcing
 *     the login form without needing a third-party reCAPTCHA service.
 *   - A TOTP second-factor challenge for users who have completed 2FA
 *     enrolment (delegated to Fortify's `/two-factor-challenge`).
 *
 * The CAPTCHA is intentionally simple (two single-digit additions) — its
 * job is to break naive scripted clients, not to defeat a determined
 * attacker. Defence-in-depth: rate limiting on the route is the real
 * brute-force guard; this just raises the bar for drive-by automation.
 */
class TwoFactorLogin extends BaseLogin
{
    /**
     * Session key holding the expected CAPTCHA answer for the currently
     * rendered form. Refreshed on every GET (form mount) and consumed on
     * the POST (authenticate). Namespaced under our own prefix so it
     * cannot collide with any framework session entry.
     */
    private const CAPTCHA_SESSION_KEY = 'bl_login_captcha_answer';

    private const CAPTCHA_QUESTION_KEY = 'bl_login_captcha_question';

    public function mount(): void
    {
        parent::mount();
        $this->refreshCaptcha();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getCaptchaFormComponent(),
                $this->getRememberFormComponent(),
            ]);
    }

    public function authenticate(): ?LoginResponse
    {
        $this->validateCaptcha();

        $response = parent::authenticate();

        $this->challengeTwoFactorIfEnrolled();

        return $response;
    }

    protected function getCaptchaFormComponent(): TextInput
    {
        $question = (string) session(self::CAPTCHA_QUESTION_KEY, 'CAPTCHA');

        return TextInput::make('captcha_answer')
            ->label("Security check: {$question}")
            ->helperText('Type the answer as a digit to prove you are human.')
            ->required()
            ->numeric()
            ->maxLength(3)
            ->autocomplete('off');
    }

    protected function validateCaptcha(): void
    {
        $expected = session(self::CAPTCHA_SESSION_KEY);
        $given = trim((string) ($this->data['captcha_answer'] ?? ''));

        // Always rotate the CAPTCHA after a submission attempt — successful
        // or not — so the same answer can never be replayed.
        $matched = $expected !== null && (string) $expected === $given;
        $this->refreshCaptcha();

        if (! $matched) {
            throw ValidationException::withMessages([
                'data.captcha_answer' => 'Wrong answer — try the new question above.',
            ]);
        }
    }

    protected function refreshCaptcha(): void
    {
        $a = random_int(1, 9);
        $b = random_int(1, 9);
        session([
            self::CAPTCHA_QUESTION_KEY => "What is {$a} + {$b}?",
            self::CAPTCHA_SESSION_KEY => (string) ($a + $b),
        ]);
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
