{{--
    The user guide contents: parts → articles; the open article lists its
    sections underneath. While searching, only the articles and sections with
    a match are listed. Rendered twice by user-guide.blade.php (phone
    <details>, desktop sticky column), hence $idSuffix.
--}}
@php
    use App\Filament\Pages\UserGuide;
@endphp

<nav class="nra-manual-toc">
    <a
        href="{{ UserGuide::articleUrl() }}"
        class="nra-manual-toc-article"
        @if (! $current && ! $searching) aria-current="page" @endif
    >Overview</a>

    @foreach ($parts as $part)
        @php
            $articles = $searching
                ? array_values(array_filter($part['articles'], fn ($a) => in_array($a['slug'], $hitSlugs, true)))
                : $part['articles'];
        @endphp

        @continue($articles === [])

        <p class="nra-manual-toc-part" id="toc-part-{{ $loop->iteration }}-{{ $idSuffix }}">{{ $part['title'] }}</p>

        <ul aria-labelledby="toc-part-{{ $loop->iteration }}-{{ $idSuffix }}">
            @foreach ($articles as $article)
                @php
                    $isCurrent = $current && $current['slug'] === $article['slug'];
                    $sections = $searching
                        ? array_values(array_filter($article['headings'], fn ($h) => in_array($h['id'], $hitAnchors, true)))
                        : ($isCurrent ? $article['headings'] : []);
                @endphp
                <li>
                    <a
                        href="{{ UserGuide::articleUrl($article['slug']) }}"
                        class="nra-manual-toc-article"
                        @if ($isCurrent && ! $searching) aria-current="page" @endif
                    >{{ $article['shortTitle'] }}</a>

                    @if ($sections !== [])
                        <ul class="nra-manual-toc-sections">
                            @foreach ($sections as $heading)
                                <li>
                                    <a
                                        href="{{ $isCurrent && ! $searching ? '#' . $heading['id'] : UserGuide::articleUrl($article['slug'], $heading['id']) }}"
                                        data-section="{{ $heading['id'] }}"
                                    >
                                        @if ($heading['number'] !== null)
                                            <span class="nra-manual-toc-number">{{ $heading['number'] }}</span>
                                        @endif
                                        <span>{{ $heading['title'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </li>
            @endforeach
        </ul>
    @endforeach
</nav>
