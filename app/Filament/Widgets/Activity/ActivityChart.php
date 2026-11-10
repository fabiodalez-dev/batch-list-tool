<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Activity;

use App\Support\Reports\ActivitySeries;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;

/**
 * One month-by-month chart of the "Activity over time" report. $kind picks the
 * series: changes, moves, disinfestations or cataloguing (see ActivitySeries).
 *
 * No polling and a 5-minute cache per user and kind: the figures move with
 * imports and edits, not by the second, and each user's repository scope gives
 * them their own numbers.
 */
class ActivityChart extends ChartWidget
{
    // The Activity over time report's month range (from, to); the dashboard
    // has none and shows the last twelve months.
    use InteractsWithPageFilters;

    /** NRA & NAF brand colours: knowledge, research centre, publications, conservation. */
    private const array PALETTE = ['#4A6F77', '#C6A24E', '#912B36', '#746B64'];

    /** Set by the page; locked so a browser cannot switch a chart to another series. */
    #[Locked]
    public string $kind = 'changes';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '280px';

    protected int|string|array $columnSpan = 1;

    public function getHeading(): string
    {
        return match ($this->kind) {
            'changes' => 'Changes recorded per month',
            'moves' => 'Moves per month',
            'disinfestations' => 'Disinfestations per month',
            'cataloguing' => 'Documents catalogued per month',
            'collection' => 'Documents in the repository',
            default => 'Activity',
        };
    }

    public function getDescription(): ?string
    {
        return match ($this->kind) {
            'changes' => 'From the audit trail: records created, changed and deleted. Imports count as changes too.',
            'moves' => 'Documents between boxes, boxes and documents between locations — recorded moves only.',
            'disinfestations' => 'By disinfestation date of boxes and of documents.',
            'cataloguing' => 'Documents given a catalogue identifier, by the month it was given.',
            'collection' => 'Total documents at the end of each month. A document counts from the day it was entered in the system, so the imported collection shows in the month of its import.',
            default => null,
        };
    }

    protected function getType(): string
    {
        return $this->kind === 'changes' ? 'bar' : 'line';
    }

    /**
     * @return list<string>
     */
    protected function months(): array
    {
        $from = $this->pageFilters['from'] ?? null;
        $to = $this->pageFilters['to'] ?? null;

        return ActivitySeries::months(12, is_string($from) ? $from : null, is_string($to) ? $to : null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        // The whole-archive audit chart, for those allowed to read the audit
        // trail only — checked here as well as where the chart is placed.
        if ($this->kind === 'changes' && ! (Auth::user()?->can('view_any_audit') ?? false)) {
            return ['datasets' => [], 'labels' => []];
        }

        $months = $this->months();
        $series = Cache::remember(
            'activity:' . $this->kind . ':u=' . (Auth::id() ?? 0) . ':' . $months[0] . '..' . end($months),
            now()->addMinutes(5),
            fn (): array => match ($this->kind) {
                'moves' => ActivitySeries::moves($months),
                'disinfestations' => ActivitySeries::disinfestations($months),
                'cataloguing' => ActivitySeries::cataloguing($months),
                'collection' => ActivitySeries::collection($months),
                default => ActivitySeries::changes($months),
            },
        );

        $datasets = [];
        $i = 0;
        foreach ($series as $label => $values) {
            $colour = self::PALETTE[$i++ % count(self::PALETTE)];
            $datasets[] = [
                'label' => $label,
                'data' => $values,
                'backgroundColor' => $colour,
                'borderColor' => $colour,
                'tension' => 0.25,
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => array_map(
                static fn (string $m): string => Carbon::parse($m . '-01')->format('M Y'),
                $months,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => $this->kind === 'changes'],
                'y' => ['stacked' => $this->kind === 'changes', 'beginAtZero' => $this->kind !== 'collection', 'ticks' => ['precision' => 0]],
            ],
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }
}
