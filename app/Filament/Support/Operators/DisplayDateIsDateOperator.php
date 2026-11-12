<?php

declare(strict_types=1);

namespace App\Filament\Support\Operators;

use Filament\QueryBuilder\Constraints\DateConstraint\Operators\IsDateOperator;

class DisplayDateIsDateOperator extends IsDateOperator
{
    use SummarisesDisplayDate;

    public function getSummary(): string
    {
        // "is" a day: Filament shows no time here either.
        return $this->displaySummaryFor('is_date', $this->fixedDate(), false);
    }
}
