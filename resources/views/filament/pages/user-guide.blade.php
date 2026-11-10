{{--
    Help → User guide. Left: the contents (parts → articles → the sections of
    the open article), sticky on wide screens and folded into a <details> on
    phones. Right: one article, or the overview, or the search results.

    Data from {@see \App\Filament\Pages\UserGuide::getViewData()}. The article
    HTML is rendered from our own Markdown by {@see \App\Support\Manual\UserManual}
    with raw HTML escaped and unsafe links dropped.
--}}
@php
    use App\Filament\Pages\UserGuide;

    /** @var string $manualTitle */
    /** @var list<string> $introduction */
    /** @var list<array{title: string, intro: string, articles: list<array<string, mixed>>}> $parts */
    /** @var array<string, mixed>|null $current */
    /** @var array<string, mixed>|null $previous */
    /** @var array<string, mixed>|null $next */
    /** @var string $searchTerm */
    /** @var list<array<string, mixed>> $results */

    $hitSlugs = collect($results)->pluck('slug')->unique()->all();
    $hitAnchors = collect($results)->pluck('anchor')->filter()->all();
    $searching = $searchTerm !== '';
@endphp

<x-filament-panels::page>
    <div class="nra-manual">
        {{-- Contents -------------------------------------------------------- --}}
        @php
            $contents = function (string $idSuffix) use ($parts, $current, $searching, $hitSlugs, $hitAnchors) {
                return view('filament.pages.partials.user-guide-contents', compact('parts', 'current', 'searching', 'hitSlugs', 'hitAnchors', 'idSuffix'));
            };
        @endphp

        <aside class="nra-manual-sidebar" aria-label="User guide contents">
            <div class="nra-manual-search">
                <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                    <x-filament::input
                        type="search"
                        wire:model.live.debounce.350ms="search"
                        placeholder="Search the guide"
                        aria-label="Search the user guide"
                        autocomplete="off"
                    />
                </x-filament::input.wrapper>
            </div>

            <details class="nra-manual-toc-mobile" @if ($searching) open @endif>
                <summary>
                    <span>{{ $current['shortTitle'] ?? 'Contents' }}</span>
                    <x-heroicon-m-chevron-down class="nra-manual-toc-chevron" />
                </summary>
                {!! $contents('mobile') !!}
            </details>

            <div class="nra-manual-toc-desktop">
                {!! $contents('desktop') !!}
            </div>
        </aside>

        {{-- Main column ------------------------------------------------------ --}}
        <div class="nra-manual-main">
            @if ($searching)
                <header class="nra-manual-header">
                    <p class="nra-manual-eyebrow">Search</p>
                    <h1 class="nra-manual-title">Results for “{{ $searchTerm }}”</h1>
                    <p class="nra-manual-lead">
                        @if (count($results) === 0)
                            Nothing in the guide matches these words. Try fewer or shorter words.
                        @else
                            {{ count($results) }} {{ \Illuminate\Support\Str::plural('section', count($results)) }} of the guide {{ count($results) === 1 ? 'matches' : 'match' }}.
                        @endif
                    </p>
                </header>

                <ol class="nra-manual-results">
                    @foreach ($results as $hit)
                        <li wire:key="hit-{{ $hit['slug'] }}-{{ $hit['anchor'] ?? 'top' }}">
                            <a href="{{ UserGuide::articleUrl($hit['slug'], $hit['anchor']) }}">
                                <span class="nra-manual-result-path">{{ $hit['articleTitle'] }}</span>
                                <span class="nra-manual-result-title">{{ $hit['section'] }}</span>
                                <span class="nra-manual-result-snippet">{{ $hit['snippet'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ol>
            @elseif ($current)
                <article class="nra-manual-article" wire:key="article-{{ $current['slug'] }}">
                    <header class="nra-manual-header">
                        @if ($current['part'] !== '')
                            <p class="nra-manual-eyebrow">{{ $current['part'] }}</p>
                        @endif
                        <h1 class="nra-manual-title">{{ $current['title'] }}</h1>
                        @if ($current['lead'] !== '')
                            <p class="nra-manual-lead">{!! $current['lead'] !!}</p>
                        @endif
                    </header>

                    <div class="nra-manual-prose">
                        {!! $current['html'] !!}
                    </div>
                </article>

                <nav class="nra-manual-pager" aria-label="Previous and next article">
                    @if ($previous)
                        <a href="{{ UserGuide::articleUrl($previous['slug']) }}" class="nra-manual-pager-link" rel="prev">
                            <span class="nra-manual-pager-label">
                                <x-heroicon-m-arrow-left class="nra-manual-pager-icon" /> Previous
                            </span>
                            <span class="nra-manual-pager-title">{{ $previous['shortTitle'] }}</span>
                        </a>
                    @else
                        <a href="{{ UserGuide::articleUrl() }}" class="nra-manual-pager-link" rel="prev">
                            <span class="nra-manual-pager-label">
                                <x-heroicon-m-arrow-left class="nra-manual-pager-icon" /> Previous
                            </span>
                            <span class="nra-manual-pager-title">Overview</span>
                        </a>
                    @endif

                    @if ($next)
                        <a href="{{ UserGuide::articleUrl($next['slug']) }}" class="nra-manual-pager-link nra-manual-pager-next" rel="next">
                            <span class="nra-manual-pager-label">
                                Next <x-heroicon-m-arrow-right class="nra-manual-pager-icon" />
                            </span>
                            <span class="nra-manual-pager-title">{{ $next['shortTitle'] }}</span>
                        </a>
                    @endif
                </nav>
            @else
                <article class="nra-manual-article">
                    <header class="nra-manual-header">
                        <p class="nra-manual-eyebrow">User guide</p>
                        <h1 class="nra-manual-title">{{ $manualTitle }}</h1>
                        @if ($introduction !== [])
                            <p class="nra-manual-lead">{{ $introduction[0] }}</p>
                        @endif
                    </header>

                    <div class="nra-manual-prose">
                        @foreach (array_slice($introduction, 1) as $paragraph)
                            <p>{{ $paragraph }}</p>
                        @endforeach

                        @foreach ($parts as $part)
                            <h2 id="part-{{ $loop->iteration }}">{{ $part['title'] }}</h2>
                            @if ($part['intro'] !== '')
                                <p>{{ $part['intro'] }}</p>
                            @endif
                            <ul class="nra-manual-cards">
                                @foreach ($part['articles'] as $article)
                                    <li>
                                        <a href="{{ UserGuide::articleUrl($article['slug']) }}">
                                            <span class="nra-manual-card-title">{{ $article['shortTitle'] }}</span>
                                            <span class="nra-manual-card-lead">{{ \Illuminate\Support\Str::limit(strip_tags($article['lead']), 150) }}</span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endforeach
                    </div>
                </article>

                @if ($next)
                    <nav class="nra-manual-pager" aria-label="Start reading">
                        <span></span>
                        <a href="{{ UserGuide::articleUrl($next['slug']) }}" class="nra-manual-pager-link nra-manual-pager-next" rel="next">
                            <span class="nra-manual-pager-label">
                                Start here <x-heroicon-m-arrow-right class="nra-manual-pager-icon" />
                            </span>
                            <span class="nra-manual-pager-title">{{ $next['shortTitle'] }}</span>
                        </a>
                    </nav>
                @endif
            @endif
        </div>
    </div>

    @if ($current)
        @script
            <script>
                // Highlights, in the contents, the section being read.
                const root = $wire.$el
                const headings = [...root.querySelectorAll('.nra-manual-prose h2[id]')]
                const links = [...root.querySelectorAll('.nra-manual-toc-desktop a[data-section]')]

                if (headings.length && links.length && 'IntersectionObserver' in window) {
                    const mark = (id) => links.forEach((a) => a.toggleAttribute('aria-current', a.dataset.section === id))
                    const visible = new Set()
                    const observer = new IntersectionObserver((entries) => {
                        entries.forEach((e) => e.isIntersecting ? visible.add(e.target.id) : visible.delete(e.target.id))
                        const first = headings.find((h) => visible.has(h.id))
                        if (first) mark(first.id)
                    }, { rootMargin: '-80px 0px -60% 0px' })

                    headings.forEach((h) => observer.observe(h))
                }
            </script>
        @endscript
    @endif
</x-filament-panels::page>
