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
    /** The longest range a chart draws: ten years of months. */
    public const int MAX_MONTHS = 120;

    /**
     * The last $count months, or the months from $from to $to ('YYYY-MM',
     * either may be blank: client 2026-11-10 asked for a date range). A
     * reversed range is turned round, and a range longer than MAX_MONTHS
     * keeps its last MAX_MONTHS.
     *
     * @return list<string> 'YYYY-MM', oldest first
     */
    public static function months(int $count = 12, ?string $from = null, ?string $to = null): array
    {
        $parse = static function (?string $month): ?Carbon {
            if ($month === null || preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
                return null;
            }

            try {
                return Carbon::createFromFormat('Y-m-d', $month . '-01')?->startOfMonth();
            } catch (\Throwable) {
                return null;
            }
        };

        $end = $parse($to) ?? Carbon::now()->startOfMonth();
        $start = $parse($from) ?? $end->copy()->subMonths($count - 1);
        if ($start->greaterThan($end)) {
            [$start, $end] = [$end, $start];
        }
        if ((int) $start->diffInMonths($end) >= self::MAX_MONTHS) {
            $start = $end->copy()->subMonths(self::MAX_MONTHS - 1);
        }

        $months = [];
        for ($month = $start->copy(); $month->lessThanOrEqualTo($end); $month->addMonth()) {
            $months[] = $month->format('Y-m');
        }

        return $months;
    }

    /**
     * @return array<string, list<int>> event => counts per month
     */
    public static function changes(?array $months = null): array
    {
        $months ??= self::months();
        $series = [];
        foreach (['created' => 'Created', 'updated' => 'Changed', 'deleted' => 'Deleted'] as $event => $label) {
            $series[$label] = self::perMonth(Audit::query()->where('event', $event), 'created_at', $months);
        }

        return $series;
    }

    /**
     * @return array<string, list<int>>
     */
    public static function moves(?array $months = null): array
    {
        $months ??= self::months();

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
    public static function disinfestations(?array $months = null): array
    {
        $months ??= self::months();

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
    public static function cataloguing(?array $months = null): array
    {
        $months ??= self::months();
        $counts = array_fill_keys($months, 0);

        Audit::query()
            ->where('auditable_type', (new Document)->getMorphClass())
            ->where('event', 'updated')
            ->where('new_values', 'like', '%"catalogue_identifier"%')
            // The audit trail has no repository: only documents the user may
            // see (their repositories, deleted ones included) are counted.
            ->whereIn('auditable_id', Document::withTrashed()->select('documents.id'))
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
     * How many documents the repository held at the end of each month (client
     * 2026-11-10: "to be able to see how much we are expanding the
     * collection"): those entered before the first month and not deleted by
     * then, plus each month's new documents, less those deleted. A document
     * counts from the day it was entered in the system: the imported
     * collection appears in the month it was imported.
     *
     * @param list<string>|null $months
     * @return array<string, list<int>>
     */
    public static function collection(?array $months = null): array
    {
        $months ??= self::months();
        $start = Carbon::parse($months[0] . '-01')->startOfDay();

        $held = Document::withTrashed()
            ->where('created_at', '<', $start)
            ->where(fn (Builder $q) => $q->whereNull('deleted_at')->orWhere('deleted_at', '>=', $start))
            ->count();
        $added = self::perMonth(Document::withTrashed(), 'created_at', $months);
        $removed = self::perMonth(Document::onlyTrashed(), 'deleted_at', $months);

        $totals = [];
        foreach ($months as $i => $_) {
            $held += $added[$i] - $removed[$i];
            $totals[] = $held;
        }

        return ['Documents in the repository' => $totals];
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
