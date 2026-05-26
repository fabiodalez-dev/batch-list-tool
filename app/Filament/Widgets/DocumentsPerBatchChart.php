<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Batch;
use App\Models\Document;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Bar chart: top 15 batches by document count.
 * Filterable by collection segment per RFQ §4:
 *   - all                — every batch
 *   - main_collection    — batch_number 1..29
 *   - notary_accessions  — batch_number >= 30 (excluding wills)
 *   - wills              — batch_number = 50 only
 *
 * Honours RepositoryScope (via Batch/Document models).
 */
class DocumentsPerBatchChart extends ChartWidget
{
    public ?string $filter = 'all';

    protected static ?int $sort = 4;

    protected ?string $heading = 'Top 15 Batches by Document Count';

    protected ?string $maxHeight = '300px';

    protected int|string|array $columnSpan = 1;

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<scalar, scalar>
     */
    protected function getFilters(): ?array
    {
        return [
            'all' => 'All batches',
            'main_collection' => 'Main Collection (1-29)',
            'notary_accessions' => 'Notary Accessions (30+)',
            'wills' => 'Wills (50)',
        ];
    }

    protected function getData(): array
    {
        $filter = $this->filter ?? 'all';

        $rows = Cache::remember(
            $this->cacheKey($filter),
            now()->addMinutes(5),
            function () use ($filter): array {
                // Constrain the batch set first (cheap), then aggregate documents.
                $batchQuery = Batch::query();
                match ($filter) {
                    'main_collection' => $batchQuery->whereBetween('batch_number', [1, Batch::MAIN_COLLECTION_MAX]),
                    'notary_accessions' => $batchQuery
                        ->where('batch_number', '>=', 30)
                        ->where('batch_number', '!=', Batch::WILLS_BATCH),
                    'wills' => $batchQuery->where('batch_number', Batch::WILLS_BATCH),
                    default => null,
                };

                $batchIds = $batchQuery->pluck('batch_number', 'id')->all();
                if (empty($batchIds)) {
                    return [];
                }

                $counts = Document::query()
                    ->whereIn('batch_id', array_keys($batchIds))
                    ->select('batch_id', DB::raw('COUNT(*) as c'))
                    ->groupBy('batch_id')
                    ->pluck('c', 'batch_id')
                    ->all();

                // Build {batch_number => count}, sort by count desc, take 15.
                $merged = [];
                foreach ($batchIds as $id => $num) {
                    $merged[$num] = (int) ($counts[$id] ?? 0);
                }
                arsort($merged);

                return array_slice($merged, 0, 15, true);
            },
        );

        return [
            'datasets' => [
                [
                    'label' => 'Documents',
                    'data' => array_values($rows),
                    'backgroundColor' => 'rgba(59, 130, 246, 0.6)',
                    'borderColor' => 'rgb(59, 130, 246)',
                    'borderWidth' => 1,
                ],
            ],
            'labels' => array_map(fn ($n) => 'Batch ' . $n, array_keys($rows)),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                ],
            ],
        ];
    }

    protected function cacheKey(string $filter): string
    {
        $user = Auth::user();
        $uid = $user?->getKey() ?? 'guest';

        return "dashboard:chart:batch:u={$uid}:f={$filter}";
    }
}
