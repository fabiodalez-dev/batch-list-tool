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

        {{-- Local storage (server disk where backups are written) --}}
        @php
            $diskStatus = $this->health['disk']['status'] ?? 'ok';
            $diskColour = match ($diskStatus) {
                'danger' => 'danger',
                'warning' => 'warning',
                default => 'success',
            };
        @endphp
        <x-filament::section compact>
            <div class="mb-2 flex items-center gap-2">
                @if($this->health['disk']['ok'] ?? false)
                    <x-heroicon-o-circle-stack class="size-5 text-{{ $diskColour }}-500" />
                    <span class="text-sm font-semibold text-{{ $diskColour }}-600 dark:text-{{ $diskColour }}-400">Local storage</span>
                @else
                    <x-heroicon-o-x-circle class="size-5 text-danger-500" />
                    <span class="text-sm font-semibold text-danger-600 dark:text-danger-400">Local storage</span>
                @endif
            </div>
            @if($this->health['disk']['ok'] ?? false)
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Server disk where backups are written
                </p>
                <p class="mt-1 text-xs text-gray-700 dark:text-gray-300">
                    {{ $this->health['disk']['free'] ?? '—' }} free
                    of {{ $this->health['disk']['total'] ?? '—' }}
                </p>
                <div class="mt-2 h-1.5 w-full rounded-full bg-gray-500/10">
                    <div
                        class="h-1.5 rounded-full bg-{{ $diskColour }}-500"
                        style="width: {{ $this->health['disk']['percent_used'] ?? 0 }}%"
                    ></div>
                </div>
                @php
                    $diskLabel = match ($diskStatus) {
                        'warning' => '(getting full)',
                        'danger' => '(low space)',
                        default => '(healthy)',
                    };
                @endphp
                <p class="mt-1 text-xs text-{{ $diskColour }}-600 dark:text-{{ $diskColour }}-400">
                    {{ $this->health['disk']['percent_used'] ?? 0 }}% used {{ $diskLabel }}
                </p>
            @else
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $this->health['disk']['message'] ?? '—' }}
                </p>
            @endif
        </x-filament::section>

    </div>

    {{-- ── Backup archives ──────────────────────────────────────────────── --}}
    @php($backups = $this->listBackups())
    <x-filament::section>
        <x-slot name="heading">Backup archives</x-slot>
        <x-slot name="description">Existing <code>.zip</code> backups across all configured destination disks.</x-slot>

        @if(count($backups) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">No backup archives found yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-4 font-medium">File</th>
                            <th class="py-2 pr-4 font-medium">Disk</th>
                            <th class="py-2 pr-4 font-medium">Size</th>
                            <th class="py-2 pr-4 font-medium">Date</th>
                            <th class="py-2 pr-4 font-medium text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach($backups as $backup)
                            <tr>
                                <td class="py-2 pr-4 font-medium text-gray-700 dark:text-gray-200" title="{{ $backup['path'] }}">
                                    {{ $backup['filename'] }}
                                </td>
                                <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $backup['disk'] }}</td>
                                <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $backup['size'] }}</td>
                                <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $backup['date'] }}</td>
                                <td class="py-2 pr-4">
                                    <div class="flex items-center justify-end gap-3">
                                        <a
                                            href="{{ route('backups.download', ['disk' => $backup['disk'], 'path' => $backup['path']]) }}"
                                            class="inline-flex items-center gap-1 text-primary-600 hover:underline dark:text-primary-400"
                                        >
                                            <x-heroicon-o-arrow-down-tray class="size-4" />
                                            Download
                                        </a>
                                        @php($canRestore = method_exists($this, 'getRestoreAction') && (bool) auth()->user()?->hasRole('super_admin'))
                                        @if($canRestore)
                                            <button
                                                type="button"
                                                wire:click="mountAction('restore', {{ \Illuminate\Support\Js::from(['disk' => $backup['disk'], 'path' => $backup['path']]) }})"
                                                class="inline-flex items-center gap-1 text-danger-600 hover:underline dark:text-danger-400"
                                            >
                                                <x-heroicon-o-arrow-uturn-left class="size-4" />
                                                Restore
                                            </button>
                                        @endif
                                        <button
                                            type="button"
                                            wire:click="deleteBackup(@js($backup['disk']), @js($backup['path']))"
                                            wire:confirm="Permanently delete {{ $backup['filename'] }}? This cannot be undone."
                                            class="inline-flex items-center gap-1 text-danger-600 hover:underline dark:text-danger-400"
                                        >
                                            <x-heroicon-o-trash class="size-4" />
                                            Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    {{-- ── Run history ──────────────────────────────────────────────────── --}}
    @php($runs = $this->recentRuns())
    <x-filament::section>
        <x-slot name="heading">Run history</x-slot>
        <x-slot name="description">The most recent backup runs (queued, completed or failed).</x-slot>

        @if($runs->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">No backup runs recorded yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-left text-xs uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="py-2 pr-4 font-medium">Type</th>
                            <th class="py-2 pr-4 font-medium">Status</th>
                            <th class="py-2 pr-4 font-medium">Started</th>
                            <th class="py-2 pr-4 font-medium">Finished</th>
                            <th class="py-2 pr-4 font-medium">Duration</th>
                            <th class="py-2 pr-4 font-medium">Size</th>
                            <th class="py-2 pr-4 font-medium">Message</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach($runs as $run)
                            @php($statusColour = ['completed' => 'success', 'success' => 'success', 'failed' => 'danger', 'error' => 'danger', 'running' => 'warning'][$run->status] ?? 'gray')
                            <tr>
                                <td class="py-2 pr-4 text-gray-700 dark:text-gray-200">
                                    <span class="uppercase text-xs font-semibold tracking-wide">{{ $run->type }}</span>
                                </td>
                                <td class="py-2 pr-4">
                                    <x-filament::badge :color="$statusColour">{{ ucfirst((string) $run->status) }}</x-filament::badge>
                                </td>
                                <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">
                                    {{ $run->started_at?->toDateTimeString() ?? '—' }}
                                </td>
                                <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">
                                    {{ $run->finished_at?->toDateTimeString() ?? '—' }}
                                </td>
                                <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">
                                    {{ $run->duration_seconds !== null ? $run->duration_seconds . 's' : '—' }}
                                </td>
                                <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">
                                    {{ $run->size_bytes !== null ? round($run->size_bytes / 1048576, 2) . ' MB' : '—' }}
                                </td>
                                <td class="py-2 pr-4 text-gray-500 dark:text-gray-400 max-w-xs truncate" title="{{ $run->message }}">
                                    {{ $run->message ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

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
