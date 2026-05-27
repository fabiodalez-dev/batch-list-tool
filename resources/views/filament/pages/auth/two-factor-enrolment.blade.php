<x-filament-panels::page>
    @if ($this->isConfirmed())
        <x-filament::section>
            <x-slot name="heading">Two-factor authentication is active</x-slot>
            <x-slot name="description">A 6-digit TOTP code from your authenticator app is required at every sign-in. To turn this off use the "Disable 2FA" header action above.</x-slot>

            <div class="prose dark:prose-invert max-w-none">
                <p class="text-sm text-gray-500 dark:text-gray-400">Recovery codes — store these somewhere safe (password manager, printed in a locked drawer). Each code is single-use; you can regenerate the set by disabling and re-enabling 2FA.</p>
                <ul class="font-mono text-sm">
                    @foreach ($this->recoveryCodes() as $code)
                        <li>{{ $code }}</li>
                    @endforeach
                </ul>
            </div>
        </x-filament::section>
    @elseif ($this->isEnabledButUnconfirmed())
        <x-filament::section>
            <x-slot name="heading">Scan this QR with your authenticator app</x-slot>
            <x-slot name="description">Use Google Authenticator, 1Password, Bitwarden, Aegis, or any RFC 6238 TOTP app. After scanning, enter the 6-digit code below to confirm.</x-slot>

            {{ $this->qrSvg() }}

            <form wire:submit="confirm" class="mt-4 space-y-4">
                {{ $this->form }}

                <x-filament::button type="submit">
                    Confirm code
                </x-filament::button>
            </form>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Add a second factor to your account</x-slot>
            <x-slot name="description">A TOTP code from your phone is the cheapest way to make a stolen password worthless. You can disable it any time. Click "Enable 2FA" above to start.</x-slot>
        </x-filament::section>
    @endif
</x-filament-panels::page>
