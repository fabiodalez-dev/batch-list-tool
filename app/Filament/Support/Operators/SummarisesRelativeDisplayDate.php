<?php

declare(strict_types=1);

namespace App\Filament\Support\Operators;

use Illuminate\Support\Carbon;

/**
 * SummarisesDisplayDate for the operators that also take a relative date
 * ("today", "past year"): is after and is before.
 */
trait SummarisesRelativeDisplayDate
{
    use SummarisesDisplayDate;

    /**
     * The chip of a fixed or relative date (is after / is before).
     *
     * @param string $key the operator's translation key, e.g. "is_after"
     */
    protected function displayDateSummary(string $key): string
    {
        $settings = $this->getSettings() ?? [];
        $hasTime = $this->constraintHasTime();

        if (($settings['mode'] ?? null) === 'relative') {
            return $this->displaySummaryFor(
                $key,
                Carbon::parse($this->resolveRelativeDate($settings, $hasTime)),
                $hasTime && $this->isTimeBasedFilter($settings),
            );
        }

        return $this->displaySummaryFor($key, $this->fixedDate(), $hasTime);
    }
}
