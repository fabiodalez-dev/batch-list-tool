{{--
    My account › Preferences page view.

    Renders the standard Filament panel page shell with the form defined in
    App\Filament\Pages\Account\PreferencesPage. The header action (Save) is
    mounted by Filament via getHeaderActions() and rendered automatically by
    <x-filament-panels::page>.
--}}
<x-filament-panels::page>
    <form wire:submit.prevent="save">
        {{ $this->form }}

        <div class="mt-6 flex items-center gap-3">
            <x-filament::button
                type="submit"
                color="primary"
                icon="heroicon-o-check"
            >
                {{ __('Save changes') }}
            </x-filament::button>
        </div>
    </form>

    <x-filament-actions::modals />
</x-filament-panels::page>
