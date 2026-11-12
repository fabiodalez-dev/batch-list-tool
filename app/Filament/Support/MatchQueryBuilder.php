<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Forms\Components\Hidden;
use Filament\QueryBuilder\Forms\Components\RuleBuilder;
use Filament\Tables\Filters\QueryBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Add rule", each rule after the first joined to the rules above it by AND,
 * OR or AND NOT (client 2026-11-06, see ConnectorRuleBuilder).
 *
 * Client 2026-10-29 had asked for the AND/OR "higher up as an option rather
 * than as condition": Filament's nested "OR condition" was easy to leave
 * empty (an empty group matches everything) and, nested again, exceeded
 * Livewire's payload depth. Per-rule joins keep the list flat.
 */
class MatchQueryBuilder extends QueryBuilder
{
    public const MATCH_ALL = 'all';

    public const MATCH_ANY = 'any';

    /**
     * The `filters` query parameter of a link that opens a list with one rule
     * applied. Hidden filters are not read from the URL, so a link into a list
     * carries a rule (client 2026-10-30: every list filters by rules only).
     *
     * @param array<string, mixed> $settings
     * @return array{queryBuilder: array{match: string, rules: array<string, array{type: string, data: array{operator: string, settings: array<string, mixed>}}>}}
     */
    public static function urlFilters(string $rule, string $operator, array $settings = []): array
    {
        return ['queryBuilder' => ['match' => self::MATCH_ALL, 'rules' => [$rule => [
            'type' => $rule,
            'data' => ['operator' => $operator, 'settings' => $settings],
        ]]]];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Client 2026-11-06: no more "OR group"; each rule carries its own
        // AND / OR / NOT (ConnectorRuleBuilder). A link or saved filter with an
        // OR group from before is not applied rather than half applied.
        $this->maxNestingDepth(1);

        $this->schema(fn (QueryBuilder $filter): array => [
            // Kept for links made with the list-wide choice: "any" makes the
            // rules that name no join of their own OR.
            Hidden::make('match')->default(self::MATCH_ALL),
            ConnectorRuleBuilder::make('rules')
                ->label($filter->getLabel())
                ->hiddenLabel()
                ->constraints($filter->getConstraints())
                ->blockPickerColumns($filter->getConstraintPickerColumns())
                ->blockPickerWidth($filter->getConstraintPickerWidth())
                ->maxRules($filter->getMaxRules())
                ->maxNestingDepth($filter->getMaxNestingDepth())
                // A list may open on a rule (Flags: the open ones), set with
                // ->default(['rules' => [...]]); Reset brings it back.
                ->default(fn (): ?array => $filter->getDefaultState()['rules'] ?? null),
        ]);

        $this->query(function (Builder $query, array $data): void {
            $rules = (array) ($data['rules'] ?? []);
            if ($this->exceedsRuleLimits($rules)) {
                return;
            }

            $joins = self::joins($rules, $data);
            $ruleBuilder = $this->getRuleBuilder();

            if (! in_array(ConnectorRuleBuilder::OR, $joins, true) && ! in_array(ConnectorRuleBuilder::NOT, $joins, true)) {
                $this->applyRulesToQuery($query, $rules, $ruleBuilder);

                return;
            }

            $query->where(fn (Builder $all): Builder => $this->applyFromLeft($all, $rules, $joins, $ruleBuilder));
        });

        $this->indicateUsing(function (array $state): array {
            $rules = (array) ($state['rules'] ?? []);
            if ($this->exceedsRuleLimits($rules)) {
                return [];
            }

            $summaries = $this->getTable()->withAppliedFiltersFormState(fn (): array => $this->getRuleSummaries($rules, $this->getRuleBuilder()));
            $joins = self::joins($rules, $state);

            if (! in_array(ConnectorRuleBuilder::OR, $joins, true) && ! in_array(ConnectorRuleBuilder::NOT, $joins, true)) {
                return $summaries;
            }

            $text = '';
            foreach ($summaries as $index => $summary) {
                $join = $joins[$index] ?? ConnectorRuleBuilder::AND;
                $text .= $text === '' ? ($join === ConnectorRuleBuilder::NOT ? 'NOT ' : '') : ' ' . strtoupper($join === ConnectorRuleBuilder::NOT ? 'and not' : $join) . ' ';
                $text .= $summary;
            }

            return [$text];
        });
    }

    /**
     * How each rule joins the ones above it; the first joins nothing.
     *
     * @param array<string, mixed> $rules
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private static function joins(array $rules, array $data): array
    {
        $fallback = self::matchesAny($data) ? ConnectorRuleBuilder::OR : ConnectorRuleBuilder::AND;
        $joins = [];
        foreach ($rules as $index => $rule) {
            $joins[$index] = $joins === [] ? ConnectorRuleBuilder::AND : ConnectorRuleBuilder::connectorOf((array) $rule, $fallback);
        }

        return $joins;
    }

    /**
     * Left to right: each rule joins everything above it, so "A OR B AND C"
     * is (A OR B) AND C, as it reads. A rule left incomplete adds nothing, and
     * Laravel drops an empty group, so it never turns an OR into "everything".
     *
     * @param array<string, mixed> $rules
     * @param array<string, string> $joins
     */
    private function applyFromLeft(Builder $query, array $rules, array $joins, RuleBuilder $ruleBuilder): Builder
    {
        $last = array_key_last($rules);
        $rule = [$last => $rules[$last]];
        $before = $rules;
        unset($before[$last]);

        if ($before === []) {
            return $this->applyRulesToQuery($query, $rule, $ruleBuilder);
        }

        $query->where(fn (Builder $above): Builder => $this->applyFromLeft($above, $before, $joins, $ruleBuilder));
        $one = fn (Builder $q): Builder => $this->applyRulesToQuery($q, $rule, $ruleBuilder);

        return match ($joins[$last]) {
            ConnectorRuleBuilder::OR => $query->orWhere($one),
            ConnectorRuleBuilder::NOT => $query->whereNot($one),
            default => $query->where($one),
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function matchesAny(array $data): bool
    {
        return ($data['match'] ?? self::MATCH_ALL) === self::MATCH_ANY;
    }
}
