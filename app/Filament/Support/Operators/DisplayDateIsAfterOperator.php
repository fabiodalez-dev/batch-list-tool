<?php

declare(strict_types=1);

namespace App\Filament\Support\Operators;

use Filament\QueryBuilder\Constraints\DateConstraint\Operators\IsAfterOperator;

class DisplayDateIsAfterOperator extends IsAfterOperator
{
    use SummarisesRelativeDisplayDate;

    public function getSummary(): string
    {
        return $this->displayDateSummary('is_after');
    }
}
