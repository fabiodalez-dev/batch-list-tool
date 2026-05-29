{{--
    RFQ §3.1.10 — Reports landing page.

    Renders a responsive card grid linking into the five canned reports.
    Card data comes from the page class via `getViewData()`:
      - $cards: list of (key, title, description, icon, url, count)
--}}
@php
    /** @var array<int, array{key:string,title:string,description:string,icon:string,url:string,count:string}> $cards */
    $cards = $cards ?? [];
    /** @var array<int, array{id:int,name:string,description:?string,source_label:string,is_shared:bool,url:?string}> $templates */
    $templates = $templates ?? [];
@endphp

<x-filament-panels::page>
    <div class="space-y-4">
        <div>
            <h2 class="text-base font-semibold text-gray-950 dark:text-white">
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

        @if (count($templates) > 0)
            <div class="pt-2">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                    Saved templates
                </h2>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Saved filter and sort presets across the canned reports. Click to open a report restored to the bookmarked state.
                </p>
            </div>

            <ul class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach ($templates as $tpl)
                    <li>
                        <a
                            href="{{ $tpl['url'] ?? '#' }}"
                            data-template-id="{{ $tpl['id'] }}"
                            class="block rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900
                                   p-4 hover:border-primary-400 dark:hover:border-primary-500 transition-colors group
                                   @if ($tpl['url'] === null) opacity-60 pointer-events-none @endif"
                        >
                            <div class="flex items-start gap-3">
                                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg
                                             bg-primary-50 text-primary-600 dark:bg-primary-900/40 dark:text-primary-300
                                             group-hover:bg-primary-100 dark:group-hover:bg-primary-900/60">
                                    <x-dynamic-component component="heroicon-o-bookmark" class="h-5 w-5" />
                                </span>
                                <div class="min-w-0">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white truncate">
                                        {{ $tpl['name'] }}
                                        @if ($tpl['is_shared'])
                                            <span class="ml-1 inline-flex items-center rounded-full bg-primary-50 dark:bg-primary-900/40 px-2 py-0.5 text-[10px] font-medium text-primary-700 dark:text-primary-300">Shared</span>
                                        @endif
                                    </p>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400 truncate">
                                        {{ $tpl['source_label'] }}
                                    </p>
                                    @if ($tpl['description'])
                                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500 line-clamp-2">
                                            {{ $tpl['description'] }}
                                        </p>
                                    @endif
                                </div>
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-filament-panels::page>
