<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Box;
use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\Location;
use App\Support\LocationBreadcrumbCache;
use Filament\Tables\Filters\QueryBuilder\Constraints\Constraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\Operators\Operator;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint\Operators\IsOperator;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Add rule" rules of the Documents list that are not a plain column.
 *
 * Client 2026-10-29: the filter panel holds only "Add rule", so what the
 * panel's ready-made filters did (box now or earlier, custody status,
 * disinfested, flags) is a rule here. Every rule also offers its NOT
 * ("Is not", "Has no open flag").
 */
final class DocumentRules
{
    /**
     * Documents with an open flag of the chosen types; or with any open flag.
     */
    public static function flag(): SelectConstraint
    {
        return SelectConstraint::make('flag')
            ->label('Flag')
            ->icon('heroicon-o-flag')
            ->options(fn (): array => DocumentFlag::typeOptions())
            ->multiple()
            ->operators([
                IsOperator::make()->modifyBaseQueryUsing(
                    fn (Builder $query, ?array $settings, ?bool $isInverse): Builder => $query->{$isInverse ? 'whereDoesntHave' : 'whereHas'}(
                        'flags',
                        fn (Builder $flags): Builder => $flags
                            ->whereIn('type', self::values($settings))
                            ->whereIn('status', DocumentFlag::OPEN_STATUSES),
                    ),
                ),
                Operator::make('any')
                    ->label(fn (?bool $isInverse): string => $isInverse ? 'Has no open flag' : 'Has an open flag')
                    ->summary(fn (?bool $isInverse): string => $isInverse ? 'No open flag' : 'Has an open flag')
                    ->modifyBaseQueryUsing(fn (Builder $query, ?bool $isInverse): Builder => $query->{$isInverse ? 'whereDoesntHave' : 'whereHas'}(
                        'flags',
                        fn (Builder $flags): Builder => $flags->whereIn('status', DocumentFlag::OPEN_STATUSES),
                    )),
            ]);
    }

    /**
     * Documents in the chosen boxes now, or before a move into an In Situ box.
     * The box pages link here.
     */
    public static function inBox(): SelectConstraint
    {
        return SelectConstraint::make('in_box')
            ->label('Box (now or earlier)')
            ->icon('heroicon-o-archive-box')
            ->multiple()
            ->searchable()
            ->options([])
            ->getSearchResultsUsing(fn (string $search): array => Box::searchOptions($search))
            ->getOptionLabelsUsing(fn (array $values): array => Box::shortLabelsFor($values))
            ->operators([
                IsOperator::make()->modifyBaseQueryUsing(
                    fn (Builder $query, ?array $settings, ?bool $isInverse): Builder => $isInverse
                        ? $query->whereNot(fn (Builder $q): Builder => Document::whereInBox($q, self::values($settings)))
                        : Document::whereInBox($query, self::values($settings)),
                ),
            ]);
    }

    /**
     * Client 2026-10-29: "I cannot filter by location from the Documents".
     * The location the Location column shows: the document's own, or its
     * box's. A room includes the shelves and showcases inside it.
     */
    public static function location(): SelectConstraint
    {
        return SelectConstraint::make('location')
            ->label('Location')
            ->icon('heroicon-o-map-pin')
            ->multiple()
            ->searchable()
            ->options(fn (): array => Location::query()->orderBy('name')->get()
                ->mapWithKeys(fn (Location $l): array => [$l->getKey() => LocationBreadcrumbCache::for($l) ?? (string) $l->name])
                ->sort()
                ->all())
            ->operators([
                IsOperator::make()->modifyBaseQueryUsing(function (Builder $query, ?array $settings, ?bool $isInverse): Builder {
                    $ids = self::withSublocations(self::values($settings));
                    $in = fn (Builder $q): Builder => $q->whereIn($q->qualifyColumn('location_id'), $ids)
                        ->orWhere(fn (Builder $q): Builder => $q
                            ->whereNull($q->qualifyColumn('location_id'))
                            ->whereHas('currentBox', fn (Builder $b): Builder => $b->whereIn('location_id', $ids)));

                    return $isInverse ? $query->whereNot($in) : $query->where($in);
                }),
            ]);
    }

    public static function custodyStatus(): SelectConstraint
    {
        return SelectConstraint::make('custody_status')
            ->label('Custody Status')
            ->options(Document::custodyLabels())
            ->multiple();
    }

    /**
     * Disinfested by its own date, or by its box's.
     */
    public static function disinfested(): Constraint
    {
        return Constraint::make('disinfested')
            ->label('Disinfested')
            ->icon('heroicon-o-sparkles')
            ->operators([
                Operator::make('yes')
                    ->label(fn (?bool $isInverse): string => $isInverse ? 'Is not disinfested' : 'Is disinfested')
                    ->summary(fn (?bool $isInverse): string => $isInverse ? 'Not disinfested' : 'Disinfested')
                    ->modifyBaseQueryUsing(function (Builder $query, ?bool $isInverse): Builder {
                        $disinfested = fn (Builder $q): Builder => $q->whereNotNull($q->qualifyColumn('disinfestation_date'))
                            ->orWhereHas('currentBox', fn (Builder $b): Builder => $b->whereNotNull('disinfestation_date'));

                        return $isInverse ? $query->whereNot($disinfested) : $query->where($disinfested);
                    }),
            ]);
    }

    /**
     * @return list<int|string>
     */
    private static function values(?array $settings): array
    {
        return array_values(array_filter((array) ($settings['values'] ?? []), 'is_scalar'));
    }

    /**
     * @param list<int|string> $ids
     * @return list<int>
     */
    private static function withSublocations(array $ids): array
    {
        $all = [];
        foreach (Location::query()->whereKey($ids)->get() as $location) {
            $all[] = (int) $location->getKey();
            foreach ($location->descendants() as $child) {
                $all[] = (int) $child->getKey();
            }
        }

        return array_values(array_unique($all));
    }
}
