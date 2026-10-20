<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Tables\Filters\QueryBuilder\Constraints\NumberConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\NumberConstraint\Operators as NumberOps;
use Filament\Tables\Filters\QueryBuilder\Constraints\Operators\IsFilledOperator;
use Filament\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\TextConstraint\Operators as TextOps;

/**
 * "Add rule" constraints whose operator starts on Equals.
 *
 * Client 2026-10-19: "Can default be Equals instead of contains for all
 * options?" A new rule takes the first operator of the list; Filament lists
 * Contains (text) and Is minimum (number) first.
 */
final class EqualsFirst
{
    public static function text(string $name, string $label, bool $nullable = false): TextConstraint
    {
        $constraint = TextConstraint::make($name)->label($label);
        if ($nullable) {
            $constraint->nullable();
        }

        return $constraint->operators([
            TextOps\EqualsOperator::class,
            TextOps\ContainsOperator::class,
            TextOps\StartsWithOperator::class,
            TextOps\EndsWithOperator::class,
            IsFilledOperator::make()->visible(fn (): bool => $constraint->isNullable()),
        ]);
    }

    public static function number(string $name, string $label): NumberConstraint
    {
        return NumberConstraint::make($name)->label($label)->integer()->operators([
            NumberOps\EqualsOperator::class,
            NumberOps\IsMinOperator::class,
            NumberOps\IsMaxOperator::class,
        ]);
    }
}
