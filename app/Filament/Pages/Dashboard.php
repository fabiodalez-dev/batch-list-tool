<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\Activity\ActivityChart;
use App\Filament\Widgets\DocumentsPerSeriesChart;
use App\Filament\Widgets\RecentActivityWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

/**
 * The dashboard in the order the client asked for on 2026-11-10: the
 * overview first, then changes, cataloguing and moves per month, documents
 * by subseries, and recent activity at the end. Pending disinfestation is off
 * the dashboard "for the time being"; it stays under Reports.
 *
 * The list is explicit rather than every discovered widget sorted by $sort,
 * so a widget added to app/Filament/Widgets does not land here by itself.
 */
class Dashboard extends BaseDashboard
{
    /**
     * @return array<class-string<Widget>|WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        $widgets = [StatsOverviewWidget::class];

        // The audit trail carries no repository: shown only to those allowed
        // to read it, as on the Activity over time report.
        if (auth()->user()?->can('view_any_audit')) {
            $widgets[] = ActivityChart::make(['kind' => 'changes']);
        }

        $widgets[] = ActivityChart::make(['kind' => 'cataloguing']);
        $widgets[] = ActivityChart::make(['kind' => 'moves']);
        $widgets[] = DocumentsPerSeriesChart::class;
        $widgets[] = RecentActivityWidget::class;

        return $widgets;
    }
}
