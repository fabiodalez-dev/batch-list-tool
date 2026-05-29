{{--
    RFQ §3.1.7 hardening — Two-Factor (TOTP) self-service page.

    Three visible states:
      1. Not enrolled       — only the "Enable" action is shown.
      2. Enrolled, unconfirmed — QR code + 6-digit input + "Confirm" action.
      3. Confirmed          — "Regenerate recovery codes" + "Disable" actions,
                              plus recovery codes panel when freshly generated.

    Surfaces use native <x-filament::section> (theme-aware) instead of hardcoded
    white/gray cards. The QR keeps a white plate (required for reliable scanning)
    and the recovery-codes panel keeps its amber warning treatment on purpose.

    Data wired from {@see \App\Filament\Pages\TwoFactorProfile::getViewData()}:
      - $user, $hasSecret, $isConfirmed
      - $qrSvg, $qrUrl (only when $hasSecret)
      - $showRecoveryCodes (array<int,string>|null).
--}}
@php
    /** @var \App\Models\User $user */
    /** @var bool $hasSecret */
    /** @var bool $isConfirmed */
    /** @var string|null $qrSvg */
    /** @var string|null $qrUrl */
    /** @var array<int,string>|null $showRecoveryCodes */
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Status callout -------------------------------------------------- --}}
        <x-filament::section compact>
            <div class="flex items-start gap-3">
                <span @class([
                    'inline-flex size-10 shrink-0 items-center justify-center rounded-lg',
                    'bg-success-50 text-success-600 dark:bg-success-400/10 dark:text-success-400' => $isConfirmed,
                    'bg-warning-50 text-warning-600 dark:bg-warning-400/10 dark:text-warning-400' => $hasSecret && ! $isConfirmed,
                    'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400' => ! $hasSecret,
                ])>
                    <x-filament::icon icon="heroicon-o-shield-check" class="size-5" />
                </span>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-950 dark:text-white">
                        @if ($isConfirmed)
                            Two-factor authentication is ENABLED.
                        @elseif ($hasSecret)
                            Enrolment in progress — finish by entering a 6-digit code.
                        @else
                            Two-factor authentication is not yet enabled.
                        @endif
                    </p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        @if ($isConfirmed)
                            From now on you will be asked for a 6-digit code from your authenticator app at every sign-in.
                            Keep your recovery codes somewhere safe — they are the only way back in if you lose your device.
                        @else
                            We strongly recommend enabling 2FA on any account that holds the <strong>admin</strong> or
                            <strong>super_admin</strong> role. It defends against credential theft and lateral movement
                            from a compromised workstation.
                        @endif
                    </p>
                </div>
            </div>
        </x-filament::section>

        {{-- State 1 / 3 — primary actions ----------------------------------- --}}
        <div class="flex flex-wrap items-center gap-2">
            {{ $this->enableAction }}
            {{ $this->confirmAction }}
            {{ $this->regenerateRecoveryCodesAction }}
            {{ $this->disableAction }}
        </div>

        {{-- State 2 — QR + confirmation ------------------------------------- --}}
        @if ($hasSecret && ! $isConfirmed && $qrSvg)
            <x-filament::section compact>
                <div class="space-y-4">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                            Step 1 &mdash; scan the QR code
                        </h3>
                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                            Open your authenticator app (1Password, Authy, Google Authenticator, Microsoft Authenticator, &hellip;)
                            and scan the code below. Can't scan? Use the setup URL underneath instead.
                        </p>
                    </div>

                    {{-- White plate is intentional: QR codes need a light background to scan. --}}
                    <div class="inline-block rounded-lg bg-white p-3 ring-1 ring-gray-950/10">
                        {!! $qrSvg !!}
                    </div>

                    @if ($qrUrl)
                        <details class="text-xs text-gray-500 dark:text-gray-400">
                            <summary class="cursor-pointer select-none">Show setup URL (otpauth://&hellip;)</summary>
                            <code class="mt-2 block break-all rounded bg-gray-500/10 p-2 text-[11px]">{{ $qrUrl }}</code>
                        </details>
                    @endif

                    <div class="border-t border-gray-200 pt-4 dark:border-white/10">
                        <h3 class="text-sm font-semibold text-gray-950 dark:text-white">
                            Step 2 &mdash; enter the 6-digit code your app shows
                        </h3>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <x-filament::input.wrapper class="w-40">
                                <x-filament::input
                                    type="text"
                                    inputmode="numeric"
                                    autocomplete="one-time-code"
                                    pattern="[0-9]*"
                                    maxlength="8"
                                    wire:model.live="confirmCode"
                                    placeholder="123 456"
                                    class="font-mono tracking-widest"
                                />
                            </x-filament::input.wrapper>
                            {{ $this->confirmAction }}
                        </div>
                    </div>
                </div>
            </x-filament::section>
        @endif

        {{-- Recovery codes panel — amber warning treatment is intentional --}}
        @if (! empty($showRecoveryCodes))
            <div class="space-y-3 rounded-xl bg-warning-50 p-4 ring-1 ring-warning-300 dark:bg-warning-400/10 dark:ring-warning-400/30">
                <div class="flex items-start gap-3">
                    <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg bg-warning-100 text-warning-700 dark:bg-warning-400/20 dark:text-warning-300">
                        <x-filament::icon icon="heroicon-o-key" class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-warning-900 dark:text-warning-100">
                            Save these recovery codes &mdash; this is the ONLY time they will be shown.
                        </p>
                        <p class="mt-1 text-xs text-warning-800 dark:text-warning-200">
                            Each code can be used once to sign in if you lose access to your authenticator app.
                            Store them in a password manager, on paper, or anywhere else only you can reach.
                            Regenerating recovery codes immediately invalidates the previous set.
                        </p>
                    </div>
                </div>

                <ul class="grid grid-cols-1 gap-1 font-mono text-sm sm:grid-cols-2">
                    @foreach ($showRecoveryCodes as $code)
                        <li class="select-all rounded bg-white px-3 py-1.5 text-gray-950 ring-1 ring-warning-200 dark:bg-gray-900 dark:text-gray-100 dark:ring-warning-400/20">
                            {{ $code }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</x-filament-panels::page>
