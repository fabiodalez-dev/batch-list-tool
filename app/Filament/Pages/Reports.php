<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Reports\BoxMovementHistoryReport;
use App\Filament\Pages\Reports\DocumentsByBatchReport;
use App\Filament\Pages\Reports\DocumentsByCreatorReport;
use App\Filament\Pages\Reports\DocumentsBySeriesReport;
use App\Filament\Pages\Reports\FlagsByTypeReport;
use App\Filament\Pages\Reports\PendingDisinfestationReport;
use App\Models\BoxMovement;
use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\ReportTemplate;
use App\Models\User;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

/**
 * RFQ §3.1.10 — Reports landing page.
 *
 * Surfaces the six canned reports the bid promises:
 *   1. Documents by batch
 *   2. Documents by creator / notary
 *   3. Documents by series
 *   4. Documents pending disinfestation
 *   5. Box movement history
 *   6. Flags by type (RFQ APP2-xviii)
 *
 * Below the canned-report grid the page also lists the saved
 * report templates the operator can access (RFQ §3.2.2).
 *
 * The landing page is a thin "card grid" of links into the individual
 * Report pages. Each card shows a one-line summary count cached for
 * 60s so this page stays snappy even if the operator hits it
 * repeatedly during a session.
 *
 * Authorisation is enforced via `report` Shield permissions seeded by
 * {@see \bl_shieldPermissionNames()} / InitialDataSeeder.
 */
class Reports extends Page
{
    protected string $view = 'filament.pages.reports';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 90;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $title = 'Reports';

    protected static ?string $slug = 'reports';

    /**
     * Only users with `view_any_report` (admin, editor, viewer per the
     * Shield role matrix) can open the landing page or any sub-report.
     */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('view_any_report');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * @return array<int, array{key:string, title:string, description:string, icon:string, url:string, count:string}>
     */
    public function cards(): array
    {
        $counts = $this->cachedCounts();

        return [
            [
                'key' => 'by-batch',
                'title' => 'Documents by batch',
                'description' => 'Counts of documents grouped by their batch number.',
                'icon' => 'heroicon-o-archive-box',
                'url' => DocumentsByBatchReport::getUrl(),
                'count' => $counts['documents'] . ' documents',
            ],
            [
                'key' => 'by-creator',
                'title' => 'Documents by creator / notary',
                'description' => 'Counts of documents grouped by Authority (notary).',
                'icon' => 'heroicon-o-user-group',
                'url' => DocumentsByCreatorReport::getUrl(),
                'count' => $counts['documents'] . ' documents',
            ],
            [
                'key' => 'by-series',
                'title' => 'Documents by series',
                'description' => 'Counts of documents grouped by their series code.',
                'icon' => 'heroicon-o-rectangle-stack',
                'url' => DocumentsBySeriesReport::getUrl(),
                'count' => $counts['documents'] . ' documents',
            ],
            [
                'key' => 'pending-disinfestation',
                'title' => 'Documents pending disinfestation',
                'description' => 'Documents without a disinfestation date that are not PERM_OUT.',
                'icon' => 'heroicon-o-shield-exclamation',
                'url' => PendingDisinfestationReport::getUrl(),
                'count' => $counts['pending'] . ' pending',
            ],
            [
                'key' => 'movements',
                'title' => 'Box movement history',
                'description' => 'Chronological log of box-to-box transfers, filterable by date.',
                'icon' => 'heroicon-o-arrow-path',
                'url' => BoxMovementHistoryReport::getUrl(),
                'count' => $counts['movements'] . ' movements',
            ],
            [
                'key' => 'flags-by-type',
                'title' => 'Flags by type',
                'description' => 'Counts of issue flags grouped by category and severity (RFQ APP2-xviii).',
                'icon' => 'heroicon-o-flag',
                'url' => FlagsByTypeReport::getUrl(),
                'count' => $counts['flags'] . ' flags',
            ],
        ];
    }

    /**
     * Templates accessible to the current user (owner OR shared in their
     * repository). Surfaced on the landing page below the canned-report
     * grid so saved views are one click away.
     *
     * @return array<int, array{id:int, name:string, description:?string, source_label:string, is_shared:bool, url:?string}>
     */
    public function templates(): array
    {
        $user = auth()->user();
        if ($user === null) {
            return [];
        }

        /** @var User $user */
        $rows = ReportTemplate::query()
            ->accessibleBy($user)
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get();

        $out = [];
        foreach ($rows as $tpl) {
            $page = match ($tpl->source) {
                ReportTemplate::SOURCE_DOCUMENTS_BY_BATCH => DocumentsByBatchReport::class,
                ReportTemplate::SOURCE_DOCUMENTS_BY_CREATOR => DocumentsByCreatorReport::class,
                ReportTemplate::SOURCE_DOCUMENTS_BY_SERIES => DocumentsBySeriesReport::class,
                ReportTemplate::SOURCE_PENDING_DISINFESTATION => PendingDisinfestationReport::class,
                ReportTemplate::SOURCE_BOX_MOVEMENTS => BoxMovementHistoryReport::class,
                ReportTemplate::SOURCE_FLAGS_BY_TYPE => FlagsByTypeReport::class,
                default => null,
            };

            $url = $page === null ? null : $page::getUrl(['template' => $tpl->getKey()]);

            $out[] = [
                'id' => (int) $tpl->getKey(),
                'name' => (string) $tpl->name,
                'description' => $tpl->description,
                'source_label' => self::sourceLabel((string) $tpl->source),
                'is_shared' => (bool) $tpl->is_shared,
                'url' => $url,
            ];
        }

        return $out;
    }

    /**
     * Human label for a `source` enum value — duplicated from
     * ReportTemplateResource so the landing page stays self-contained.
     */
    public static function sourceLabel(string $source): string
    {
        return match ($source) {
            ReportTemplate::SOURCE_DOCUMENTS_BY_BATCH => 'Documents by batch',
            ReportTemplate::SOURCE_DOCUMENTS_BY_CREATOR => 'Documents by creator',
            ReportTemplate::SOURCE_DOCUMENTS_BY_SERIES => 'Documents by series',
            ReportTemplate::SOURCE_PENDING_DISINFESTATION => 'Pending disinfestation',
            ReportTemplate::SOURCE_BOX_MOVEMENTS => 'Box movement history',
            ReportTemplate::SOURCE_FLAGS_BY_TYPE => 'Flags by type',
            ReportTemplate::SOURCE_DOCUMENTS => 'Documents',
            default => $source,
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        return [
            'cards' => $this->cards(),
            'templates' => $this->templates(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function cachedCounts(): array
    {
        $uid = auth()->id() ?? 'guest';

        $counts = Cache::remember(
            "reports:landing:counts:u={$uid}",
            now()->addSeconds(60),
            function (): array {
                return [
                    'documents' => Document::query()->count(),
                    'pending' => Document::query()
                        ->whereNull('disinfestation_date')
                        ->where(function ($q): void {
                            $q->whereNull('current_box_id')
                                ->orWhereHas('currentBox', function ($q): void {
                                    $q->where('barcode_status', '!=', 'PERM_OUT');
                                });
                        })
                        ->count(),
                    'movements' => BoxMovement::query()->count(),
                    'flags' => DocumentFlag::query()->count(),
                ];
            },
        );

        return [
            'documents' => number_format((int) $counts['documents']),
            'pending' => number_format((int) $counts['pending']),
            'movements' => number_format((int) $counts['movements']),
            'flags' => number_format((int) $counts['flags']),
        ];
    }
}
