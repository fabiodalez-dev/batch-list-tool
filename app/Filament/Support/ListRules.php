<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Repository;
use App\Models\User;
use Closure;
use Filament\Tables\Filters\QueryBuilder\Constraints\BooleanConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\Constraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\DateConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\NumberConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\NumberConstraint\Operators as NumberOps;
use Filament\Tables\Filters\QueryBuilder\Constraints\Operators\Operator;
use Filament\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint\Operators\IsOperator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * "Add rule" rules shared by the lists other than Documents.
 *
 * Client 2026-10-30: "can you check on every page that all the fields are
 * included?" and "Can all the filters be built up exactly like Documents with
 * the AND OR rule and then Add Condition?" Every column a list shows has a
 * rule; the ones that are not a plain column (counts, the Inputter, a
 * yes/no worked out from a date) are built here.
 */
final class ListRules
{
    /**
     * The number of related records, as the "Boxes" / "Documents" count
     * columns show it: equals, at least, at most (and their NOT).
     *
     * @param (Closure(Builder): mixed)|null $scope narrows the related records counted
     */
    public static function count(string $name, string $label, string $relationship, ?Closure $scope = null): NumberConstraint
    {
        $compare = static fn (string $direct, string $inverse): Closure => static function (Builder $query, ?array $settings, ?bool $isInverse) use ($direct, $inverse, $relationship, $scope): Builder {
            $number = $settings['number'] ?? null;
            if (! is_numeric($number)) {
                return $query;
            }

            return $query->has($relationship, $isInverse ? $inverse : $direct, (int) $number, 'and', $scope);
        };

        return NumberConstraint::make($name)
            ->label($label)
            ->icon('heroicon-o-hashtag')
            ->integer()
            ->operators([
                NumberOps\EqualsOperator::make()->modifyBaseQueryUsing($compare('=', '!=')),
                NumberOps\IsMinOperator::make()->modifyBaseQueryUsing($compare('>=', '<')),
                NumberOps\IsMaxOperator::make()->modifyBaseQueryUsing($compare('<=', '>')),
            ]);
    }

    /**
     * The user the Inputter column names (see {@see CreatorColumn}): the
     * author of the record's first "created" audit, else of its first
     * "updated" one, else of its first audit of any kind.
     */
    public static function inputter(string $label = 'Inputter'): SelectConstraint
    {
        return SelectConstraint::make('inputter')
            ->label($label)
            ->icon('heroicon-o-user')
            ->options(fn (): array => self::visibleToViewer(User::query())->orderBy('name')->pluck('name', 'id')->all())
            ->searchable()
            ->multiple()
            ->operators([
                IsOperator::make()->modifyBaseQueryUsing(function (Builder $query, ?array $settings, ?bool $isInverse): Builder {
                    $userIds = DocumentRules::values($settings);
                    $model = $query->getModel();
                    $type = $model->getMorphClass();
                    $key = $model->getQualifiedKeyName();
                    $audits = (string) config('audit.drivers.database.table', 'audits');

                    $byInputter = static fn (Builder $q): Builder => $q->whereExists(static fn (QueryBuilder $audit): QueryBuilder => $audit
                        ->from($audits . ' as inputter_audit')
                        ->where('inputter_audit.auditable_type', $type)
                        ->whereColumn('inputter_audit.auditable_id', $key)
                        ->whereIn('inputter_audit.user_id', $userIds)
                        ->where('inputter_audit.id', '=', static fn (QueryBuilder $first): QueryBuilder => $first
                            ->from($audits . ' as first_audit')
                            ->select('first_audit.id')
                            ->where('first_audit.auditable_type', $type)
                            ->whereColumn('first_audit.auditable_id', $key)
                            ->orderByRaw("case first_audit.event when 'created' then 0 when 'updated' then 1 else 2 end")
                            ->orderBy('first_audit.id')
                            ->limit(1)));

                    return $isInverse ? $query->whereNot($byInputter) : $query->where($byInputter);
                }),
            ]);
    }

    /**
     * The choices a rule offers, cut to what the viewer may see: repositories
     * they belong to, and the users of those repositories. Admins see all.
     * Batches, boxes and the other records already carry the repository scope.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param Builder<TModel> $query
     * @return Builder<TModel>
     */
    public static function visibleToViewer(Builder $query): Builder
    {
        $viewer = auth()->user();
        if (! $viewer instanceof User || $viewer->hasAnyRole(['super_admin', 'admin'])) {
            return $query;
        }

        $repositoryIds = $viewer->repositories()->pluck('repositories.id');
        $model = $query->getModel();

        return match (true) {
            $model instanceof Repository => $query->whereIn($model->getQualifiedKeyName(), $repositoryIds),
            $model instanceof User => $query->where(fn (Builder $q): Builder => $q
                ->whereKey($viewer->getKey())
                ->orWhereHas('repositories', fn (Builder $r): Builder => $r->whereIn('repositories.id', $repositoryIds))),
            default => $query,
        };
    }

    /**
     * A belongs-to column ("Repository", "Owner"): is one of the chosen
     * records, or (when the column can be empty) is empty.
     */
    public static function relation(string $name, string $label, string $titleAttribute, bool $emptyable = false, bool $preload = true): RelationshipConstraint
    {
        $operator = IsRelatedToOperator::make()
            ->titleAttribute($titleAttribute)
            ->modifyRelationshipQueryUsing(fn (Builder $query): Builder => self::visibleToViewer($query))
            ->searchable()
            ->multiple();
        if ($preload) {
            $operator->preload();
        }

        $constraint = RelationshipConstraint::make($name)
            ->label($label)
            ->selectable($operator);

        return $emptyable ? $constraint->emptyable() : $constraint;
    }

    /**
     * A timestamp column: on / before / after a date, or empty.
     */
    public static function date(string $name, ?string $label = null): DateConstraint
    {
        $constraint = DateConstraint::make($name)->nullable();

        return $label === null ? $constraint : $constraint->label($label);
    }

    /**
     * The rules of a lookup list (Box Types, Flag Types, …): code, label,
     * order, active, plus the lookup's own columns, Inputter, Updated at.
     *
     * @param list<\Filament\QueryBuilder\Constraints\Constraint> $extra
     * @return list<\Filament\QueryBuilder\Constraints\Constraint>
     */
    public static function lookup(string $codeLabel = 'Identifier', array $extra = []): array
    {
        return [
            EqualsFirst::text('code', $codeLabel),
            EqualsFirst::text('label', 'Label'),
            EqualsFirst::number('sort_order', 'Sort order'),
            BooleanConstraint::make('is_active'),
            ...$extra,
            self::inputter(),
            self::date('updated_at'),
        ];
    }

    /**
     * A yes/no column worked out by a query ("Destroyed", "Worked as NTG").
     *
     * @param Closure(Builder): Builder $condition the records that read "Yes"
     */
    public static function yesNo(string $name, string $label, string $yes, string $no, Closure $condition): Constraint
    {
        return Constraint::make($name)
            ->label($label)
            ->icon('heroicon-o-check-circle')
            ->operators([
                Operator::make('yes')
                    ->label(fn (?bool $isInverse): string => $isInverse ? $no : $yes)
                    ->summary(fn (?bool $isInverse): string => $label . ': ' . ($isInverse ? 'No' : 'Yes'))
                    ->modifyBaseQueryUsing(fn (Builder $query, ?bool $isInverse): Builder => $isInverse
                        ? $query->whereNot($condition)
                        : $query->where($condition)),
            ]);
    }
}
