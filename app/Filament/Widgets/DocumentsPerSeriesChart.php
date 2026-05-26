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

        return [
            'datasets' => [
                [
                    'label' => 'Documents',
                    'data' => array_values($rows),
                ],
            ],
            'labels' => array_keys($rows),
        ];
    }

    protected function cacheKey(): string
    {
        $user = Auth::user();
        $uid = $user?->getKey() ?? 'guest';

        return "dashboard:chart:series:u={$uid}";
    }
}
