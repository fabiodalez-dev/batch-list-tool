<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Widgets\Activity\ActivityChart;
use Filament\Pages\Page;
use Filament\Widgets\WidgetConfiguration;

/**
 * "Activity over time": the last twelve months of the archive, month by month
 * — changes recorded, moves, disinfestations and cataloguing. The history of
 * single records lives on their History tab; this is the same history in
 * aggregate.
 *
 * The changes chart reads the audit trail, which carries no repository, so it
 * shows the whole archive and is shown only to those allowed to read it.
 */
class ActivityOverTimeReport extends Page
{
    use ExplainsPage;

    protected string $view = 'filament.pages.reports.activity';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'Activity over time';

    protected static ?string $slug = 'reports/activity-over-time';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('view_any_report');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return ['md' => 2];
    }

    /**
     * @return array<int, WidgetConfiguration>
     */
    protected function getHeaderWidgets(): array
    {
        $widgets = [];
        if (auth()->user()?->can('view_any_audit')) {
            $widgets[] = ActivityChart::make(['kind' => 'changes']);
        }
        $widgets[] = ActivityChart::make(['kind' => 'moves']);
        $widgets[] = ActivityChart::make(['kind' => 'disinfestations']);
        $widgets[] = ActivityChart::make(['kind' => 'cataloguing']);

        return $widgets;
    }
}
