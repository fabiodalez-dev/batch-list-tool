<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Box;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint\Operators\IsOperator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * "Add rule" rules of the Boxes list that are not a plain column.
 */
final class BoxRules
{
    /**
     * Client 2026-10-30: "Location Filter is not working well from Boxes. I
     * tried to filter by boxes in the Mould Room. Maybe because the location
     * is computed from Documents?" The location the Location column shows:
     * the box's own, or — for a box without one — the one location all its
     * documents share ("from documents", {@see Box::sharedDocumentLocation()}).
     * A room includes the shelves and showcases inside it.
     */
    public static function location(): SelectConstraint
    {
        return SelectConstraint::make('location')
            ->label('Location')
            ->icon('heroicon-o-map-pin')
            ->multiple()
            ->searchable()
            ->options(fn (): array => DocumentRules::locationOptions())
            ->operators([
                IsOperator::make()->modifyBaseQueryUsing(function (Builder $query, ?array $settings, ?bool $isInverse): Builder {
                    $ids = DocumentRules::withSublocations(DocumentRules::values($settings));
                    $box = $query->getModel()->getQualifiedKeyName();
                    $own = $query->qualifyColumn('location_id');

                    // Same documents as sharedDocumentLocation(): in the box
                    // now, not deleted, with a location.
                    $documents = static fn (QueryBuilder $d): QueryBuilder => $d
                        ->from('documents as shared_doc')
                        ->whereColumn('shared_doc.current_box_id', $box)
                        ->whereNull('shared_doc.deleted_at')
                        ->whereNotNull('shared_doc.location_id');

                    // whereNotNull keeps "Is not" from dropping the boxes
                    // without a location (NOT (NULL IN …) is not true in SQL).
                    $in = static fn (Builder $q): Builder => $q
                        ->where(static fn (Builder $q): Builder => $q->whereNotNull($own)->whereIn($own, $ids))
                        ->orWhere(static function (Builder $q) use ($own, $ids, $documents): void {
                            $q->whereNull($own)
                                ->whereExists(static fn (QueryBuilder $d): QueryBuilder => $documents($d)->whereIn('shared_doc.location_id', $ids));
                            // Exactly one location among them.
                            $q->getQuery()->where(static fn (QueryBuilder $d): QueryBuilder => $documents($d)->selectRaw('count(distinct shared_doc.location_id)'), '=', 1);
                        });

                    return $isInverse ? $query->whereNot($in) : $query->where($in);
                }),
            ]);
    }

    /**
     * The Parent box column: the box's parent, or one of the RAS boxes it was
     * assembled from.
     */
    public static function parentBox(): SelectConstraint
    {
        return SelectConstraint::make('parent_box')
            ->label('Parent box')
            ->icon('heroicon-o-archive-box')
            ->multiple()
            ->searchable()
            ->options([])
            ->getSearchResultsUsing(fn (string $search): array => Box::searchOptions($search))
            ->getOptionLabelsUsing(fn (array $values): array => Box::shortLabelsFor($values))
            ->operators([
                IsOperator::make()->modifyBaseQueryUsing(function (Builder $query, ?array $settings, ?bool $isInverse): Builder {
                    $ids = DocumentRules::values($settings);
                    $in = static fn (Builder $q): Builder => $q
                        ->whereIn($q->qualifyColumn('parent_box_id'), $ids)
                        ->orWhereHas('parents', static fn (Builder $p): Builder => $p->whereKey($ids));

                    return $isInverse ? $query->whereNot($in) : $query->where($in);
                }),
            ]);
    }
}
