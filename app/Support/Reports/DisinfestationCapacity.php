<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Models\Box;
use App\Models\Lookup\CurrentBoxType;

/**
 * Q2 (NAF Queries) — disinfestation-cycle capacity weighting.
 *
 * A "Big Brown Box" occupies the space of two ordinary boxes, so it counts as 2
 * against the per-cycle limit (RFQ App.2-ix); every other `current_box_type`
 * counts as 1. The weight is stored on the editable `current_box_types` lookup
 * (`counts_as`) so the client can retune it without a code change — this helper
 * is the single reader that turns those weights into cycle-capacity figures.
 *
 * `current_box_type` lives on the DOCUMENT (the physical container each record
 * sits in), so a box's weight is the heaviest container type among its
 * documents (they share one physical box), never below 1.
 */
final class DisinfestationCapacity
{
    /**
     * Memoised `current_box_type` label => counts_as weight.
     *
     * @var array<string, int>|null
     */
    private static ?array $weights = null;

    /** Reset the per-request memo (call in tests after editing the lookup). */
    public static function flushCache(): void
    {
        self::$weights = null;
    }

    /**
     * Cycle weight for a single `current_box_type` label. Unknown / null / blank
     * labels weigh 1 (a plain box).
     */
    public static function weightFor(?string $currentBoxType): int
    {
        if ($currentBoxType === null || $currentBoxType === '') {
            return 1;
        }

        self::$weights ??= CurrentBoxType::query()
            ->pluck('counts_as', 'code')
            ->map(fn ($weight): int => max(1, (int) ($weight ?? 1)))
            ->all();

        return self::$weights[$currentBoxType] ?? 1;
    }

    /**
     * Weight of one box = the heaviest `current_box_type` among its documents
     * (they share one physical container), never below 1.
     */
    public static function weightForBox(Box $box): int
    {
        $types = $box->relationLoaded('documents')
            ? $box->documents->pluck('current_box_type')
            : $box->documents()->distinct()->pluck('current_box_type');

        $weight = 1;
        foreach ($types as $type) {
            $weight = max($weight, self::weightFor(is_string($type) ? $type : null));
        }

        return $weight;
    }

    /**
     * Weighted total for a set of boxes — a Big Brown Box counts twice. Use this
     * for the "boxes used this cycle" figure against the per-cycle limit.
     *
     * @param iterable<Box> $boxes
     */
    public static function weightedBoxCount(iterable $boxes): int
    {
        $total = 0;
        foreach ($boxes as $box) {
            $total += self::weightForBox($box);
        }

        return $total;
    }
}
