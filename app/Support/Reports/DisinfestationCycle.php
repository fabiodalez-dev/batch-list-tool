<?php

declare(strict_types=1);

namespace App\Support\Reports;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Q1 (NAF Queries) — disinfestation-cycle timing.
 *
 * Client answer: "a cycle is 40 days but the service provider often has delays
 * and so it can take up to 80 days." So from the last disinfestation date an
 * item is:
 *   - `current`  — within the 40-day cycle (not yet due),
 *   - `due`      — past 40 days, still inside the 80-day tolerance,
 *   - `overdue`  — past 80 days,
 *   - `never`    — no disinfestation date recorded at all.
 *
 * The client wants the "never disinfested" items surfaced FIRST, then the ones
 * going round for a second (or later) cycle — see {@see sortRank()}.
 *
 * Pure date math, no DB — so it is reusable for both the by-box and by-document
 * cycle views and unit-testable without a report page.
 */
final class DisinfestationCycle
{
    /** Nominal cycle length in days. */
    public const DUE_DAYS = 40;

    /** Upper tolerance before an item is overdue (service-provider delays). */
    public const OVERDUE_DAYS = 80;

    public const NEVER = 'never';

    public const CURRENT = 'current';

    public const DUE = 'due';

    public const OVERDUE = 'overdue';

    /**
     * Cycle status for the given last-disinfestation date.
     */
    public static function status(?CarbonInterface $lastDate, ?CarbonInterface $now = null): string
    {
        if ($lastDate === null) {
            return self::NEVER;
        }

        $now ??= CarbonImmutable::now();
        $days = $lastDate->startOfDay()->diffInDays($now->startOfDay());

        if ($days >= self::OVERDUE_DAYS) {
            return self::OVERDUE;
        }
        if ($days >= self::DUE_DAYS) {
            return self::DUE;
        }

        return self::CURRENT;
    }

    /**
     * Next due date = last disinfestation + 40 days. Null when never disinfested.
     */
    public static function dueDate(?CarbonInterface $lastDate): ?CarbonInterface
    {
        return $lastDate?->startOfDay()->addDays(self::DUE_DAYS);
    }

    /**
     * True when the item should appear in a cycle plan: never disinfested, or
     * past the 40-day mark. `current` items are not yet due.
     */
    public static function isPlannable(?CarbonInterface $lastDate, ?CarbonInterface $now = null): bool
    {
        return self::status($lastDate, $now) !== self::CURRENT;
    }

    /**
     * Ordering rank for the cycle plan — lower sorts first. Client wants the
     * never-disinfested boxes first, then re-cycle candidates (most overdue
     * before least). `current` items rank last (they aren't due yet).
     */
    public static function sortRank(string $status): int
    {
        return match ($status) {
            self::NEVER => 0,
            self::OVERDUE => 1,
            self::DUE => 2,
            default => 3,
        };
    }
}
