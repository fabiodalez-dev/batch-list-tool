<?php

declare(strict_types=1);

namespace App\Filament\Widgets\Activity;

use App\Support\Reports\ActivitySeries;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

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
    private const array PALETTE = ['#2563eb', '#f59e0b', '#dc2626', '#16a34a'];

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
            default => null,
        };
    }

    protected function getType(): string
    {
        return $this->kind === 'changes' ? 'bar' : 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $series = Cache::remember(
            'activity:' . $this->kind . ':u=' . (Auth::id() ?? 0),
            now()->addMinutes(5),
            fn (): array => match ($this->kind) {
                'moves' => ActivitySeries::moves(),
                'disinfestations' => ActivitySeries::disinfestations(),
                'cataloguing' => ActivitySeries::cataloguing(),
                default => ActivitySeries::changes(),
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
                ActivitySeries::months(),
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
                'y' => ['stacked' => $this->kind === 'changes', 'beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }
}
