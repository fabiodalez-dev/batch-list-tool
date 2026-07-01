<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Document;
use App\Models\Series;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Doughnut chart: documents broken down by Series code (R, REG, RWL, O, …).
 * Honours RepositoryScope through the Document model.
 */
class DocumentsPerSeriesChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Documents by Series';

    protected ?string $maxHeight = '300px';

    protected int|string|array $columnSpan = 1;

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $rows = Cache::remember(
            $this->cacheKey(),
            now()->addMinutes(5),
            function (): array {
                // Counts grouped by series_id, then resolve codes via a single
                // additional Series lookup — avoids N+1 and avoids assuming
                // a JOIN-friendly query builder for SQLite/MySQL parity.
                $byId = Document::query()
                    ->select('series_id', DB::raw('COUNT(*) as c'))
                    ->whereNotNull('series_id')
                    ->groupBy('series_id')
                    ->pluck('c', 'series_id')
                    ->all();

                if (empty($byId)) {
                    return [];
                }

                $codes = Series::query()
                    ->whereIn('id', array_keys($byId))
                    ->pluck('code', 'id')
                    ->all();

                $merged = [];
                foreach ($byId as $seriesId => $count) {
                    $code = $codes[$seriesId] ?? ('#' . $seriesId);
                    $merged[$code] = (int) $count;
                }
                arsort($merged);

                return $merged;
            },
        );

        $values = array_values($rows);

        return [
            'datasets' => [
                [
                    'label' => 'Documents',
                    'data' => $values,
                    // Without an explicit per-segment palette Filament fills every
                    // doughnut slice with the single primary colour, which on the
                    // NAf light theme resolves to a near-white cream (#EFF3F4) —
                    // making the whole chart invisible. Give each slice a distinct,
                    // saturated colour so the breakdown is actually readable.
                    'backgroundColor' => self::segmentColors(count($values)),
                    'borderColor' => '#ffffff',
                    'borderWidth' => 2,
                ],
            ],
            'labels' => array_keys($rows),
        ];
    }

    /**
     * A categorical palette of distinct, saturated colours (visible on the light
     * NAf paper theme), cycled to cover $count slices. Starts on the brand green.
     *
     * @return list<string>
     */
    protected static function segmentColors(int $count): array
    {
        $palette = [
            '#4A6F77', '#C2703D', '#5B8A72', '#8E5B9F', '#C24D5B',
            '#3E7CB1', '#B58B2A', '#6D8C3A', '#A0522D', '#4C9AA8',
            '#8C6D4A', '#7A5C99', '#B85C8A', '#5F9E6E', '#9C7A3C',
            '#3D6B8C', '#A94E4E', '#547C8A', '#7E8B3D', '#96588C',
        ];

        $out = [];
        for ($i = 0; $i < max(0, $count); $i++) {
            $out[] = $palette[$i % count($palette)];
        }

        return $out;
    }

    protected function cacheKey(): string
    {
        $user = Auth::user();
        $uid = $user?->getKey() ?? 'guest';

        return "dashboard:chart:series:u={$uid}";
    }
}
