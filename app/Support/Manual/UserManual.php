<?php

declare(strict_types=1);

namespace App\Support\Manual;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;

/**
 * The user manual shown inside the tool (Help → User guide).
 *
 * The articles are written in nra/manual (the source the DOCX and PDF are
 * built from) and copied into the repository by `php artisan manual:sync`:
 *
 *   resources/manual/manifest.json        parts, article order, introduction
 *   resources/manual/articles/<slug>.md   one article per file
 *   public/manual/images/<slug>/*.png     the screenshots of each article
 *
 * Only slugs listed in the manifest are ever read from disk, so a slug that
 * comes from a URL can never name another file.
 *
 * @phpstan-type Heading array{id: string, number: string|null, title: string}
 * @phpstan-type Article array{slug: string, title: string, shortTitle: string, lead: string, html: string, headings: list<Heading>, part: string}
 * @phpstan-type Part array{title: string, intro: string, articles: list<string>}
 * @phpstan-type SearchHit array{slug: string, articleTitle: string, anchor: string|null, section: string, snippet: string}
 */
final class UserManual
{
    /** Bump to throw away every cached render after a change to the renderer. */
    private const RENDER_VERSION = 'v1';

    private const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    /** @var array<string, Article> */
    private array $articles = [];

    public static function resourcesPath(string $path = ''): string
    {
        $base = (string) config('manual.resources_path', resource_path('manual'));

        return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . $path;
    }

    public static function imagesPath(string $path = ''): string
    {
        $base = (string) config('manual.images_path', public_path('manual/images'));

        return $path === '' ? $base : $base . DIRECTORY_SEPARATOR . $path;
    }

    public static function isValidSlug(string $slug): bool
    {
        return preg_match(self::SLUG_PATTERN, $slug) === 1;
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $file = self::resourcesPath('manifest.json');
        $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return $this->manifest = is_array($decoded) ? $decoded : [];
    }

    public function title(): string
    {
        $title = $this->manifest()['title'] ?? 'User guide';

        return is_string($title) ? $title : 'User guide';
    }

    /** @return list<string> */
    public function introduction(): array
    {
        $intro = $this->manifest()['introduction'] ?? [];

        return is_array($intro) ? array_values(array_filter($intro, 'is_string')) : [];
    }

    /** @return list<Part> */
    public function parts(): array
    {
        $parts = [];

        foreach ((array) ($this->manifest()['parts'] ?? []) as $part) {
            if (! is_array($part)) {
                continue;
            }

            $slugs = array_values(array_filter(
                (array) ($part['articles'] ?? []),
                fn ($slug): bool => is_string($slug)
                    && self::isValidSlug($slug)
                    && is_file(self::resourcesPath('articles/' . $slug . '.md')),
            ));

            $parts[] = [
                'title' => is_string($part['title'] ?? null) ? $part['title'] : '',
                'intro' => is_string($part['intro'] ?? null) ? $part['intro'] : '',
                'articles' => $slugs,
            ];
        }

        return $parts;
    }

    /** @return list<string> Every article slug, in manual order. */
    public function slugs(): array
    {
        return array_values(array_unique(array_merge([], ...array_map(
            fn (array $part): array => $part['articles'],
            $this->parts(),
        ))));
    }

    public function has(string $slug): bool
    {
        return in_array($slug, $this->slugs(), true);
    }

    /** @return Article|null */
    public function article(string $slug): ?array
    {
        if (! $this->has($slug)) {
            return null;
        }

        if (isset($this->articles[$slug])) {
            return $this->articles[$slug];
        }

        $file = self::resourcesPath('articles/' . $slug . '.md');
        $key = 'user-manual:' . self::RENDER_VERSION . ':' . $slug . ':' . filemtime($file) . ':' . filesize($file) . ':' . md5(asset('manual/images'));

        /** @var array{title: string, lead: string, html: string, headings: list<Heading>} $rendered */
        $rendered = Cache::rememberForever($key, fn (): array => $this->render($slug, (string) file_get_contents($file)));

        return $this->articles[$slug] = [
            'slug' => $slug,
            'title' => $rendered['title'],
            'shortTitle' => self::shortTitle($rendered['title']),
            'lead' => $rendered['lead'],
            'html' => $rendered['html'],
            'headings' => $rendered['headings'],
            'part' => $this->partOf($slug),
        ];
    }

    public function previous(string $slug): ?string
    {
        $slugs = $this->slugs();
        $index = array_search($slug, $slugs, true);

        return is_int($index) && $index > 0 ? $slugs[$index - 1] : null;
    }

    public function next(string $slug): ?string
    {
        $slugs = $this->slugs();
        $index = array_search($slug, $slugs, true);

        return is_int($index) && isset($slugs[$index + 1]) ? $slugs[$index + 1] : null;
    }

    /**
     * Plain full-text search: every section (an article's lead or one of its
     * H2 sections) whose text contains every word of the query.
     *
     * @return list<SearchHit>
     */
    public function search(string $query, int $limit = 40): array
    {
        $words = array_values(array_filter(preg_split('/\s+/', Str::lower(trim($query))) ?: [], fn (string $w): bool => $w !== ''));

        if ($words === [] || mb_strlen(implode('', $words)) < 2) {
            return [];
        }

        $hits = [];

        foreach ($this->slugs() as $slug) {
            $article = $this->article($slug);

            if ($article === null) {
                continue;
            }

            foreach ($this->sections($article) as $section) {
                $haystack = Str::lower($article['title'] . ' ' . $section['title'] . ' ' . $section['text']);

                foreach ($words as $word) {
                    if (! str_contains($haystack, $word)) {
                        continue 2;
                    }
                }

                $hits[] = [
                    'slug' => $slug,
                    'articleTitle' => $article['shortTitle'],
                    'anchor' => $section['id'],
                    'section' => $section['title'],
                    'snippet' => self::snippet($section['text'], $words[0]),
                ];

                if (count($hits) >= $limit) {
                    return $hits;
                }
            }
        }

        return $hits;
    }

    /** "How to find documents…" → "Find documents…", the label used in the contents. */
    public static function shortTitle(string $title): string
    {
        return Str::ucfirst((string) preg_replace('/^how to\s+/i', '', $title));
    }

    private function partOf(string $slug): string
    {
        foreach ($this->parts() as $part) {
            if (in_array($slug, $part['articles'], true)) {
                return $part['title'];
            }
        }

        return '';
    }

    /**
     * @param Article $article
     * @return list<array{id: string|null, title: string, text: string}>
     */
    private function sections(array $article): array
    {
        $chunks = preg_split('/(?=<h2\b)/i', $article['html']) ?: [];
        $titles = array_column($article['headings'], 'title', 'id');
        $sections = [['id' => null, 'title' => 'Introduction', 'text' => self::plain($article['lead'])]];

        foreach ($chunks as $chunk) {
            if (preg_match('/^<h2\b[^>]*\bid="([^"]+)"[^>]*>(.*?)<\/h2>/is', $chunk, $m) !== 1) {
                continue;
            }

            $sections[] = [
                'id' => $m[1],
                'title' => $titles[$m[1]] ?? self::plain($m[2]),
                'text' => self::plain(substr($chunk, strlen($m[0]))),
            ];
        }

        return $sections;
    }

    private static function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('/<a[^>]*class="heading-permalink"[^>]*>.*?<\/a>/is', '', $html)), ENT_QUOTES | ENT_HTML5);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function snippet(string $text, string $word): string
    {
        $position = mb_stripos($text, $word);

        if ($position === false || $position < 80) {
            return Str::limit($text, 200);
        }

        return '…' . Str::limit(mb_substr($text, $position - 60), 200);
    }

    /** @return array{title: string, lead: string, html: string, headings: list<Heading>} */
    private function render(string $slug, string $markdown): array
    {
        $imageBase = rtrim(asset('manual/images/' . $slug), '/') . '/';

        $environment = new Environment([
            // The articles are ours, but nothing in them needs raw HTML, so
            // it is shown as text rather than trusted.
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
            'heading_permalink' => [
                'min_heading_level' => 2,
                'max_heading_level' => 3,
                'apply_id_to_heading' => true,
                'id_prefix' => '',
                'fragment_prefix' => '',
                'insert' => 'after',
                'symbol' => '#',
                'title' => 'Link to this section',
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new HeadingPermalinkExtension);

        // Screenshots are written as ./block_x.png next to index.md; they are
        // served from public/manual/images/<slug>/.
        $environment->addEventListener(DocumentParsedEvent::class, function (DocumentParsedEvent $event) use ($imageBase): void {
            foreach ($event->getDocument()->iterator() as $node) {
                if (! $node instanceof Image) {
                    continue;
                }

                $url = $node->getUrl();

                if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|/)#i', $url) === 1) {
                    continue;
                }

                $file = basename((string) preg_replace('#^\./#', '', $url));
                $node->setUrl($imageBase . rawurlencode($file));
            }
        });

        $html = (string) (new MarkdownConverter($environment))->convert($markdown);

        // H1 = article title, shown in the page header, not in the body.
        $title = '';
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>\s*/is', $html, $m) === 1) {
            $title = self::plain($m[1]);
            $html = str_replace($m[0], '', $html);
        }

        // The paragraph right after the title is the grey lead.
        $lead = '';
        if (preg_match('/^\s*<p>(.*?)<\/p>\s*/is', $html, $m) === 1) {
            $lead = $m[1];
            $html = substr($html, strlen($m[0]));
        }

        // A screenshot alone in its paragraph becomes a framed figure that
        // opens the full-size image.
        $html = (string) preg_replace_callback(
            '/<p>\s*<img src="([^"]+)" alt="([^"]*)"\s*\/?>\s*<\/p>/i',
            fn (array $m): string => '<figure class="nra-manual-figure">'
                . '<a href="' . $m[1] . '" target="_blank" rel="noopener" title="Open the full-size screenshot">'
                . '<img src="' . $m[1] . '" alt="' . $m[2] . '" loading="lazy" decoding="async">'
                . '</a>'
                . ($m[2] !== '' ? '<figcaption>' . $m[2] . '</figcaption>' : '')
                . '</figure>',
            $html,
        );

        // "## 3. Search the list" → a numbered step with a round badge.
        $headings = [];
        $html = (string) preg_replace_callback(
            '/<h2 id="([^"]+)">(.*?)<\/h2>/is',
            function (array $m) use (&$headings): string {
                $inner = $m[2];
                $number = null;

                if (preg_match('/^(\d+)\.\s+/', $inner, $n) === 1) {
                    $number = $n[1];
                    $inner = '<span class="nra-manual-step-number" aria-hidden="true">' . $number . '</span>'
                        . '<span class="sr-only">Step ' . $number . ': </span>'
                        . substr($inner, strlen($n[0]));
                }

                $headings[] = [
                    'id' => $m[1],
                    'number' => $number,
                    'title' => (string) preg_replace('/^\d+\.\s+/', '', self::plain($m[2])),
                ];

                return '<h2 id="' . $m[1] . '"' . ($number !== null ? ' class="nra-manual-step"' : '') . '>' . $inner . '</h2>';
            },
            $html,
        );

        // The body of a numbered step hangs under its title, past the badge.
        $chunks = preg_split('/(?=<h2\b)/i', $html) ?: [$html];
        $html = implode('', array_map(function (string $chunk): string {
            if (preg_match('/^(<h2 [^>]*class="nra-manual-step"[^>]*>.*?<\/h2>)(.*)$/is', $chunk, $m) !== 1 || trim($m[2]) === '') {
                return $chunk;
            }

            return $m[1] . "\n<div class=\"nra-manual-step-body\">" . $m[2] . "</div>\n";
        }, $chunks));

        // Task-list checkboxes in "Prerequisites" are a checklist to read,
        // not a form to fill.
        $html = str_replace('<li><input disabled="" type="checkbox"> ', '<li class="nra-manual-check">', $html);

        return ['title' => $title !== '' ? $title : Str::headline($slug), 'lead' => $lead, 'html' => $html, 'headings' => $headings];
    }
}
