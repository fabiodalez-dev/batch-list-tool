{{--
    A1 (Wave A) — Import Status page.

    Renders a table of recent Filament import records so operators can track
    whether queued jobs ran, how many rows were processed, and download any
    failed-rows CSVs.
--}}
@php
    /** @var array<int,array<string,mixed>> $imports */
    $imports ??= [];
@endphp

<x-filament-panels::page>

    @if (count($imports) === 0)
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-6 text-center text-sm text-gray-500 dark:text-gray-400">
            No import records found. Once you run an import via the
            <a href="{{ \App\Filament\Pages\ImportWizard::getUrl() }}" class="font-medium text-primary-600 hover:underline">Import Wizard</a>,
            its progress will appear here.
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-3">File</th>
                        <th class="px-4 py-3">Importer</th>
                        <th class="px-4 py-3 text-right">Processed&nbsp;/ Total</th>
                        <th class="px-4 py-3 text-right">OK</th>
                        <th class="px-4 py-3 text-right">Failed</th>
                        <th class="px-4 py-3">Completed</th>
                        <th class="px-4 py-3">Started by</th>
                        <th class="px-4 py-3">Download</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700 bg-white dark:bg-gray-900">
                    @foreach ($imports as $row)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/60 transition-colors">
                            {{-- File name --}}
                            <td class="px-4 py-3 font-medium text-gray-700 dark:text-gray-200 max-w-xs truncate">
                                {{ $row['file_name'] }}
                            </td>

                            {{-- Importer short name --}}
                            <td class="px-4 py-3 text-gray-500 dark:text-gray-400">
                                {{ $row['importer'] }}
                            </td>

                            {{-- Processed / Total --}}
                            <td class="px-4 py-3 text-right tabular-nums text-gray-700 dark:text-gray-300">
                                {{ number_format($row['processed_rows']) }}&nbsp;/&nbsp;{{ number_format($row['total_rows']) }}
                            </td>

                            {{-- Successful --}}
                            <td class="px-4 py-3 text-right tabular-nums">
                                <span class="text-green-700 dark:text-green-400 font-medium">
                                    {{ number_format($row['successful_rows']) }}
                                </span>
                            </td>

                            {{-- Failed --}}
                            <td class="px-4 py-3 text-right tabular-nums">
                                @if ($row['failed_rows'] > 0)
                                    <span class="text-red-600 dark:text-red-400 font-medium">
                                        {{ number_format($row['failed_rows']) }}
                                    </span>
                                @else
                                    <span class="text-gray-400">0</span>
                                @endif
                            </td>

                            {{-- Completed at --}}
                            <td class="px-4 py-3 text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                @if ($row['completed_at'])
                                    <span title="{{ \Carbon\Carbon::createFromTimestamp($row['completed_at'])->toDateTimeString() }}">
                                        {{ \Carbon\Carbon::createFromTimestamp($row['completed_at'])->diffForHumans() }}
                                    </span>
                                @elseif (!empty($row['is_stalled']))
                                    <span class="inline-flex items-center gap-1 text-red-600 dark:text-red-400"
                                          title="Pending for over 5 minutes. The queue worker is probably not running — start it with `php artisan queue:work`.">
                                        <x-heroicon-o-exclamation-triangle class="h-3.5 w-3.5" />
                                        Stalled — queue worker?
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 text-amber-600 dark:text-amber-400">
                                        <x-heroicon-o-clock class="h-3.5 w-3.5" />
                                        Pending
                                    </span>
                                @endif
                            </td>

                            {{-- Inputter --}}
                            <td class="px-4 py-3 text-gray-500 dark:text-gray-400">
                                {{ $row['inputter'] ?? '—' }}
                            </td>

                            {{-- Failed-rows download --}}
                            <td class="px-4 py-3">
                                @if ($row['failed_download'])
                                    <a href="{{ $row['failed_download'] }}"
                                       class="inline-flex items-center gap-1 text-xs font-medium text-primary-600 dark:text-primary-400 hover:underline"
                                       target="_blank"
                                       rel="noopener">
                                        <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" />
                                        Errors CSV
                                    </a>
                                @else
                                    <span class="text-gray-300 dark:text-gray-600">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

</x-filament-panels::page>
