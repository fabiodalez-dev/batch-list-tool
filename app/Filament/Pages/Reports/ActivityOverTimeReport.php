<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Widgets\Activity\ActivityChart;
use App\Support\Reports\ActivitySeries;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Widgets\WidgetConfiguration;

/**
 * "Activity over time": the last twelve months of the archive, month by month
 * — changes recorded, moves, disinfestations and cataloguing. The history of
 * single records lives on their History tab; this is the same history in
 * aggregate.
 *
 * Client 2026-11-10: the charts are drawn for a chosen range of months (the
 * last twelve when none is chosen), and a fifth chart shows how many
 * documents the repository held month by month.
 *
 * The changes chart reads the audit trail, which carries no repository, so it
 * shows the whole archive and is shown only to those allowed to read it.
 */
class ActivityOverTimeReport extends Page
{
    use ExplainsPage;
    use HasFiltersForm;

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

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->columns(['md' => 2, 'xl' => 4])
            ->components([
                TextInput::make('from')
                    ->label('From month')
                    ->type('month')
                    ->helperText('Blank: twelve months before the end month.'),
                TextInput::make('to')
                    ->label('To month')
                    ->type('month')
                    ->helperText('Blank: this month. At most ' . ActivitySeries::MAX_MONTHS / 12 . ' years are drawn.'),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedSchema::make('filtersForm'),
            Grid::make(['md' => 2])
                ->schema(fn (): array => $this->getWidgetsSchemaComponents($this->charts())),
        ]);
    }

    /**
     * @return array<int, WidgetConfiguration>
     */
    public function charts(): array
    {
        $widgets = [ActivityChart::make(['kind' => 'collection'])];
        if (auth()->user()?->can('view_any_audit')) {
            $widgets[] = ActivityChart::make(['kind' => 'changes']);
        }
        $widgets[] = ActivityChart::make(['kind' => 'moves']);
        $widgets[] = ActivityChart::make(['kind' => 'disinfestations']);
        $widgets[] = ActivityChart::make(['kind' => 'cataloguing']);

        return $widgets;
    }
}
