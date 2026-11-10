{{--
    Client 2026-11-10, Report 1 — RAS ↔ NRA reconciliation.

    Above the table: which RAS file is on screen (who uploaded it and when)
    and how many rows fall in each outcome. A tile filters the table to that
    outcome.
--}}
@php
    /** @var \App\Models\RasReconciliationRun|null $run */
    /** @var array<string,int>|null $summary */
    /** @var array<string,string> $outcomes */
    $active = $this->tableFilters['outcome']['values'] ?? [];
    $discrepanciesOnly = (bool) ($this->tableFilters['discrepancies_only']['isActive'] ?? true);
@endphp

<x-filament-panels::page>
    @if ($run === null)
        <x-filament::section>
            <p class="text-sm text-gray-700 dark:text-gray-300">
                No RAS report has been uploaded for this repository yet. Press <strong>Upload RAS report</strong> and choose the export RAS sends (Excel or CSV).
            </p>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                Read from it: barcode (Column 1), RAS reference ARK/box-batch (Column 4, e.g. ARK/054-4 = batch 4, box 54) and status In / Out / Perm Out (Column 6). A sheet with real headers (Barcode, Reference, Status) works too.
            </p>
        </x-filament::section>
    @else
        <x-filament::section>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="text-sm text-gray-700 dark:text-gray-300" data-ras-run>
                    <span class="font-medium text-gray-950 dark:text-white">{{ $run->original_filename }}</span>
                    — uploaded by {{ $run->user?->name ?? 'unknown user' }}
                    on {{ $run->created_at?->format('d-m-Y H:i') }}
                </p>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Last compared {{ $run->compared_at?->format('d-m-Y H:i') ?? '—' }}
                    · {{ number_format($run->ras_rows) }} RAS rows · {{ number_format($run->our_boxes) }} RAS boxes of ours
                </p>
            </div>

            {{-- Inline grid: as many tiles per line as fit, whatever the compiled theme holds. --}}
            <div class="mt-4" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(10.5rem, 1fr)); gap: 0.75rem;">
                @foreach ($outcomes as $key => $label)
                    @php
                        $isOn = $key === \App\Models\RasReconciliationRow::RECONCILED
                            ? ! $discrepanciesOnly
                            : in_array($key, $active, true);
                        $color = \App\Filament\Pages\Reports\RasReconciliationReport::outcomeColor($key);
                    @endphp
                    <button
                        type="button"
                        wire:click="showOutcome('{{ $key }}')"
                        data-ras-tile="{{ $key }}"
                        @class([
                            'rounded-xl border p-3 text-left transition-colors',
                            'border-primary-400 bg-primary-50 dark:border-primary-500 dark:bg-primary-900/40' => $isOn,
                            'border-gray-200 bg-white hover:border-primary-400 dark:border-gray-700 dark:bg-gray-900' => ! $isOn,
                        ])
                    >
                        <x-filament::badge :color="$color" size="sm">{{ $label }}</x-filament::badge>
                        <p class="mt-2 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">
                            {{ number_format($summary[$key] ?? 0) }}
                        </p>
                        @if ($key === \App\Models\RasReconciliationRow::STATUS_DIFFERS && ($summary['status_mismatch'] ?? 0) > ($summary[$key] ?? 0))
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ number_format($summary['status_mismatch']) }} including rows whose batch / box also differs
                            </p>
                        @endif
                    </button>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
