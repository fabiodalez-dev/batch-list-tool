<?php

declare(strict_types=1);

use App\Support\Reports\DisinfestationCycle as Cycle;
use Carbon\CarbonImmutable;

/**
 * Q1 (NAF Queries) — 40-day cycle, 80-day tolerance; never-disinfested first,
 * then most-overdue. Pure date math, reusable for by-box and by-document views.
 */
$now = CarbonImmutable::create(2026, 7, 1);

it('classifies the cycle status from the last disinfestation date', function () use ($now) {
    expect(Cycle::status(null, $now))->toBe(Cycle::NEVER)
        ->and(Cycle::status($now->subDays(10), $now))->toBe(Cycle::CURRENT)
        ->and(Cycle::status($now->subDays(39), $now))->toBe(Cycle::CURRENT)
        ->and(Cycle::status($now->subDays(40), $now))->toBe(Cycle::DUE)
        ->and(Cycle::status($now->subDays(79), $now))->toBe(Cycle::DUE)
        ->and(Cycle::status($now->subDays(80), $now))->toBe(Cycle::OVERDUE)
        ->and(Cycle::status($now->subDays(120), $now))->toBe(Cycle::OVERDUE);
});

it('computes the next due date at last + 40 days (null when never disinfested)', function () {
    $last = CarbonImmutable::create(2026, 1, 1);

    expect(Cycle::dueDate($last)->toDateString())->toBe('2026-02-10')
        ->and(Cycle::dueDate(null))->toBeNull();
});

it('marks an item plannable when never disinfested or past 40 days', function () use ($now) {
    expect(Cycle::isPlannable(null, $now))->toBeTrue()
        ->and(Cycle::isPlannable($now->subDays(10), $now))->toBeFalse()
        ->and(Cycle::isPlannable($now->subDays(45), $now))->toBeTrue()
        ->and(Cycle::isPlannable($now->subDays(90), $now))->toBeTrue();
});

it('ranks never-disinfested first, then overdue, then due, then current', function () {
    expect(Cycle::sortRank(Cycle::NEVER))->toBeLessThan(Cycle::sortRank(Cycle::OVERDUE))
        ->and(Cycle::sortRank(Cycle::OVERDUE))->toBeLessThan(Cycle::sortRank(Cycle::DUE))
        ->and(Cycle::sortRank(Cycle::DUE))->toBeLessThan(Cycle::sortRank(Cycle::CURRENT));
});
