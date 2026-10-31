<?php

declare(strict_types=1);

namespace App\Filament\Support\Operators;

use Filament\QueryBuilder\Constraints\DateConstraint\Operators\IsBeforeOperator;

class DisplayDateIsBeforeOperator extends IsBeforeOperator
{
    use SummarisesRelativeDisplayDate;

    public function getSummary(): string
    {
        return $this->displayDateSummary('is_before');
    }
}
