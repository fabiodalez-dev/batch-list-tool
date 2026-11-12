<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Pages\Reports\ActivityOverTimeReport;
use App\Filament\Pages\Reports\BoxesPendingDisinfestationReport;
use App\Filament\Pages\Reports\BoxesReadyToDestroyReport;
use App\Filament\Pages\Reports\BoxMovementHistoryReport;
use App\Filament\Pages\Reports\DocumentLocationReport;
use App\Filament\Pages\Reports\DocumentsByBatchReport;
use App\Filament\Pages\Reports\DocumentsByCreatorReport;
use App\Filament\Pages\Reports\DocumentsBySeriesReport;
use App\Filament\Pages\Reports\FlagsByTypeReport;
use App\Filament\Pages\Reports\MassMigrationReport;
use App\Filament\Pages\Reports\PendingDisinfestationReport;
use App\Filament\Pages\Reports\RasNraReconciliationReport;
use App\Filament\Pages\Reports\RasReconciliationReport;
use App\Filament\Pages\Reports\StockTakeReport;
use App\Models\BoxMovement;
use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\Location;
use App\Models\RasReconciliationRun;
use App\Models\ReportTemplate;
use App\Models\User;
use App\Support\ActiveRepository;
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
 *   5. Document movement history
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
    use ExplainsPage;

    /**
     * The report pages opened from this landing page. They have no sidebar
     * item of their own, so the "Reports" item stays highlighted on them.
     *
     * @var list<class-string<Page>>
     */
    public const array REPORT_PAGES = [
        ActivityOverTimeReport::class,
        BoxesPendingDisinfestationReport::class,
        BoxesReadyToDestroyReport::class,
        BoxMovementHistoryReport::class,
        DocumentLocationReport::class,
        DocumentsByBatchReport::class,
        DocumentsByCreatorReport::class,
        DocumentsBySeriesReport::class,
        FlagsByTypeReport::class,
        MassMigrationReport::class,
        PendingDisinfestationReport::class,
        RasNraReconciliationReport::class,
        RasReconciliationReport::class,
        StockTakeReport::class,
    ];

    protected string $view = 'filament.pages.reports';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 10;

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
     * @return array<string>
     */
    public static function getNavigationItemActiveRoutePattern(): array
    {
        return [
            static::getRouteName(),
            ...array_map(static fn (string $page): string => $page::getRouteName(), self::REPORT_PAGES),
        ];
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
                'title' => 'Documents by subseries',
                'description' => 'Counts of documents grouped by their series code.',
                'icon' => 'heroicon-o-rectangle-stack',
                'url' => DocumentsBySeriesReport::getUrl(),
                'count' => $counts['documents'] . ' documents',
            ],
            [
                'key' => 'document-locations',
                'title' => 'Documents by location',
                'description' => 'Every document with its effective location and the box it sits in (or just the location if it is no longer in a box).',
                'icon' => 'heroicon-o-map-pin',
                'url' => DocumentLocationReport::getUrl(),
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
                'key' => 'boxes-pending-disinfestation',
                'title' => 'Boxes pending disinfestation',
                'description' => 'Boxes at status IN holding at least one document (In Box) with no disinfestation date: the whole box goes. Select them and send them to disinfestation.',
                'icon' => 'heroicon-o-bug-ant',
                'url' => BoxesPendingDisinfestationReport::getUrl(),
                'count' => 'Box level',
            ],
            [
                // Client 2026-11-10, Report 1: upload the RAS export, see where it does not tally.
                'key' => 'ras-reconciliation',
                'title' => 'RAS ↔ NRA reconciliation',
                'description' => 'Upload the RAS report: it is matched with our RAS boxes by barcode, batch / box and status, and only the discrepancies are listed.',
                'icon' => 'heroicon-o-scale',
                'url' => RasReconciliationReport::getUrl(),
                'count' => $this->rasUploadLabel(),
            ],
            [
                'key' => 'ras-nra-reconciliation',
                'title' => 'RAS key on documents',
                'description' => 'RAS Batch / Box / latest Barcode IN per document, flagging documents whose RAS key is incomplete.',
                'icon' => 'heroicon-o-key',
                'url' => RasNraReconciliationReport::getUrl(),
                'count' => ($counts['reconciliation'] ?? 0) . ' RAS-origin docs',
            ],
            [
                'key' => 'movements',
                'title' => 'Document movement history',
                'description' => 'Documents moved between boxes: when, from which box to which, why and by whom, with catalogue identifier, authority, volume, creator and dates.',
                'icon' => 'heroicon-o-arrow-path',
                'url' => BoxMovementHistoryReport::getUrl(),
                'count' => $counts['movements'] . ' movements',
            ],
            [
                'key' => 'stock-take',
                'title' => 'Stock take (by location)',
                'description' => 'Per box (batch / box or NRA number, current box type, location) or per document (catalogue identifier, authority code, volume, creator, dates), grouped by location.',
                'icon' => 'heroicon-o-clipboard-document-check',
                'url' => StockTakeReport::getUrl(),
                'count' => ($counts['stocktake'] ?? 0) . ' locations',
            ],
            [
                'key' => 'mass-migration',
                'title' => 'Mass migration',
                'description' => 'The boxes holding at least one document of the chosen series, document type and Actual Authority Reference Code range. The whole box is listed.',
                'icon' => 'heroicon-o-truck',
                'url' => MassMigrationReport::getUrl(),
                'count' => 'Box level',
            ],
            [
                'key' => 'activity-over-time',
                'title' => 'Activity over time',
                'description' => 'The last twelve months, month by month: changes recorded, moves, disinfestations and cataloguing.',
                'icon' => 'heroicon-o-chart-bar',
                'url' => ActivityOverTimeReport::getUrl(),
                'count' => '12 months',
            ],
            [
                'key' => 'boxes-ready-to-destroy',
                'title' => 'Boxes ready to be destroyed',
                'description' => 'RAS boxes whose documents all have a catalogue identifier — the ones that can now be marked destroyed (RFQ Appendix 2 §vii).',
                'icon' => 'heroicon-o-archive-box-x-mark',
                'url' => BoxesReadyToDestroyReport::getUrl(),
                'count' => 'Box lifecycle',
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
                ReportTemplate::SOURCE_DOCUMENT_LOCATIONS => DocumentLocationReport::class,
                ReportTemplate::SOURCE_PENDING_DISINFESTATION => PendingDisinfestationReport::class,
                ReportTemplate::SOURCE_RAS_NRA_RECONCILIATION => RasNraReconciliationReport::class,
                ReportTemplate::SOURCE_STOCK_TAKE => StockTakeReport::class,
                ReportTemplate::SOURCE_BOXES_READY_TO_DESTROY => BoxesReadyToDestroyReport::class,
                ReportTemplate::SOURCE_BOXES_PENDING_DISINFESTATION => BoxesPendingDisinfestationReport::class,
                ReportTemplate::SOURCE_MASS_MIGRATION => MassMigrationReport::class,
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
            ReportTemplate::SOURCE_DOCUMENTS_BY_SERIES => 'Documents by subseries',
            ReportTemplate::SOURCE_DOCUMENT_LOCATIONS => 'Documents by location',
            ReportTemplate::SOURCE_PENDING_DISINFESTATION => 'Pending disinfestation',
            ReportTemplate::SOURCE_RAS_NRA_RECONCILIATION => 'RAS key on documents',
            ReportTemplate::SOURCE_STOCK_TAKE => 'Stock take',
            ReportTemplate::SOURCE_BOXES_READY_TO_DESTROY => 'Boxes ready to be destroyed',
            ReportTemplate::SOURCE_BOXES_PENDING_DISINFESTATION => 'Boxes pending disinfestation',
            ReportTemplate::SOURCE_MASS_MIGRATION => 'Mass migration',
            ReportTemplate::SOURCE_BOX_MOVEMENTS => 'Document movement history',
            ReportTemplate::SOURCE_FLAGS_BY_TYPE => 'Flags by type',
            ReportTemplate::SOURCE_DOCUMENTS => 'Documents',
            default => $source,
        };
    }

    protected function getHeaderActions(): array
    {
        return [
            UserGuide::helpAction('run-and-export-reports'),
        ];
    }

    /** When the RAS report was last uploaded for the active repository. */
    protected function rasUploadLabel(): string
    {
        $id = auth()->id();
        $run = RasReconciliationRun::query()
            ->forScope(app(ActiveRepository::class)->id(), $id === null ? null : (int) $id)
            ->latest('id')
            ->first(['id', 'created_at']);

        return $run?->created_at === null
            ? 'No upload yet'
            : 'Last upload ' . $run->created_at->format('d-m-Y');
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
            fn (): array => [
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
                'reconciliation' => Document::query()
                    ->where(function ($q): void {
                        $q->where(fn ($b) => $b->whereNotNull('ras_batch_1')->where('ras_batch_1', '!=', ''))
                            ->orWhere(fn ($b) => $b->whereNotNull('ras_batch_2')->where('ras_batch_2', '!=', ''))
                            ->orWhere(fn ($b) => $b->whereNotNull('ras_box_1')->where('ras_box_1', '!=', ''))
                            ->orWhere(fn ($b) => $b->whereNotNull('ras_box_2')->where('ras_box_2', '!=', ''))
                            ->orWhere(fn ($b) => $b->whereNotNull('barcode_ras_1')->where('barcode_ras_1', '!=', ''))
                            ->orWhere(fn ($b) => $b->whereNotNull('barcode_ras_2')->where('barcode_ras_2', '!=', ''))
                            ->orWhere(fn ($b) => $b->whereNotNull('barcode_in_2')->where('barcode_in_2', '!=', ''));
                    })
                    ->count(),
                'stocktake' => Location::query()->count(),
            ],
        );

        return [
            'documents' => number_format((int) $counts['documents']),
            'pending' => number_format((int) $counts['pending']),
            'movements' => number_format((int) $counts['movements']),
            'flags' => number_format((int) $counts['flags']),
            'reconciliation' => number_format((int) $counts['reconciliation']),
            'stocktake' => number_format((int) $counts['stocktake']),
        ];
    }
}
