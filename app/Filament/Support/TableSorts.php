<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Resources\DocumentResource;
use App\Models\DocumentFlag;
use App\Models\Location;
use App\Models\Scopes\RepositoryScope;
use App\Models\User;
use App\Support\ActiveRepository;
use Illuminate\Database\Eloquent\Builder;

/**
 * Client 2026-11-05: "can you check that boxes/Documents pages have all fields
 * with sort function?" A column whose cell is resolved in PHP (a box's location
 * from its documents, the inputter from the audits, a custom field) has no
 * column to order by, so it orders by the same value through a correlated
 * sub-select: never a join, which would duplicate rows, break the paginator's
 * count and make `id` / `repository_id` ambiguous for the scopes and the rules
 * of the filter builder. Each sub-select is one indexed lookup per row.
 *
 * Empty cells go last in both directions: with 4,733 boxes without a location,
 * "blanks first" reads as a sort that did nothing.
 *
 * Plain SQL only (CASE, COALESCE, NULLIF, CAST): production is MySQL / MariaDB,
 * the tests run on SQLite.
 */
final class TableSorts
{
    public static function direction(string $direction): string
    {
        return strtolower($direction) === 'desc' ? 'desc' : 'asc';
    }

    /**
     * Order by an expression that is NULL when the cell is empty.
     *
     * @param list<mixed> $bindings of the expression
     */
    public static function blanksLast(Builder $query, string $expression, string $direction, array $bindings = []): Builder
    {
        $dir = self::direction($direction);

        return $query
            ->orderByRaw("CASE WHEN {$expression} IS NULL THEN 1 ELSE 0 END asc", $bindings)
            ->orderByRaw("{$expression} {$dir}", $bindings);
    }

    /** A plain text column: NULL and '' are both an empty cell. */
    public static function textColumn(Builder $query, string $column, string $direction): Builder
    {
        return self::blanksLast($query, "NULLIF({$query->qualifyColumn($column)}, '')", $direction);
    }

    /**
     * Boxes: batch in number order (1, 2, 3… rather than 1, 10, 100), the same
     * as the Documents list; boxes without a batch last.
     */
    public static function boxBatch(Builder $query, string $direction): Builder
    {
        $dir = self::direction($direction);
        $batch = '(SELECT sort_batch.batch_number FROM batches sort_batch WHERE sort_batch.id = boxes.batch_id)';

        return $query
            ->orderByRaw('CASE WHEN boxes.batch_id IS NULL THEN 1 ELSE 0 END asc')
            ->orderByRaw("CAST({$batch} AS UNSIGNED) {$dir}")
            ->orderByRaw("{$batch} {$dir}");
    }

    /**
     * A box's parent, as the cell names it: type, batch and number of the single
     * parent. Boxes assembled from several parents have no single one, and come
     * after those that have.
     */
    public static function boxParent(Builder $query, string $direction): Builder
    {
        $dir = self::direction($direction);
        $parent = static fn (string $column): string => "(SELECT sort_parent.{$column} FROM boxes sort_parent WHERE sort_parent.id = boxes.parent_box_id)";
        $batch = '(SELECT sort_batch.batch_number FROM boxes sort_parent JOIN batches sort_batch ON sort_batch.id = sort_parent.batch_id WHERE sort_parent.id = boxes.parent_box_id)';
        $number = $parent('box_number');

        return $query
            ->orderByRaw('CASE WHEN boxes.parent_box_id IS NOT NULL THEN 0 WHEN EXISTS (SELECT 1 FROM box_parents sort_bp WHERE sort_bp.box_id = boxes.id) THEN 1 ELSE 2 END asc')
            ->orderByRaw("{$parent('box_type')} {$dir}")
            ->orderByRaw("CAST({$batch} AS UNSIGNED) {$dir}")
            ->orderByRaw("CAST({$number} AS UNSIGNED) {$dir}")
            ->orderByRaw("{$number} {$dir}");
    }

    /**
     * A box's location, as the list shows it: its own, else the one location
     * all its located documents share (Box::sharedDocumentLocation()).
     */
    public static function boxLocation(Builder $query, string $direction): Builder
    {
        $own = self::locationRank('boxes.location_id');
        $shared = self::locationRank('MIN(sort_doc.location_id)');

        return self::orderByRank($query, "COALESCE({$own}, (
            SELECT CASE WHEN COUNT(DISTINCT sort_doc.location_id) = 1 THEN {$shared} END
            FROM documents sort_doc
            WHERE sort_doc.current_box_id = boxes.id
                AND sort_doc.location_id IS NOT NULL
                AND sort_doc.deleted_at IS NULL
        ))", $direction);
    }

    /** A document's location, as the list shows it: its own, else its box's. */
    public static function documentLocation(Builder $query, string $direction): Builder
    {
        $own = self::locationRank('documents.location_id');
        $box = self::locationRank('sort_box.location_id');

        return self::orderByRank($query, "COALESCE({$own}, (
            SELECT {$box} FROM boxes sort_box
            WHERE sort_box.id = documents.current_box_id AND sort_box.deleted_at IS NULL
        ))", $direction);
    }

    /**
     * The disinfestation date a document shows: its own, else its box's. The
     * "Current Cycle" rows (client 2026-11-05) stay together, as the newest
     * value: last going up, first going down. Empty cells last.
     */
    public static function documentDisinfestation(Builder $query, string $direction): Builder
    {
        $dir = self::direction($direction);
        $date = DocumentResource::EFFECTIVE_DISINFESTATION_SQL;
        $current = 'documents.is_in_disinfestation = 1';
        [$dates, $cycle] = $dir === 'asc' ? [0, 1] : [1, 0];

        return $query
            ->orderByRaw("CASE WHEN {$current} THEN {$cycle} WHEN {$date} IS NOT NULL THEN {$dates} ELSE 2 END asc")
            ->orderByRaw("CASE WHEN {$current} THEN NULL ELSE {$date} END {$dir}");
    }

    /**
     * Who the Inputter column names: the first 'created' audit, else the first
     * 'updated' one, else the first of any event (see CreatorColumn).
     */
    public static function inputter(Builder $query, string $direction): Builder
    {
        $model = $query->getModel();
        $audits = (string) config('audit.drivers.database.table', 'audits');

        // MIN over the rows of one record, then its user by primary key: no sort
        // per row, which took 17 seconds on 26,000 documents. Users ranked in
        // PHP like the locations, so this runs once per row, not twice.
        $names = User::query()->withoutGlobalScopes()->whereNotNull('name')->pluck('name', 'id')->all();
        natcasesort($names);
        $cases = '';
        $rank = 0;
        foreach (array_keys($names) as $id) {
            $cases .= ' WHEN ' . (int) $id . ' THEN ' . ++$rank;
        }
        if ($cases === '') {
            return $query;
        }

        return self::orderByRank($query, "(SELECT CASE sort_audit.user_id{$cases} END FROM {$audits} sort_audit WHERE sort_audit.id = (
            SELECT COALESCE(
                MIN(CASE WHEN sort_first.event = 'created' THEN sort_first.id END),
                MIN(CASE WHEN sort_first.event = 'updated' THEN sort_first.id END),
                MIN(sort_first.id)
            )
            FROM {$audits} sort_first
            WHERE sort_first.auditable_type = ? AND sort_first.auditable_id = {$model->getQualifiedKeyName()}
        ))", $direction, [$model->getMorphClass()]);
    }

    /** A custom-field column: its value on this record, numbers as numbers. */
    public static function customField(Builder $query, int $definitionId, string $type, string $direction): Builder
    {
        $model = $query->getModel();
        $value = $type === 'number' ? "CAST(NULLIF(sort_cf.value, '') AS DECIMAL(30,10))" : "NULLIF(sort_cf.value, '')";

        return self::blanksLast($query, "(SELECT {$value} FROM custom_field_values sort_cf
            WHERE sort_cf.custom_field_definition_id = {$definitionId}
                AND sort_cf.customizable_type = ?
                AND sort_cf.customizable_id = {$model->getQualifiedKeyName()})", $direction, [$model->getMorphClass()]);
    }

    /**
     * An authority column: a document can have several, one line each, so it
     * orders by the first of them alphabetically. $expression reads `sort_a`.
     */
    public static function authorityField(Builder $query, string $expression, string $direction): Builder
    {
        return self::blanksLast($query, "(SELECT MIN({$expression}) FROM document_authority sort_da
            JOIN authorities sort_a ON sort_a.id = sort_da.authority_id AND sort_a.deleted_at IS NULL
            WHERE sort_da.document_id = documents.id)", $direction);
    }

    /** The open flags by name, the first of them alphabetically. */
    public static function documentFlags(Builder $query, string $direction): Builder
    {
        $labels = DocumentFlag::typeOptions();
        foreach (DocumentFlag::query()->withoutGlobalScopes()->distinct()->pluck('type') as $type) {
            $labels[$type] ??= ucwords(str_replace('_', ' ', (string) $type));
        }
        natcasesort($labels);

        $cases = '';
        $bindings = [];
        $rank = 0;
        foreach (array_keys($labels) as $type) {
            $cases .= ' WHEN ? THEN ' . ++$rank;
            $bindings[] = $type;
        }
        $open = implode(', ', array_map(static fn (string $status): string => "'{$status}'", DocumentFlag::OPEN_STATUSES));

        return self::blanksLast($query, "(SELECT MIN(CASE sort_flag.type{$cases} END) FROM document_flags sort_flag
            WHERE sort_flag.document_id = documents.id AND sort_flag.status IN ({$open}))", $direction, $bindings);
    }

    /**
     * Museum Location holds a code; the cell shows the location that code names
     * in the document's repository, or the code itself when none has it.
     */
    public static function documentMuseumLocation(Builder $query, string $direction): Builder
    {
        $cases = '';
        $bindings = [];
        foreach (self::visibleLocations() as $location) {
            if (($location['code'] ?? '') === '' || $location['repository_id'] === null) {
                continue;
            }
            $cases .= ' WHEN documents.repository_id = ' . $location['repository_id'] . ' AND documents.museum_location = ? THEN ?';
            $bindings[] = $location['code'];
            $bindings[] = $location['breadcrumb'];
        }
        $expression = $cases === ''
            ? "NULLIF(documents.museum_location, '')"
            : "COALESCE(CASE{$cases} END, NULLIF(documents.museum_location, ''))";

        return self::blanksLast($query, $expression, $direction, $bindings);
    }

    /**
     * @param list<mixed> $bindings of the rank expression
     */
    private static function orderByRank(Builder $query, string $rank, string $direction, array $bindings = []): Builder
    {
        // A sentinel instead of a second evaluation of the sub-select.
        return self::direction($direction) === 'asc'
            ? $query->orderByRaw("COALESCE({$rank}, 2147483647) asc", $bindings)
            : $query->orderByRaw("COALESCE({$rank}, -1) desc", $bindings);
    }

    /**
     * Each location's position when reading the breadcrumbs in natural order
     * ("Archive 2" before "Archive 10", which a text sort cannot do), as a
     * CASE over the location id. The locations are a short list; a location
     * the user cannot see has no position, which is what its blank cell shows.
     */
    private static function locationRank(string $operand): string
    {
        $cases = '';
        $rank = 0;
        foreach (array_keys(self::visibleLocations()) as $id) {
            $cases .= ' WHEN ' . $id . ' THEN ' . ++$rank;
        }

        return $cases === '' ? 'NULL' : "CASE {$operand}{$cases} END";
    }

    /**
     * Same set as the location pickers: the user's repositories plus the
     * global locations, in breadcrumb order.
     *
     * @return array<int, array{code: ?string, repository_id: ?int, breadcrumb: string}>
     */
    private static function visibleLocations(): array
    {
        $query = Location::query()->withoutGlobalScope(RepositoryScope::class);
        $allowed = ActiveRepository::allowedRepositoryIdsFor(auth()->user());
        if (auth()->check() && $allowed !== null) {
            $query->where(static function (Builder $q) use ($allowed): void {
                $q->whereNull('repository_id');
                if ($allowed !== []) {
                    $q->orWhereIn('repository_id', $allowed);
                }
            });
        }
        $rows = $query->get(['id', 'name', 'code', 'path', 'repository_id']);
        $names = $rows->pluck('name', 'id');

        $out = [];
        foreach ($rows as $location) {
            $crumbs = array_map(static fn (int $id): ?string => $names[$id] ?? null, $location->ancestorIdsFromPath());
            $crumbs[] = $location->name;
            $out[(int) $location->getKey()] = [
                'code' => $location->code,
                'repository_id' => $location->repository_id !== null ? (int) $location->repository_id : null,
                'breadcrumb' => implode(' → ', array_filter($crumbs)),
            ];
        }
        uasort($out, static fn (array $a, array $b): int => strnatcasecmp($a['breadcrumb'], $b['breadcrumb']));

        return $out;
    }
}
