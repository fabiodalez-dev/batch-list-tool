{{--
    RFQ §3.1.7 hardening — Two-Factor (TOTP) self-service page.

    Three visible states:
      1. Not enrolled       — only the "Enable" action is shown.
      2. Enrolled, unconfirmed — QR code + 6-digit input + "Confirm" action.
      3. Confirmed          — "Regenerate recovery codes" + "Disable" actions,
                              plus recovery codes panel when freshly generated.

    Data wired from {@see \App\Filament\Pages\TwoFactorProfile::getViewData()}:
      - $user, $hasSecret, $isConfirmed
      - $qrSvg, $qrUrl (only when $hasSecret)
      - $showRecoveryCodes (array<int,string>|null — present only the request
        that generated/regenerated them).
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
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
            <div class="flex items-start gap-3">
                <span @class([
                    'inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg',
                    'bg-emerald-50 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-300' => $isConfirmed,
                    'bg-amber-50 text-amber-600 dark:bg-amber-900/40 dark:text-amber-300' => $hasSecret && ! $isConfirmed,
                    'bg-gray-50 text-gray-500 dark:bg-gray-800 dark:text-gray-400' => ! $hasSecret,
                ])>
                    <x-filament::icon
                        icon="heroicon-o-shield-check"
                        class="h-5 w-5"
                    />
                </span>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">
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
        </div>

        {{-- State 1 / 3 — primary actions ----------------------------------- --}}
        <div class="flex flex-wrap items-center gap-2">
            {{ $this->enableAction }}
            {{ $this->confirmAction }}
            {{ $this->regenerateRecoveryCodesAction }}
            {{ $this->disableAction }}
        </div>

        {{-- State 2 — QR + confirmation ------------------------------------- --}}
        @if ($hasSecret && ! $isConfirmed && $qrSvg)
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4 space-y-4">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                        Step 1 &mdash; scan the QR code
                    </h3>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Open your authenticator app (1Password, Authy, Google Authenticator, Microsoft Authenticator, &hellip;)
                        and scan the code below. Can't scan? Use the setup URL underneath instead.
                    </p>
                </div>

                <div class="inline-block rounded-lg bg-white p-3 ring-1 ring-gray-200 dark:ring-gray-700">
                    {!! $qrSvg !!}
                </div>

                @if ($qrUrl)
                    <details class="text-xs text-gray-500 dark:text-gray-400">
                        <summary class="cursor-pointer select-none">Show setup URL (otpauth://&hellip;)</summary>
                        <code class="mt-2 block break-all rounded bg-gray-50 dark:bg-gray-800 p-2 text-[11px]">{{ $qrUrl }}</code>
                    </details>
                @endif

                <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">
                        Step 2 &mdash; enter the 6-digit code your app shows
                    </h3>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <input
                            type="text"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            pattern="[0-9]*"
                            maxlength="8"
                            wire:model.live="confirmCode"
                            placeholder="123 456"
                            class="block w-40 rounded-md border-gray-300 dark:border-gray-700
                                   bg-white dark:bg-gray-800 text-base font-mono tracking-widest
                                   shadow-sm focus:border-primary-400 focus:ring-primary-400"
                        >
                        {{ $this->confirmAction }}
                    </div>
                </div>
            </div>
        @endif

        {{-- Recovery codes panel — shown only the request they were generated --}}
        @if (! empty($showRecoveryCodes))
            <div class="rounded-xl border-2 border-amber-300 dark:border-amber-600 bg-amber-50 dark:bg-amber-900/30 p-4 space-y-3">
                <div class="flex items-start gap-3">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg
                                 bg-amber-100 text-amber-700 dark:bg-amber-900/60 dark:text-amber-200">
                        <x-filament::icon icon="heroicon-o-key" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-amber-900 dark:text-amber-100">
                            Save these recovery codes &mdash; this is the ONLY time they will be shown.
                        </p>
                        <p class="mt-1 text-xs text-amber-800 dark:text-amber-200">
                            Each code can be used once to sign in if you lose access to your authenticator app.
                            Store them in a password manager, on paper, or anywhere else only you can reach.
                            Regenerating recovery codes immediately invalidates the previous set.
                        </p>
                    </div>
                </div>

                <ul class="grid grid-cols-1 sm:grid-cols-2 gap-1 font-mono text-sm">
                    @foreach ($showRecoveryCodes as $code)
                        <li class="px-3 py-1.5 rounded bg-white dark:bg-gray-900 text-gray-900 dark:text-gray-100
                                   ring-1 ring-amber-200 dark:ring-amber-700 select-all">
                            {{ $code }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</x-filament-panels::page>
