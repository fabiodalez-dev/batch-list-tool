<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Models\Box;
use App\Models\BoxLocationHistory;
use App\Models\BoxMovement;
use App\Models\Document;
use App\Models\DocumentLocationHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Models\Audit;

/**
 * Month-by-month counts of what happened in the archive, for the "Activity over
 * time" charts: changes recorded, moves, disinfestations and cataloguing.
 *
 * Every series covers the same months — the last N, oldest first, empty months
 * as zero — so the charts line up. Months are grouped in SQL with the driver's
 * own date function (MySQL/MariaDB in production, SQLite in tests).
 *
 * Repository scope applies wherever the model has it: an editor sees the
 * activity of their own repositories. The audit trail has no repository column,
 * so its series is the whole archive — which is why that chart is shown only to
 * those allowed to read the audit trail.
 */
final class ActivitySeries
{
    /**
     * @return list<string> 'YYYY-MM', oldest first
     */
    public static function months(int $count = 12): array
    {
        $months = [];
        $start = Carbon::now()->startOfMonth()->subMonths($count - 1);
        for ($i = 0; $i < $count; $i++) {
            $months[] = $start->copy()->addMonths($i)->format('Y-m');
        }

        return $months;
    }

    /**
     * @return array<string, list<int>> event => counts per month
     */
    public static function changes(int $count = 12): array
    {
        $months = self::months($count);
        $series = [];
        foreach (['created' => 'Created', 'updated' => 'Changed', 'deleted' => 'Deleted'] as $event => $label) {
            $series[$label] = self::perMonth(Audit::query()->where('event', $event), 'created_at', $months);
        }

        return $series;
    }

    /**
     * @return array<string, list<int>>
     */
    public static function moves(int $count = 12): array
    {
        $months = self::months($count);

        return [
            'Documents moved between boxes' => self::perMonth(
                BoxMovement::query()->whereNotNull('movement_date')->where('date_source', BoxMovement::DATE_SOURCE_RECORDED),
                'movement_date',
                $months,
            ),
            'Boxes moved between locations' => self::perMonth(BoxLocationHistory::query()->where('source', 'update'), 'changed_at', $months),
            'Documents moved between locations' => self::perMonth(DocumentLocationHistory::query()->where('source', 'update'), 'changed_at', $months),
        ];
    }

    /**
     * @return array<string, list<int>>
     */
    public static function disinfestations(int $count = 12): array
    {
        $months = self::months($count);

        return [
            'Boxes disinfested' => self::perMonth(Box::query(), 'disinfestation_date', $months),
            'Documents disinfested' => self::perMonth(Document::query(), 'disinfestation_date', $months),
        ];
    }

    /**
     * Documents given a catalogue identifier, by the month it was given — read
     * from the audit trail: an update whose old value was blank and whose new
     * value is not. There is no catalogued_at column to count instead.
     *
     * @return array<string, list<int>>
     */
    public static function cataloguing(int $count = 12): array
    {
        $months = self::months($count);
        $counts = array_fill_keys($months, 0);

        Audit::query()
            ->where('auditable_type', (new Document)->getMorphClass())
            ->where('event', 'updated')
            ->where('new_values', 'like', '%"catalogue_identifier"%')
            ->where('created_at', '>=', Carbon::parse($months[0] . '-01'))
            ->select(['id', 'old_values', 'new_values', 'created_at'])
            ->chunkById(1000, function ($audits) use (&$counts): void {
                foreach ($audits as $audit) {
                    $old = (array) ($audit->getAttribute('old_values') ?? []);
                    $new = (array) ($audit->getAttribute('new_values') ?? []);
                    $was = trim((string) ($old['catalogue_identifier'] ?? ''));
                    $now = trim((string) ($new['catalogue_identifier'] ?? ''));
                    $month = $audit->getAttribute('created_at')?->format('Y-m');
                    if ($was === '' && $now !== '' && $month !== null && array_key_exists($month, $counts)) {
                        $counts[$month]++;
                    }
                }
            });

        return ['Documents catalogued' => array_values($counts)];
    }

    /**
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     * @param list<string> $months
     * @return list<int>
     */
    private static function perMonth(Builder $query, string $column, array $months): array
    {
        $model = $query->getModel();
        $qualified = $model->qualifyColumn($column);
        $month = self::monthExpression($qualified, $model->getConnection()->getDriverName());

        $rows = $query
            ->whereNotNull($qualified)
            ->where($qualified, '>=', Carbon::parse($months[0] . '-01')->startOfDay())
            ->where($qualified, '<', Carbon::parse(end($months) . '-01')->addMonth()->startOfDay())
            ->selectRaw("{$month} as month, COUNT(*) as total")
            ->groupBy(DB::raw($month))
            ->pluck('total', 'month')
            ->all();

        return array_map(static fn (string $m): int => (int) ($rows[$m] ?? 0), $months);
    }

    private static function monthExpression(string $column, string $driver): string
    {
        return match ($driver) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }
}
