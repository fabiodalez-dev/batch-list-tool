{{--
    Backup & health page view.

    Renders the standard Filament panel page shell with:
      - A read-only health summary card (DB status, latest backup, disk space).
      - The retention settings form defined in BackupHealthPage.
    The header actions (Run backup now, Save retention) are mounted by Filament
    via getHeaderActions() and rendered automatically by <x-filament-panels::page>.
--}}
<x-filament-panels::page>

    {{-- ── Health summary ────────────────────────────────────────────────── --}}
    <div class="grid gap-4 sm:grid-cols-3">

        {{-- DB connection --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-center gap-2 mb-2">
                @if($this->health['db']['ok'] ?? false)
                    <x-heroicon-o-check-circle class="h-5 w-5 text-success-500" />
                    <span class="text-sm font-semibold text-success-600 dark:text-success-400">Database</span>
                @else
                    <x-heroicon-o-x-circle class="h-5 w-5 text-danger-500" />
                    <span class="text-sm font-semibold text-danger-600 dark:text-danger-400">Database</span>
                @endif
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ $this->health['db']['message'] ?? '—' }}
            </p>
        </div>

        {{-- Latest backup --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-center gap-2 mb-2">
                @if($this->health['backup']['ok'] ?? false)
                    <x-heroicon-o-check-circle class="h-5 w-5 text-success-500" />
                    <span class="text-sm font-semibold text-success-600 dark:text-success-400">Latest backup</span>
                @else
                    <x-heroicon-o-exclamation-circle class="h-5 w-5 text-warning-500" />
                    <span class="text-sm font-semibold text-warning-600 dark:text-warning-400">Latest backup</span>
                @endif
            </div>
            @if($this->health['backup']['ok'] ?? false)
                <p class="text-xs text-gray-700 dark:text-gray-300 font-medium truncate" title="{{ $this->health['backup']['file'] }}">
                    {{ $this->health['backup']['file'] ?? '—' }}
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    {{ $this->health['backup']['date'] ?? '—' }}
                    &middot;
                    {{ $this->health['backup']['size'] ?? '—' }}
                </p>
            @else
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $this->health['backup']['message'] ?? '—' }}
                </p>
            @endif
        </div>

        {{-- Disk free space --}}
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-center gap-2 mb-2">
                @if($this->health['disk']['ok'] ?? false)
                    <x-heroicon-o-circle-stack class="h-5 w-5 text-info-500" />
                    <span class="text-sm font-semibold text-info-600 dark:text-info-400">Storage disk</span>
                @else
                    <x-heroicon-o-x-circle class="h-5 w-5 text-danger-500" />
                    <span class="text-sm font-semibold text-danger-600 dark:text-danger-400">Storage disk</span>
                @endif
            </div>
            @if($this->health['disk']['ok'] ?? false)
                <p class="text-xs text-gray-700 dark:text-gray-300">
                    {{ $this->health['disk']['free'] ?? '—' }} free
                    of {{ $this->health['disk']['total'] ?? '—' }}
                </p>
                <div class="mt-2 h-1.5 w-full rounded-full bg-gray-200 dark:bg-gray-700">
                    <div
                        class="h-1.5 rounded-full {{ ($this->health['disk']['percent_used'] ?? 0) > 90 ? 'bg-danger-500' : (($this->health['disk']['percent_used'] ?? 0) > 70 ? 'bg-warning-500' : 'bg-success-500') }}"
                        style="width: {{ $this->health['disk']['percent_used'] ?? 0 }}%"
                    ></div>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    {{ $this->health['disk']['percent_used'] ?? 0 }}% used
                </p>
            @else
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $this->health['disk']['message'] ?? '—' }}
                </p>
            @endif
        </div>

    </div>

    {{-- ── Retention settings form ─────────────────────────────────────── --}}
    <form wire:submit.prevent="save">
        {{ $this->form }}

        <div class="mt-6 flex items-center gap-3">
            <x-filament::button
                type="submit"
                color="primary"
                icon="heroicon-o-check"
            >
                {{ __('Save retention') }}
            </x-filament::button>
        </div>
    </form>

    <x-filament-actions::modals />
</x-filament-panels::page>
