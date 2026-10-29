<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Forms\Components\Select;
use Filament\QueryBuilder\Forms\Components\RuleBuilder;
use Filament\Tables\Filters\QueryBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Add rule" with one choice above the rules: match all of them (AND) or
 * any of them (OR).
 *
 * Client 2026-10-29: "OR condition is not working — can we have the AND/OR
 * higher up as an option rather than as condition?" Filament's OR is a
 * nested "OR condition" rule whose groups were easy to leave empty (an
 * empty group matches everything) and, nested again, exceeded Livewire's
 * payload depth and failed. Each rule's operator already offers its NOT
 * ("Does not equal", "Is not"), so AND / OR / NOT read top to bottom.
 */
class MatchQueryBuilder extends QueryBuilder
{
    public const MATCH_ALL = 'all';

    public const MATCH_ANY = 'any';

    protected function setUp(): void
    {
        parent::setUp();

        // No nested "OR condition" rule: the choice above replaces it.
        $this->maxNestingDepth(1);

        $this->schema(fn (QueryBuilder $filter): array => [
            Select::make('match')
                ->label('Show documents that match')
                ->options([
                    self::MATCH_ALL => 'All of the rules (AND)',
                    self::MATCH_ANY => 'Any of the rules (OR)',
                ])
                ->default(self::MATCH_ALL)
                ->selectablePlaceholder(false)
                ->native(false),
            RuleBuilder::make('rules')
                ->label($filter->getLabel())
                ->hiddenLabel()
                ->constraints($filter->getConstraints())
                ->blockPickerColumns($filter->getConstraintPickerColumns())
                ->blockPickerWidth($filter->getConstraintPickerWidth())
                ->maxRules($filter->getMaxRules())
                ->maxNestingDepth($filter->getMaxNestingDepth()),
        ]);

        $this->query(function (Builder $query, array $data): void {
            $rules = (array) ($data['rules'] ?? []);
            if ($this->exceedsRuleLimits($rules)) {
                return;
            }

            if (! self::matchesAny($data) || count($rules) < 2) {
                $this->applyRulesToQuery($query, $rules, $this->getRuleBuilder());

                return;
            }

            // One OR branch per rule. A rule left incomplete adds nothing to
            // its branch, and Laravel drops an empty branch, so it never turns
            // the OR into "everything".
            $ruleBuilder = $this->getRuleBuilder();
            $query->where(function (Builder $any) use ($rules, $ruleBuilder): void {
                foreach ($rules as $index => $rule) {
                    $any->orWhere(fn (Builder $one): Builder => $this->applyRulesToQuery($one, [$index => $rule], $ruleBuilder));
                }
            });
        });

        $this->indicateUsing(function (array $state): array {
            $rules = (array) ($state['rules'] ?? []);
            if ($this->exceedsRuleLimits($rules)) {
                return [];
            }

            $summaries = $this->getTable()->withAppliedFiltersFormState(fn (): array => $this->getRuleSummaries($rules, $this->getRuleBuilder()));

            return self::matchesAny($state) && count($summaries) > 1
                ? ['Any of: ' . implode(' | ', $summaries)]
                : $summaries;
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function matchesAny(array $data): bool
    {
        return ($data['match'] ?? self::MATCH_ALL) === self::MATCH_ANY;
    }
}
