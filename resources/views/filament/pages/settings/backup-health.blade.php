{{--
    Backup & health page view.

    Read-only health summary (DB status, latest backup, disk space) rendered as
    native Filament sections (theme-aware surface/border, not hardcoded
    white/gray), followed by the retention settings form. Header actions
    (Run backup now) are mounted via getHeaderActions().
--}}
<x-filament-panels::page>

    {{-- ── Health summary ────────────────────────────────────────────────── --}}
    <div class="grid gap-4 sm:grid-cols-3">

        {{-- DB connection --}}
        <x-filament::section compact>
            <div class="mb-2 flex items-center gap-2">
                @if($this->health['db']['ok'] ?? false)
                    <x-heroicon-o-check-circle class="size-5 text-success-500" />
                    <span class="text-sm font-semibold text-success-600 dark:text-success-400">Database</span>
                @else
                    <x-heroicon-o-x-circle class="size-5 text-danger-500" />
                    <span class="text-sm font-semibold text-danger-600 dark:text-danger-400">Database</span>
                @endif
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                {{ $this->health['db']['message'] ?? '—' }}
            </p>
        </x-filament::section>

        {{-- Latest backup --}}
        <x-filament::section compact>
            <div class="mb-2 flex items-center gap-2">
                @if($this->health['backup']['ok'] ?? false)
                    <x-heroicon-o-check-circle class="size-5 text-success-500" />
                    <span class="text-sm font-semibold text-success-600 dark:text-success-400">Latest backup</span>
                @else
                    <x-heroicon-o-exclamation-circle class="size-5 text-warning-500" />
                    <span class="text-sm font-semibold text-warning-600 dark:text-warning-400">Latest backup</span>
                @endif
            </div>
            @if($this->health['backup']['ok'] ?? false)
                <p class="truncate text-xs font-medium text-gray-700 dark:text-gray-300" title="{{ $this->health['backup']['file'] }}">
                    {{ $this->health['backup']['file'] ?? '—' }}
                </p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ $this->health['backup']['date'] ?? '—' }}
                    &middot;
                    {{ $this->health['backup']['size'] ?? '—' }}
                </p>
            @else
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $this->health['backup']['message'] ?? '—' }}
                </p>
            @endif
        </x-filament::section>

        {{-- Disk free space --}}
        <x-filament::section compact>
            <div class="mb-2 flex items-center gap-2">
                @if($this->health['disk']['ok'] ?? false)
                    <x-heroicon-o-circle-stack class="size-5 text-info-500" />
                    <span class="text-sm font-semibold text-info-600 dark:text-info-400">Storage disk</span>
                @else
                    <x-heroicon-o-x-circle class="size-5 text-danger-500" />
                    <span class="text-sm font-semibold text-danger-600 dark:text-danger-400">Storage disk</span>
                @endif
            </div>
            @if($this->health['disk']['ok'] ?? false)
                <p class="text-xs text-gray-700 dark:text-gray-300">
                    {{ $this->health['disk']['free'] ?? '—' }} free
                    of {{ $this->health['disk']['total'] ?? '—' }}
                </p>
                <div class="mt-2 h-1.5 w-full rounded-full bg-gray-500/10">
                    <div
                        class="h-1.5 rounded-full {{ ($this->health['disk']['percent_used'] ?? 0) > 90 ? 'bg-danger-500' : (($this->health['disk']['percent_used'] ?? 0) > 70 ? 'bg-warning-500' : 'bg-success-500') }}"
                        style="width: {{ $this->health['disk']['percent_used'] ?? 0 }}%"
                    ></div>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ $this->health['disk']['percent_used'] ?? 0 }}% used
                </p>
            @else
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $this->health['disk']['message'] ?? '—' }}
                </p>
            @endif
        </x-filament::section>

    </div>

    {{-- ── Retention settings form ─────────────────────────────────────── --}}
    <form wire:submit.prevent="save">
        {{ $this->form }}

        <div class="mt-6 flex items-center gap-3">
            <x-filament::button type="submit" color="primary" icon="heroicon-o-check">
                {{ __('Save retention') }}
            </x-filament::button>
        </div>
    </form>

    <x-filament-actions::modals />
</x-filament-panels::page>
