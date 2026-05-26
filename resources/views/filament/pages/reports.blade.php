{{--
    RFQ §3.1.10 — Reports landing page.

    Renders a responsive card grid linking into the five canned reports.
    Card data comes from the page class via `getViewData()`:
      - $cards: list of (key, title, description, icon, url, count)
--}}
@php
    /** @var array<int, array{key:string,title:string,description:string,icon:string,url:string,count:string}> $cards */
    $cards = $cards ?? [];
@endphp

<x-filament-panels::page>
    <div class="space-y-4">
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-4">
            <h2 class="text-base font-semibold text-gray-900 dark:text-white">
                Canned reports
            </h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                Pre-built summaries of the archive. Each report can be viewed on screen, exported to CSV, or rendered as a print-friendly PDF.
            </p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach ($cards as $card)
                <a
                    href="{{ $card['url'] }}"
                    data-report-key="{{ $card['key'] }}"
                    class="block rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900
                           p-4 hover:border-primary-400 dark:hover:border-primary-500 transition-colors group"
                >
                    <div class="flex items-start gap-3">
                        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg
                                     bg-primary-50 text-primary-600 dark:bg-primary-900/40 dark:text-primary-300
                                     group-hover:bg-primary-100 dark:group-hover:bg-primary-900/60">
                            <x-dynamic-component :component="$card['icon']" class="h-5 w-5" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-900 dark:text-white">
                                {{ $card['title'] }}
                            </p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ $card['description'] }}
                            </p>
                            <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                                {{ $card['count'] }}
                            </p>
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
