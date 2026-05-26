<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Reports\BoxMovementHistoryReport;
use App\Filament\Pages\Reports\DocumentsByBatchReport;
use App\Filament\Pages\Reports\DocumentsByCreatorReport;
use App\Filament\Pages\Reports\DocumentsBySeriesReport;
use App\Filament\Pages\Reports\PendingDisinfestationReport;
use App\Models\BoxMovement;
use App\Models\Document;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

/**
 * RFQ §3.1.10 — Reports landing page.
 *
 * Surfaces the five canned reports the bid promises:
 *   1. Documents by batch
 *   2. Documents by creator / notary
 *   3. Documents by series
 *   4. Documents pending disinfestation
 *   5. Box movement history
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
    protected static string $view = 'filament.pages.reports';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 90;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        return [
            'cards' => $this->cards(),
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
                ];
            },
        );

        return [
            'documents' => number_format((int) $counts['documents']),
            'pending' => number_format((int) $counts['pending']),
            'movements' => number_format((int) $counts['movements']),
        ];
    }
}
