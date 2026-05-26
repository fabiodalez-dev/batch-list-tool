<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\AuthorityResource;
use App\Filament\Resources\BatchResource;
use App\Filament\Resources\DocumentResource;
use App\Filament\Resources\RepositoryResource;
use App\Filament\Resources\SeriesResource;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Pages\Page;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RFQ §3.1.3 (Bulk Import v2) — onboarding wizard.
 *
 * This is the first thing an operator sees on a fresh tenant: it walks
 * them through the 5-step setup sequence (Series → Authorities →
 * Repositories → Batches → Documents) with the right ordering, prereq
 * gating, and download/upload entry points all in one place.
 *
 * Why a Page (not a Resource):
 *
 *   - It's a workflow, not a model. There is no `import_wizard` table.
 *   - Filament Pages give us full control of the blade view (we render
 *     a 5-card stepper, not a CRUD table).
 *
 * Auto-hide behaviour: once every prereq table has at least one row
 * the wizard disappears from the nav (returns false from
 * `shouldRegisterNavigation`). That keeps the sidebar clean for
 * day-to-day operators who don't need to see setup tools.
 *
 * The buttons on the page download templates via
 * {@see TemplateGenerator::download()} (same code path the per-Resource
 * header action uses) and link to the matching Resource List page for
 * actually opening the import modal.
 */
class ImportWizard extends Page
{
    /**
     * Blade view rendered inside `<x-filament-panels::page>`.
     */
    protected string $view = 'filament.pages.import-wizard';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 1;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rocket-launch';

    protected static ?string $title = 'Import wizard';

    protected static ?string $slug = 'import-wizard';

    /**
     * Auto-hide once setup is done.
     *
     * The wizard exists to *bootstrap* a fresh tenant — once every
     * prereq table is non-empty there's nothing useful left here, so
     * we return false to drop the entry from the sidebar. Operators
     * with ongoing imports use the per-Resource import action instead.
     *
     * Reads bypass repository scopes intentionally: a brand-new
     * tenant might have inherited reference data (Series, Authorities)
     * that lives outside their tenant filter, so we count globally.
     */
    public static function shouldRegisterNavigation(): bool
    {
        // Cheap fast-path: if the user is not authenticated (CLI / queue
        // discovery, unit tests), keep the registration on by default.
        if (auth()->guest()) {
            return true;
        }

        return ! self::allPrerequisitesMet();
    }

    /**
     * Admin / super_admin only — the wizard runs imports that affect every
     * tenant, so we keep it off-limits for editors and viewers.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        return $user->hasAnyRole(['super_admin', 'admin']);
    }

    /* ──────────────────────────────────────────────────────────────── */
    /* Step definitions + state queries */
    /* ──────────────────────────────────────────────────────────────── */

    /**
     * The 5 steps the operator sees, in the order they should be executed.
     * Each step carries:
     *   - key:    the TemplateGenerator entity key
     *   - title:  human label
     *   - expected: approx rows expected (used in the "29 / 29 expected" caption)
     *   - prereq: array of step keys that must be non-empty before this step
     *             unlocks (the "Open importer" button is disabled until then)
     *   - resource: the Filament Resource class for the deep-link
     *
     * @return array<int, array{key:string,title:string,expected:?int,prereq:array<int, string>,resource:string}>
     */
    public static function steps(): array
    {
        return [
            [
                'key' => 'series',
                'title' => 'Series',
                'expected' => 29,
                'prereq' => [],
                'resource' => SeriesResource::class,
            ],
            [
                'key' => 'authority',
                'title' => 'Authorities',
                'expected' => 808,
                'prereq' => [],
                'resource' => AuthorityResource::class,
            ],
            [
                'key' => 'repository',
                'title' => 'Repositories',
                'expected' => null,
                'prereq' => [],
                'resource' => RepositoryResource::class,
            ],
            [
                'key' => 'batch',
                'title' => 'Batches',
                'expected' => null,
                'prereq' => ['repository'],
                'resource' => BatchResource::class,
            ],
            [
                'key' => 'document',
                'title' => 'Documents',
                'expected' => 3113,
                'prereq' => ['series', 'authority', 'repository', 'batch'],
                'resource' => DocumentResource::class,
            ],
        ];
    }

    /**
     * Per-step row counts and gating state. Used by the blade view.
     *
     * @return array<int, array{
     *     key:string,title:string,expected:?int,prereq:array<int, string>,
     *     resource:string,count:int,done:bool,unlocked:bool,
     *     missing:array<int, string>,
     *     has_template:bool,
     * }>
     */
    public static function stepStates(): array
    {
        $counts = self::counts();
        $states = [];
        foreach (self::steps() as $step) {
            $missing = [];
            foreach ($step['prereq'] as $req) {
                if (($counts[$req] ?? 0) === 0) {
                    $missing[] = $req;
                }
            }

            $states[] = $step + [
                'count' => $counts[$step['key']] ?? 0,
                'done' => ($counts[$step['key']] ?? 0) > 0,
                'unlocked' => count($missing) === 0,
                'missing' => $missing,
                'has_template' => array_key_exists($step['key'], TemplateGenerator::TEMPLATES),
            ];
        }

        return $states;
    }

    /**
     * Number of completed steps (out of total). Used for the progress bar.
     *
     * @return array{done:int,total:int,percent:int}
     */
    public static function progress(): array
    {
        $counts = self::counts();
        $total = count(self::steps());
        $done = 0;
        foreach (self::steps() as $step) {
            if (($counts[$step['key']] ?? 0) > 0) {
                $done++;
            }
        }

        return [
            'done' => $done,
            'total' => $total,
            'percent' => $total === 0 ? 0 : (int) round(($done / $total) * 100),
        ];
    }

    /**
     * Global row counts per entity — bypasses tenant scopes because a
     * fresh tenant might have inherited reference data shared across
     * repositories.
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        return [
            'series' => Series::query()->count(),
            'authority' => Authority::query()->count(),
            'repository' => Repository::query()->count(),
            'batch' => Batch::query()->withoutGlobalScope(RepositoryScope::class)->count(),
            'document' => Document::query()->withoutGlobalScope(RepositoryScope::class)->count(),
            'box' => Box::query()->withoutGlobalScope(ThroughBatchRepositoryScope::class)->count(),
        ];
    }

    /** True when every step's table has at least one row. */
    public static function allPrerequisitesMet(): bool
    {
        $counts = self::counts();
        foreach (self::steps() as $step) {
            if (($counts[$step['key']] ?? 0) === 0) {
                return false;
            }
        }

        return true;
    }

    /* ──────────────────────────────────────────────────────────────── */
    /* Livewire actions */
    /* ──────────────────────────────────────────────────────────────── */

    /**
     * Stream the entity's blank template — same code path as the
     * per-Resource header action. Called from the blade view's
     * "Download template" button via `wire:click="downloadTemplate('series')"`.
     */
    public function downloadTemplate(string $entity): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return TemplateGenerator::download($entity);
    }

    /**
     * URL of the matching Resource List page — used for the "Open
     * importer" deep-link. Returns `#` for the Repository step
     * (the wizard hands off to whatever Resource registered for
     * Repository, which exists in this codebase but isn't always
     * navigation-registered for non-admin operators).
     */
    public static function importerUrl(string $resource): string
    {
        if (! class_exists($resource)) {
            return '#';
        }

        return $resource::getUrl('index');
    }
}
