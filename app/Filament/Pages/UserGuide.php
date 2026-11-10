<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\Manual\UserManual;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

/**
 * Help → User guide: the user manual, inside the tool.
 *
 * Client 2026-11-10: "manual — is it possible to incorporate it in the tool
 * itself?". The articles are the same ones the DOCX and PDF are built from,
 * copied into the repository by `php artisan manual:sync` (see
 * {@see UserManual}). One article per page at /admin/help/{article}; the
 * overview at /admin/help.
 *
 * Every signed-in user can read it: the manual says, article by article,
 * which role each task needs.
 */
class UserGuide extends Page
{
    /** The article shown; validated in mount() and never taken from the client again. */
    #[Locked]
    public ?string $article = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    protected string $view = 'filament.pages.user-guide';

    protected static string|\UnitEnum|null $navigationGroup = 'Help';

    protected static ?int $navigationSort = 10;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationLabel = 'User guide';

    protected static ?string $slug = 'help';

    protected Width|string|null $maxContentWidth = Width::Full;

    public static function getRoutePath(Panel $panel): string
    {
        return '/' . static::getSlug($panel) . '/{article?}';
    }

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    /**
     * A header action that opens the guide at the given article (and section).
     * Used by the list pages so the help for a screen is one click away.
     */
    public static function helpAction(string $article, ?string $section = null): Action
    {
        return Action::make('help')
            ->label('Help')
            ->icon('heroicon-o-question-mark-circle')
            ->color('gray')
            ->tooltip('Open the user guide for this page')
            ->url(static::articleUrl($article, $section), shouldOpenInNewTab: true);
    }

    public static function articleUrl(?string $article = null, ?string $section = null): string
    {
        $url = static::getUrl($article !== null ? ['article' => $article] : []);

        return $section !== null && $section !== '' ? $url . '#' . $section : $url;
    }

    public function mount(?string $article = null): void
    {
        if ($article !== null && ! app(UserManual::class)->has($article)) {
            abort(404);
        }

        $this->article = $article;
    }

    public function getTitle(): string|Htmlable
    {
        $article = $this->article !== null ? app(UserManual::class)->article($this->article) : null;

        return $article !== null ? $article['shortTitle'] . ' · User guide' : 'User guide';
    }

    public function getHeading(): string|Htmlable
    {
        // The guide draws its own title and lead.
        return '';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $manual = app(UserManual::class);
        $current = $this->article !== null ? $manual->article($this->article) : null;

        $parts = [];
        foreach ($manual->parts() as $part) {
            $articles = [];
            foreach ($part['articles'] as $slug) {
                $article = $manual->article($slug);
                if ($article !== null) {
                    $articles[] = $article;
                }
            }
            $parts[] = ['title' => $part['title'], 'intro' => $part['intro'], 'articles' => $articles];
        }

        $previous = $this->article !== null ? $manual->previous($this->article) : null;
        $next = $this->article !== null ? $manual->next($this->article) : ($manual->slugs()[0] ?? null);

        $search = trim($this->search);

        return [
            'manualTitle' => $manual->title(),
            'introduction' => $manual->introduction(),
            'parts' => $parts,
            'current' => $current,
            'previous' => $previous !== null ? $manual->article($previous) : null,
            'next' => $next !== null ? $manual->article($next) : null,
            'searchTerm' => $search,
            'results' => $search !== '' ? $manual->search($search) : [],
        ];
    }
}
