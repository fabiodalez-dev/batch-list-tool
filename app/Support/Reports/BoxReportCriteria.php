<?php

declare(strict_types=1);

namespace App\Support\Reports;

use App\Models\Authority;
use App\Models\Document;
use App\Models\Series;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The SQL the box reports of 2026-11-10 share (stock take, boxes pending
 * disinfestation, mass migration): boxes in number order, the "not disinfested
 * yet" rule, and the mass-migration document criteria.
 *
 * Everything stays SQL-side: the production web request dies at ~64 MB, so no
 * report loads its boxes or documents into PHP to decide what to show.
 */
final class BoxReportCriteria
{
    /** The custody statuses of a document kept outside its box (client 2026-11-10). */
    public const array OUT_OF_BOX_CUSTODY = ['not_in_box', 'mounted_no_box', 'conservation_box'];

    /**
     * The batch number of `boxes`, for ordering and display.
     */
    public const string BATCH_NUMBER_SQL = '(SELECT ord_batch.batch_number FROM batches ord_batch WHERE ord_batch.id = boxes.batch_id)';

    /**
     * Order boxes the way the client reads them: RAS boxes first by batch and
     * box number, then NRA / MUS / MAV / STVC… by type and number. Numbers are
     * compared as numbers (batch 2 before batch 10, box 9 before box 54); the
     * raw text breaks ties for the few that are not plain numbers (12A).
     *
     * The arguments are SQL expressions, so the same order serves a Box query
     * and the stock-take UNION alike.
     *
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public static function orderBoxes(Builder $query, string $type, string $batch, string $number, string $direction = 'asc'): Builder
    {
        $dir = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        return $query
            ->orderByRaw("CASE WHEN COALESCE({$type}, 'RAS') = 'RAS' THEN 0 ELSE 1 END {$dir}")
            ->orderByRaw("{$type} {$dir}")
            ->orderByRaw("CASE WHEN {$batch} IS NULL OR {$batch} = '' THEN 1 ELSE 0 END {$dir}")
            ->orderByRaw("CAST({$batch} AS UNSIGNED) {$dir}")
            ->orderByRaw("{$batch} {$dir}")
            ->orderByRaw("CAST({$number} AS UNSIGNED) {$dir}")
            ->orderByRaw("{$number} {$dir}");
    }

    /**
     * Catalogue identifiers in natural order: R2/001 before R10/001 before
     * R100/001. They read letter, number, "/", a zero-padded part, so the
     * first letter, then the number after it, then the text does it. Blank
     * identifiers go last.
     *
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public static function orderCatalogueIdentifier(Builder $query, string $column, string $direction = 'asc'): Builder
    {
        $dir = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        return $query
            ->orderByRaw("CASE WHEN {$column} IS NULL OR {$column} = '' THEN 1 ELSE 0 END")
            ->orderByRaw("SUBSTR({$column}, 1, 1) {$dir}")
            ->orderByRaw("CAST(SUBSTR({$column}, 2) AS UNSIGNED) {$dir}")
            ->orderByRaw("{$column} {$dir}");
    }

    /**
     * Documents not disinfested yet: no disinfestation date at all — neither
     * the current one nor any of the three legacy rounds that
     * {@see Document::disinfestationTimeline()} lists — and not out
     * at disinfestation right now (those are on their way).
     *
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $documents
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public static function notDisinfested(Builder $documents): Builder
    {
        return $documents
            ->whereNull($documents->qualifyColumn('disinfestation_date'))
            ->whereNull($documents->qualifyColumn('disinfestation_date_1'))
            ->whereNull($documents->qualifyColumn('disinfestation_date_2'))
            ->whereNull($documents->qualifyColumn('disinfestation_date_3'))
            ->where(fn (Builder $q): Builder => $q
                ->whereNull($documents->qualifyColumn('is_in_disinfestation'))
                ->orWhere($documents->qualifyColumn('is_in_disinfestation'), false));
    }

    /**
     * The selected series with all their subseries (series.parent_id), at any
     * depth: choosing R brings in REG, RWL, RDP…
     *
     * @param array<int|string> $ids
     * @return list<int>
     */
    public static function seriesWithSubseries(array $ids): array
    {
        $all = array_values(array_unique(array_map('intval', array_filter($ids, 'filled'))));
        $frontier = $all;

        for ($depth = 0; $frontier !== [] && $depth < 10; $depth++) {
            $children = Series::withoutGlobalScopes()->whereIn('parent_id', $frontier)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $frontier = array_values(array_diff($children, $all));
            $all = array_merge($all, $frontier);
        }

        return $all;
    }

    /**
     * Split an Actual Authority Reference Code into its letters and its number:
     * "R150" → ['R', 150], "R163A" → ['R', 163], "r 7" → ['R', 7], "150" →
     * ['', 150]. Null when there is no number to compare (blank, "Unknown").
     *
     * @return array{0: string, 1: int}|null
     */
    public static function parseCode(?string $code): ?array
    {
        if ($code === null || preg_match('/^\s*([A-Za-z]*)\s*(\d+)/', $code, $m) !== 1) {
            return null;
        }

        return [strtoupper($m[1]), (int) $m[2]];
    }

    /**
     * Whether a code lies in the range. The number is compared as a number, so
     * R150–R165 takes R151 and R163A but not R1500 or R16. A bound without
     * letters matches any letters; with one bound only, the code must carry
     * that bound's letters. With both bounds and different letters (R900 to
     * S010) the letters are compared first, then the number.
     *
     * @param array{0: string, 1: int}|null $from
     * @param array{0: string, 1: int}|null $to
     */
    public static function codeInRange(?string $code, ?array $from, ?array $to): bool
    {
        $parsed = self::parseCode($code);
        if ($parsed === null) {
            return false;
        }
        [$letters, $number] = $parsed;

        $sameLetters = $from !== null && $to !== null && $from[0] === $to[0];
        if ($from === null || $to === null || $sameLetters) {
            $bound = $from ?? $to;
            if ($bound !== null && $bound[0] !== '' && $bound[0] !== $letters) {
                return false;
            }

            return ($from === null || $number >= $from[1]) && ($to === null || $number <= $to[1]);
        }

        $key = [$letters, $number];

        return ($from[0] === '' ? $number >= $from[1] : $key >= $from)
            && ($to[0] === '' ? $number <= $to[1] : $key <= $to);
    }

    /**
     * The authorities whose Actual Authority Reference Code (the Citing
     * Reference Code, authorities.alternative_identifier) lies in the range,
     * and those that have no usable code at all. One small read: the archive
     * holds a few hundred authorities, so the range is resolved here and the
     * document query only filters on ids.
     *
     * @return array{in_range: list<int>, no_code: list<int>}
     */
    public static function authorityIds(?string $from, ?string $to): array
    {
        // Read once per request: the box query asks twice (filter and count).
        return once(fn (): array => self::readAuthorityIds($from, $to));
    }

    /**
     * Narrow a document query to the mass-migration criteria. Each one is
     * optional; those given must all hold. A document matches the authority
     * range when ANY of its authorities is in it (a document can carry several).
     *
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $documents
     * @param array{series?: array<int|string>, document_types?: array<string>, from?: ?string, to?: ?string, include_no_code?: bool} $criteria
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public static function applyMigrationCriteria(Builder $documents, array $criteria): Builder
    {
        $series = self::seriesWithSubseries($criteria['series'] ?? []);
        if ($series !== []) {
            $documents->whereIn($documents->qualifyColumn('series_id'), $series);
        }

        $types = array_values(array_filter($criteria['document_types'] ?? [], 'filled'));
        if ($types !== []) {
            $documents->whereIn($documents->qualifyColumn('document_type'), $types);
        }

        $from = trim((string) ($criteria['from'] ?? ''));
        $to = trim((string) ($criteria['to'] ?? ''));
        $includeNoCode = (bool) ($criteria['include_no_code'] ?? false);
        $hasRange = self::parseCode($from) !== null || self::parseCode($to) !== null;

        if ($hasRange) {
            $ids = self::authorityIds($from, $to);
            $documents->where(function (Builder $q) use ($ids, $includeNoCode): void {
                $q->whereHas('authorities', fn (Builder $a): Builder => $a->whereIn('authorities.id', $ids['in_range']));
                if ($includeNoCode) {
                    $q->orWhereDoesntHave('authorities')
                        ->orWhereHas('authorities', fn (Builder $a): Builder => $a->whereIn('authorities.id', $ids['no_code']));
                }
            });
        }

        return $documents;
    }

    /**
     * Read the per-location counts of a report's filtered rows, for the group
     * headings: one GROUP BY over what the table shows.
     *
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $filtered
     * @return array<string, array<string, int>> location name ('' for none) => [kind => count]
     */
    public static function countsPerLocation(Builder $filtered, string $locationColumn, ?string $kindColumn = null): array
    {
        $inner = (clone $filtered)->reorder()->toBase();
        $inner->limit = null;
        $inner->offset = null;

        $query = DB::query()->fromSub($inner, 'grp')
            ->selectRaw("COALESCE(grp.{$locationColumn}, '') as loc")
            ->selectRaw(($kindColumn !== null ? "grp.{$kindColumn}" : "'row'") . ' as kind')
            ->selectRaw('COUNT(*) as aggregate')
            ->groupByRaw("COALESCE(grp.{$locationColumn}, '')" . ($kindColumn !== null ? ", grp.{$kindColumn}" : ''));

        $out = [];
        foreach ($query->get() as $row) {
            $out[(string) $row->loc][(string) $row->kind] = (int) $row->aggregate;
        }

        return $out;
    }

    /**
     * @return array{in_range: list<int>, no_code: list<int>}
     */
    private static function readAuthorityIds(?string $from, ?string $to): array
    {
        $fromBound = self::parseCode($from);
        $toBound = self::parseCode($to);

        $inRange = [];
        $noCode = [];
        foreach (Authority::query()->toBase()->get(['authorities.id', 'authorities.alternative_identifier']) as $row) {
            $code = $row->alternative_identifier;
            if (self::parseCode(is_string($code) ? $code : null) === null) {
                $noCode[] = (int) $row->id;

                continue;
            }
            if (($fromBound !== null || $toBound !== null) && self::codeInRange($code, $fromBound, $toBound)) {
                $inRange[] = (int) $row->id;
            }
        }

        return ['in_range' => $inRange, 'no_code' => $noCode];
    }
}
