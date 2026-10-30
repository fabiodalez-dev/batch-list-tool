<?php

declare(strict_types=1);

namespace App\Filament\Support\Operators;

use Filament\Forms\Components\TextInput;
use Filament\QueryBuilder\Constraints\Operators\Operator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Feedback1 Wave B (B2) — "worked between X and Y", now a rule of the
 * Creators list. A creator worked in [from, to] when the practice years
 * overlap it; a missing start or end year is open-ended.
 */
class WorkedBetweenOperator extends Operator
{
    public function getName(): string
    {
        return 'workedBetween';
    }

    public function getLabel(): string
    {
        return $this->isInverse() ? 'Did not work between' : 'Worked between';
    }

    public function getSummary(): string
    {
        $from = $this->getNumericSetting('from');
        $to = $this->getNumericSetting('to');
        $range = trim(($from !== null ? 'from ' . (int) $from : '') . ' ' . ($to !== null ? 'to ' . (int) $to : ''));

        return ($this->isInverse() ? 'Did not work ' : 'Worked ') . ($range === '' ? 'any time' : $range);
    }

    public function getFormSchema(): array
    {
        return [
            TextInput::make('from')
                ->label('Worked after / from (year)')
                ->numeric()
                ->integer(),
            TextInput::make('to')
                ->label('Worked before / to (year)')
                ->numeric()
                ->integer(),
        ];
    }

    public function applyToBaseQuery(Builder $query): Builder
    {
        $from = $this->getNumericSetting('from');
        $to = $this->getNumericSetting('to');
        if ($from === null && $to === null) {
            return $query;
        }

        $worked = function (Builder $q) use ($from, $to): void {
            if ($from !== null) {
                $q->where(fn (Builder $q): Builder => $q->whereNull($q->qualifyColumn('practice_dates_end'))->orWhere($q->qualifyColumn('practice_dates_end'), '>=', (int) $from));
            }
            if ($to !== null) {
                $q->where(fn (Builder $q): Builder => $q->whereNull($q->qualifyColumn('practice_dates_start'))->orWhere($q->qualifyColumn('practice_dates_start'), '<=', (int) $to));
            }
        };

        return $this->isInverse() ? $query->whereNot($worked) : $query->where($worked);
    }
}
