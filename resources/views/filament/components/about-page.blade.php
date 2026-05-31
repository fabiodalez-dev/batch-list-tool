{{--
    "About this page" — a collapsible explanation card rendered as the page
    subheading by the ExplainsPage trait. Theme-aware (uses Filament's surface
    tokens, no hard-coded white/black). Collapsed by default so it never gets in
    the way of daily data entry; the operator expands it when they need context.

    @param string $body  Plain-text explanation; blank lines separate paragraphs.
    @param string $refs  Requirement references shown in the footer.
--}}
<details class="fi-section group/about mt-1 overflow-hidden rounded-xl bg-gray-50 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
    <summary class="flex cursor-pointer list-none items-center gap-2 px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/5">
        <x-heroicon-o-information-circle class="size-5 text-primary-500" />
        <span>About this page</span>
        <x-heroicon-m-chevron-down class="size-4 text-gray-400 transition group-open/about:rotate-180" />
    </summary>

    <div class="space-y-2 border-t border-gray-950/5 px-4 py-3 text-sm leading-relaxed text-gray-600 dark:border-white/10 dark:text-gray-300">
        @foreach (preg_split('/\n{2,}/', trim($body)) as $paragraph)
            <p>{!! nl2br(e(trim($paragraph))) !!}</p>
        @endforeach

        @if (filled($refs))
            <p class="pt-1 text-xs font-medium text-gray-400 dark:text-gray-500">
                {{ $refs }}
            </p>
        @endif
    </div>
</details>
