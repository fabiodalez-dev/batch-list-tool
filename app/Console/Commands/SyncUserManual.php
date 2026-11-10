<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Manual\UserManual;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Copies the user manual from its source (nra/manual: articles/<slug>/index.md
 * with their screenshots, and _build/manifest.json for the order) into the
 * folders the in-app Help page reads, which are versioned and deployed:
 *
 *   resources/manual/manifest.json
 *   resources/manual/articles/<slug>.md
 *   public/manual/images/<slug>/<image>
 *
 * The target folders are replaced as a whole, so an article or screenshot
 * removed from the source disappears from the tool as well.
 */
class SyncUserManual extends Command
{
    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    protected $signature = 'manual:sync {path? : The manual source folder (default: nra/manual)}';

    protected $description = 'Copy the user manual articles and screenshots into the tool (Help → User guide).';

    public function handle(): int
    {
        $source = rtrim((string) ($this->argument('path') ?? base_path('nra/manual')), DIRECTORY_SEPARATOR);
        $articlesDir = $source . '/articles';
        $manifestFile = collect([$source . '/_build/manifest.json', $source . '/manifest.json'])->first(fn (string $f): bool => is_file($f));

        if (! is_dir($articlesDir) || $manifestFile === null) {
            $this->error("No manual found in {$source} (expected articles/ and _build/manifest.json).");

            return self::FAILURE;
        }

        $manifest = json_decode((string) file_get_contents($manifestFile), true);

        if (! is_array($manifest) || ! is_array($manifest['parts'] ?? null)) {
            $this->error("{$manifestFile} is not a valid manifest (no \"parts\").");

            return self::FAILURE;
        }

        $slugs = [];
        foreach ($manifest['parts'] as $part) {
            foreach ((array) ($part['articles'] ?? []) as $slug) {
                if (! is_string($slug) || ! UserManual::isValidSlug($slug)) {
                    $this->error('Invalid article slug in the manifest: ' . json_encode($slug));

                    return self::FAILURE;
                }

                if (! is_file("{$articlesDir}/{$slug}/index.md")) {
                    $this->error("The manifest lists \"{$slug}\" but {$articlesDir}/{$slug}/index.md does not exist.");

                    return self::FAILURE;
                }

                $slugs[] = $slug;
            }
        }

        $resources = UserManual::resourcesPath();
        $images = UserManual::imagesPath();

        File::deleteDirectory($resources . '/articles');
        File::deleteDirectory($images);
        File::ensureDirectoryExists($resources . '/articles');
        File::ensureDirectoryExists($images);

        File::put($resources . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

        $rows = [];
        $imageCount = 0;

        foreach (array_unique($slugs) as $slug) {
            $markdown = (string) file_get_contents("{$articlesDir}/{$slug}/index.md");
            File::put("{$resources}/articles/{$slug}.md", $markdown);

            $copied = 0;
            foreach (File::files("{$articlesDir}/{$slug}") as $file) {
                if (! in_array(strtolower($file->getExtension()), self::IMAGE_EXTENSIONS, true)) {
                    continue;
                }

                File::ensureDirectoryExists("{$images}/{$slug}");
                File::copy($file->getPathname(), "{$images}/{$slug}/{$file->getFilename()}");
                $copied++;
            }

            // Screenshots the article points at but that are not there.
            preg_match_all('/!\[[^\]]*\]\(\.?\/?([^)\s]+)\)/', $markdown, $refs);
            foreach ($refs[1] as $ref) {
                if (! is_file("{$images}/{$slug}/" . basename($ref))) {
                    $this->warn("{$slug}: the image {$ref} is referenced but missing.");
                }
            }

            $rows[] = [$slug, $copied];
            $imageCount += $copied;
        }

        $this->table(['Article', 'Images'], $rows);
        $this->info(sprintf('Copied %d articles and %d images from %s.', count($rows), $imageCount, $source));

        return self::SUCCESS;
    }
}
