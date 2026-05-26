{{--
    RFQ §3.1.3 — Bulk Import v2 onboarding wizard.

    Five-step stepper that walks the operator from "empty tenant" to
    "fully populated tenant" in the right order. Each card shows:
      • status badge (done / pending / locked)
      • current row count + expected count (when known)
      • download-template button
      • open-importer deep-link (disabled with tooltip when locked)

    The whole logic — counts, prereqs, gating — lives on the page class
    (ImportWizard). This view is intentionally dumb: it reads `$states`
    and `$progress` and renders.
--}}
@php
    /** @var array $states */
    $states = \App\Filament\Pages\ImportWizard::stepStates();
    /** @var array $progress */
    $progress = \App\Filament\Pages\ImportWizard::progress();
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Progress header --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold text-gray-900 dark:text-white">
                    Setup progress
                </h2>
                <span class="text-sm text-gray-600 dark:text-gray-300">
                    {{ $progress['done'] }} / {{ $progress['total'] }} steps complete
                </span>
            </div>
            <div class="mt-3 h-2 w-full rounded-full bg-gray-100 dark:bg-gray-800 overflow-hidden">
                <div
                    class="h-full bg-primary-500 transition-all duration-500"
                    style="width: {{ $progress['percent'] }}%"
                    aria-valuenow="{{ $progress['percent'] }}"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    role="progressbar"
                ></div>
            </div>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                Follow the steps in order. Each download is a blank .xlsx
                whose column headers match the legacy sample files —
                fill it, save, and re-upload via "Open importer".
            </p>
        </div>

        {{-- Steps --}}
        <ol class="space-y-3">
            @foreach ($states as $idx => $step)
                @php
                    $stepNum = $idx + 1;
                    $countLabel = $step['expected'] !== null
                        ? $step['count'] . ' / ' . $step['expected'] . ' expected'
                        : $step['count'] . ' rows';
                @endphp
                <li class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4
                           flex flex-col md:flex-row md:items-center md:justify-between gap-3"
                    data-step-key="{{ $step['key'] }}"
                    data-step-done="{{ $step['done'] ? '1' : '0' }}"
                    data-step-unlocked="{{ $step['unlocked'] ? '1' : '0' }}">

                    <div class="flex items-start gap-3 min-w-0">
                        {{-- Status badge --}}
                        @if ($step['done'])
                            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full
                                         bg-success-100 text-success-700 dark:bg-success-900 dark:text-success-200"
                                  aria-label="Done">
                                <x-heroicon-s-check class="h-5 w-5" />
                            </span>
                        @elseif ($step['unlocked'])
                            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full
                                         bg-warning-100 text-warning-700 dark:bg-warning-900 dark:text-warning-200"
                                  aria-label="Pending">
                                <x-heroicon-s-clock class="h-5 w-5" />
                            </span>
                        @else
                            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full
                                         bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400"
                                  aria-label="Locked">
                                <x-heroicon-s-lock-closed class="h-5 w-5" />
                            </span>
                        @endif

                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">
                                Step {{ $stepNum }} — {{ $step['title'] }}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $countLabel }}
                                @if (! $step['unlocked'])
                                    · waiting for: {{ implode(', ', $step['missing']) }}
                                @endif
                            </p>
                        </div>
                    </div>

                    {{-- Action buttons --}}
                    <div class="flex items-center gap-2 shrink-0">
                        @if ($step['has_template'])
                            <button
                                type="button"
                                wire:click="downloadTemplate('{{ $step['key'] }}')"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300
                                       dark:border-gray-600 bg-white dark:bg-gray-800 px-3 py-1.5 text-sm
                                       text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700"
                            >
                                <x-heroicon-o-arrow-down-tray class="h-4 w-4" />
                                Download template
                            </button>
                        @endif

                        @if ($step['unlocked'])
                            <a
                                href="{{ \App\Filament\Pages\ImportWizard::importerUrl($step['resource']) }}"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-1.5
                                       text-sm font-medium text-white hover:bg-primary-700"
                            >
                                <x-heroicon-o-arrow-up-tray class="h-4 w-4" />
                                Open importer
                            </a>
                        @else
                            <button
                                type="button"
                                disabled
                                title="Complete steps {{ implode(', ', $step['missing']) }} first"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-gray-200 dark:bg-gray-800
                                       px-3 py-1.5 text-sm font-medium text-gray-500 dark:text-gray-400 cursor-not-allowed"
                            >
                                <x-heroicon-o-arrow-up-tray class="h-4 w-4" />
                                Open importer
                            </button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </div>
</x-filament-panels::page>
