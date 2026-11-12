<?php

declare(strict_types=1);

use App\Filament\Pages\Reports;
use App\Filament\Pages\UserGuide;
use App\Filament\Resources\BoxResource\Pages\ListBoxes;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Models\User;
use App\Support\Manual\UserManual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/**
 * Client 2026-11-10: "Re: manual — is it possible to incorporate it in the
 * tool itself?". Help → User guide renders the manual articles (copied into
 * resources/manual and public/manual/images by `manual:sync`).
 */
uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

/** A plain viewer: the lowest role that can sign in to the panel. */
function iam_viewer(): User
{
    $user = bl_actor('viewer');
    $user->update(['is_active' => true]);

    return $user;
}

function iam_manual(): UserManual
{
    return app(UserManual::class);
}

/** A throwaway manual source in the layout of nra/manual. */
function iam_fixture(): string
{
    $dir = sys_get_temp_dir() . '/iam-manual-' . bin2hex(random_bytes(4));
    File::ensureDirectoryExists("{$dir}/_build");
    File::ensureDirectoryExists("{$dir}/articles/first-article");
    File::ensureDirectoryExists("{$dir}/articles/second-article");

    File::put("{$dir}/_build/manifest.json", json_encode([
        'title' => 'Fixture manual',
        'introduction' => ['Intro paragraph.'],
        'parts' => [
            ['title' => 'Part 1 · One', 'intro' => 'First part.', 'articles' => ['first-article']],
            ['title' => 'Part 2 · Two', 'intro' => 'Second part.', 'articles' => ['second-article']],
        ],
    ]));
    File::put("{$dir}/articles/first-article/index.md", "# How to do the first thing\n\nLead text.\n\n## 1. Open it\n\nClick **Open**.\n\n![Open it](./block_open.png)\n\n<script>alert(1)</script>\n\n[bad](javascript:alert(1))\n");
    File::put("{$dir}/articles/first-article/block_open.png", base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    File::put("{$dir}/articles/first-article/notes.txt", 'not an image');
    File::put("{$dir}/articles/second-article/index.md", "# How to do the second thing\n\nSecond lead.\n\n## Prerequisites\n\n- [ ] An account.\n\n| Role | Can do |\n|---|---|\n| Admin | Everything |\n");

    return $dir;
}

/** Point the guide at a temp target, sync the fixture into it. */
function iam_syncFixture(): array
{
    $source = iam_fixture();
    $target = sys_get_temp_dir() . '/iam-target-' . bin2hex(random_bytes(4));
    config([
        'manual.resources_path' => "{$target}/resources",
        'manual.images_path' => "{$target}/images",
    ]);
    app()->forgetInstance(UserManual::class);

    return [$source, $target];
}

it('renders the overview and an article for a user with no special role', function (): void {
    $user = iam_viewer();
    $this->actingAs($user);

    $this->get(UserGuide::getUrl())
        ->assertOk()
        ->assertSee('Logistics Tool')
        ->assertSee('Part 1 · Getting started');

    $this->get(UserGuide::getUrl(['article' => 'find-documents-with-search-and-filters']))
        ->assertOk()
        ->assertSee('How to find documents with search and filters')
        ->assertSee('nra-manual-step-number', false)
        ->assertSee('nra-manual-figure', false)
        ->assertSee('id="1-open-the-documents-list"', false);
});

it('is in the sidebar (Help group) and the user menu for every signed-in user', function (): void {
    $this->actingAs(iam_viewer());

    expect(UserGuide::canAccess())->toBeTrue()
        ->and(UserGuide::getNavigationGroup())->toBe('Help')
        ->and(UserGuide::getNavigationIcon())->toBe('heroicon-o-question-mark-circle');

    $menu = collect(Filament\Facades\Filament::getPanel('admin')->getUserMenuItems())->keys()->all();
    expect($menu)->toContain('user-guide');
});

it('sends guests to the login page', function (): void {
    $this->get(UserGuide::getUrl())->assertRedirect();
});

it('answers 404 for an unknown article', function (): void {
    $this->actingAs(iam_viewer());

    $this->get('/admin/help/no-such-article')->assertNotFound();
});

it('refuses path traversal in the article slug', function (string $slug): void {
    $this->actingAs(iam_viewer());

    $status = $this->get('/admin/help/' . $slug)->status();

    expect($status)->toBe(404);
    expect(iam_manual()->article(urldecode($slug)))->toBeNull();
})->with([
    '..%2F..%2F.env',
    '..%2Fmanifest',
    '%2E%2E',
    '..',
    'manifest',
    'FIND-DOCUMENTS-WITH-SEARCH-AND-FILTERS',
]);

it('cannot be pointed at another article through the Livewire payload', function (): void {
    $this->actingAs(iam_viewer());

    expect(fn () => Livewire::test(UserGuide::class, ['article' => 'run-and-export-reports'])
        ->set('article', '../../.env'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('lists every article of the manifest in the contents, in manual order', function (): void {
    $manifest = json_decode((string) file_get_contents(resource_path('manual/manifest.json')), true);
    $expected = collect($manifest['parts'])->flatMap(fn ($p) => $p['articles'])->values()->all();

    expect(iam_manual()->slugs())->toBe($expected)->and(count($expected))->toBe(16);

    $this->actingAs(iam_viewer());
    $html = $this->get(UserGuide::getUrl(['article' => 'run-and-export-reports']))->assertOk()->getContent();

    $positions = [];
    foreach ($expected as $slug) {
        $url = UserGuide::articleUrl($slug);
        expect($html)->toContain('href="' . e($url) . '"');
        $positions[] = strpos($html, 'href="' . e($url) . '"');
    }
    $sorted = $positions;
    sort($sorted);
    expect($positions)->toBe($sorted);

    // The open article lists its sections as anchors.
    expect($html)->toContain('href="#4-export-the-results"');
});

it('points every screenshot at a file that exists under public/manual/images', function (): void {
    $count = 0;

    foreach (iam_manual()->slugs() as $slug) {
        $article = iam_manual()->article($slug);
        preg_match_all('/<img src="([^"]+)"/', $article['html'], $m);

        foreach ($m[1] as $src) {
            $path = public_path(ltrim((string) parse_url($src, PHP_URL_PATH), '/'));
            expect(is_file(rawurldecode($path)))->toBeTrue("missing image {$src}");
            $count++;
        }

        expect($article['title'])->toStartWith('How to');
    }

    expect($count)->toBeGreaterThan(30);
});

it('finds sections by full-text search and shows them as results', function (): void {
    $hits = iam_manual()->search('two-factor recovery');
    expect($hits)->not->toBeEmpty()
        ->and(collect($hits)->pluck('slug')->unique()->all())->toContain('protect-your-account-with-two-factor-authentication');

    expect(iam_manual()->search('x'))->toBe([])
        ->and(iam_manual()->search('zzqqxx nothing'))->toBe([]);

    $this->actingAs(iam_viewer());
    Livewire::test(UserGuide::class)
        ->set('search', 'Export CSV')
        ->assertSee('Results for')
        ->assertSee('Run and export reports');
});

it('adds a Help action to the Documents, Boxes and Reports pages', function (string $page, string $slug): void {
    $this->actingAs(bl_actor('super_admin'));

    Livewire::test($page)
        ->assertActionExists('help')
        ->assertActionHasUrl('help', UserGuide::articleUrl($slug));
})->with([
    'documents' => [ListDocuments::class, 'find-documents-with-search-and-filters'],
    'boxes' => [ListBoxes::class, 'manage-boxes-and-their-barcode-status'],
    'reports' => [Reports::class, 'run-and-export-reports'],
]);

it('manual:sync copies the articles, screenshots and manifest from a source folder', function (): void {
    [$source, $target] = iam_syncFixture();

    // A leftover from an earlier sync must disappear.
    File::ensureDirectoryExists("{$target}/resources/articles");
    File::put("{$target}/resources/articles/removed-article.md", '# Gone');

    $this->artisan('manual:sync', ['path' => $source])
        ->expectsOutputToContain('Copied 2 articles and 1 images')
        ->assertSuccessful();

    expect(File::exists("{$target}/resources/manifest.json"))->toBeTrue()
        ->and(File::exists("{$target}/resources/articles/first-article.md"))->toBeTrue()
        ->and(File::exists("{$target}/resources/articles/second-article.md"))->toBeTrue()
        ->and(File::exists("{$target}/resources/articles/removed-article.md"))->toBeFalse()
        ->and(File::exists("{$target}/images/first-article/block_open.png"))->toBeTrue()
        ->and(File::exists("{$target}/images/first-article/notes.txt"))->toBeFalse();

    $manual = app(UserManual::class);
    expect($manual->slugs())->toBe(['first-article', 'second-article'])
        ->and($manual->next('first-article'))->toBe('second-article')
        ->and($manual->previous('first-article'))->toBeNull();

    $first = $manual->article('first-article');
    expect($first['title'])->toBe('How to do the first thing')
        ->and($first['shortTitle'])->toBe('Do the first thing')
        ->and($first['lead'])->toBe('Lead text.')
        ->and($first['headings'][0])->toMatchArray(['id' => '1-open-it', 'number' => '1', 'title' => 'Open it'])
        ->and($first['html'])->toContain('/manual/images/first-article/block_open.png')
        // Raw HTML is escaped, javascript: links are dropped.
        ->and($first['html'])->not->toContain('<script>')
        ->and($first['html'])->toContain('&lt;script&gt;')
        ->and($first['html'])->not->toContain('javascript:');

    $second = $manual->article('second-article');
    expect($second['html'])->toContain('<table>')
        ->and($second['html'])->toContain('nra-manual-check');

    File::deleteDirectory($source);
    File::deleteDirectory($target);
});

it('manual:sync refuses a manifest that names a missing or unsafe article', function (string $slug): void {
    [$source, $target] = iam_syncFixture();
    File::put("{$source}/_build/manifest.json", json_encode(['parts' => [['title' => 'x', 'articles' => [$slug]]]]));

    $this->artisan('manual:sync', ['path' => $source])->assertFailed();

    expect(File::exists("{$target}/resources/manifest.json"))->toBeFalse();

    File::deleteDirectory($source);
})->with(['../escape', 'missing-article', 'Bad Slug']);

it('manual:sync fails cleanly when the source folder is missing', function (): void {
    iam_syncFixture();

    $this->artisan('manual:sync', ['path' => sys_get_temp_dir() . '/does-not-exist-' . bin2hex(random_bytes(3))])
        ->assertFailed();
});
