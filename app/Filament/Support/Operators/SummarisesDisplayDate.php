<?php

declare(strict_types=1);

namespace App\Filament\Support\Operators;

use App\Support\DisplayDate;
use Filament\QueryBuilder\Constraints\DateConstraint;
use Illuminate\Support\Carbon;

/**
 * Client 2026-10-30, issue 11: dates dd-mm-yyyy throughout the system.
 * Filament writes the chip of a date rule with toFormattedDateString()
 * ("Mar 5, 2026"); these operators write the same sentence with the
 * system's display format. Used by ListRules::date().
 */
trait SummarisesDisplayDate
{
    protected function constraintHasTime(): bool
    {
        $constraint = $this->getConstraint();

        return $constraint instanceof DateConstraint && $constraint->hasTime();
    }

    protected function fixedDate(): ?Carbon
    {
        $raw = $this->getDateSetting('date');

        return $raw === null ? null : Carbon::parse($raw);
    }

    protected function displaySummaryFor(string $key, ?Carbon $date, bool $withTime): string
    {
        return __(
            "filament-query-builder::query-builder.operators.date.{$key}.summary." . ($this->isInverse() ? 'inverse' : 'direct'),
            [
                'attribute' => $this->getConstraint()?->getAttributeLabel(),
                'date' => $date?->format($withTime ? DisplayDate::DATE_TIME_SECONDS : DisplayDate::DATE),
            ],
        );
    }
}
