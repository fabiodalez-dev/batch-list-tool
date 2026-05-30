<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Accession;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use Filament\Forms\Components\Select;

/**
 * Factory helpers that build Filament `Select` components configured for
 * the "speak to operator" UX brief (PR — feat/ux-searchable-selects).
 *
 * The legacy pattern was `Select::make()->relationship(...)->searchable()->preload()`
 * which on the production-realistic dataset (3,113 documents, 808 authorities,
 * 669 boxes) preloads the entire option list into a `<select>` element and
 * is unusable.
 *
 * The new pattern is:
 *   - `preload(false)` — no upfront fetch.
 *   - `getSearchResultsUsing(...)` — server-side autocomplete capped at 50.
 *   - `getOptionLabelUsing(...)` — single-record label for the already-selected
 *     value, with a "(deleted)" suffix when the relation is soft-deleted.
 *   - `getOptionLabelFromRecordUsing(...)` — same human-friendly label format
 *     used by both the autocomplete dropdown and the static option.
 *
 * Each factory below returns a `Select` that callers can further refine
 * (`->required()`, `->label('...')`, `->visible(fn)`, etc.) — the helpers
 * do NOT call `->relationship()` because some sites need a custom query
 * (tenant-scoped repository list, RAS-only parent box list, …). Callers
 * declare the relationship as before; the helpers only wire the
 * search/label closures.
 */
final class SearchableSelects
{
    /** Hard cap on autocomplete result rows. Anything above this is paginated by typing more. */
    public const MAX_RESULTS = 50;

    /* =========================================================================
     |  Document
     |========================================================================*/

    /**
     * Build a Select bound to a Document FK column.
     *
     * Label format: `<identifier> — <primary authority surname> — bc:<barcode_in>`.
     * Search across: identifier, catalogue_identifier, barcode_in, authorities.surname.
     */
    public static function document(string $name = 'document_id'): Select
    {
        return Select::make($name)
            ->relationship('document', 'identifier')
            ->searchable(['identifier', 'catalogue_identifier', 'barcode_in'])
            ->preload(false)
            ->getOptionLabelFromRecordUsing(fn (Document $r): string => self::documentLabel($r))
            ->getSearchResultsUsing(fn (string $search): array => self::documentSearchResults($search))
            ->getOptionLabelUsing(fn ($value): ?string => self::documentOptionLabel($value));
    }

    /**
     * Same as {@see Document()} but resolves through an arbitrary relationship
     * name (Filament uses the relation name to load the current value).
     */
    public static function documentVia(string $fieldName, string $relationshipName): Select
    {
        return Select::make($fieldName)
            ->relationship($relationshipName, 'identifier')
            ->searchable(['identifier', 'catalogue_identifier', 'barcode_in'])
            ->preload(false)
            ->getOptionLabelFromRecordUsing(fn (Document $r): string => self::documentLabel($r))
            ->getSearchResultsUsing(fn (string $search): array => self::documentSearchResults($search))
            ->getOptionLabelUsing(fn ($value): ?string => self::documentOptionLabel($value));
    }

    /**
     * Search closure for Document — exposed so tests can call it directly
     * via reflection without spinning up a Livewire page.
     *
     * @return array<int|string, string>
     */
    public static function documentSearchResults(string $search): array
    {
        $search = trim($search);

        $query = Document::query()->with('authorities');

        if ($search === '') {
            $query->orderBy('identifier');
        } else {
            $needle = '%' . $search . '%';
            $query->where(function ($q) use ($needle, $search) {
                $q->where('identifier', 'like', $needle)
                    ->orWhere('catalogue_identifier', 'like', $needle)
                    ->orWhere('barcode_in', 'like', $needle)
                    ->orWhereHas('authorities', fn ($a) => $a->where('surname', 'like', '%' . $search . '%'));
            })->orderBy('identifier');
        }

        $rows = $query->limit(self::MAX_RESULTS)->get();
        $out = [];
        foreach ($rows as $r) {
            /** @var Document $r */
            $out[$r->id] = self::documentLabel($r);
        }

        return $out;
    }

    public static function documentLabel(Document $r): string
    {
        /** @var Authority|null $authority */
        $authority = $r->relationLoaded('authorities')
            ? $r->authorities->first()
            : $r->authorities()->first();

        $surname = $authority instanceof Authority && $authority->surname !== null
            ? $authority->surname
            : '—';
        $barcode = $r->barcode_in !== null && $r->barcode_in !== '' ? $r->barcode_in : '—';

        return "{$r->identifier} — {$surname} — bc:{$barcode}";
    }

    /* =========================================================================
     |  Box
     |========================================================================*/

    /**
     * Build a Select bound to a Box FK column.
     *
     * Label format: `Batch <batch_number>/Box <box_number> — <box_type> — <barcode_status>`.
     */
    public static function box(string $name = 'current_box_id', string $relationship = 'currentBox'): Select
    {
        return Select::make($name)
            ->relationship($relationship, 'box_number')
            ->searchable(['box_number', 'barcode'])
            ->preload(false)
            ->getOptionLabelFromRecordUsing(fn (Box $r): string => self::boxLabel($r))
            ->getSearchResultsUsing(fn (string $search): array => self::boxSearchResults($search))
            ->getOptionLabelUsing(fn ($value): ?string => self::boxOptionLabel($value));
    }

    /**
     * Build a Box Select with a caller-supplied query modifier (e.g. RAS-only
     * for the IN_SITU parent picker). The modifier runs on top of the
     * standard search closure.
     */
    public static function boxFiltered(
        string $name,
        string $relationship,
        \Closure $queryModifier,
    ): Select {
        return Select::make($name)
            ->relationship($relationship, 'box_number', $queryModifier)
            ->searchable(['box_number', 'barcode'])
            ->preload(false)
            ->getOptionLabelFromRecordUsing(fn (Box $r): string => self::boxLabel($r))
            ->getSearchResultsUsing(function (string $search) use ($queryModifier): array {
                return self::boxSearchResults($search, $queryModifier);
            })
            ->getOptionLabelUsing(fn ($value): ?string => self::boxOptionLabel($value));
    }

    /**
     * @return array<int|string, string>
     */
    public static function boxSearchResults(string $search, ?\Closure $queryModifier = null): array
    {
        $search = trim($search);

        $query = Box::query()->with('batch');
        if ($queryModifier !== null) {
            $queryModifier($query);
        }

        if ($search === '') {
            $query->orderBy('box_number');
        } else {
            $needle = '%' . $search . '%';
            $query->where(function ($q) use ($needle) {
                $q->where('box_number', 'like', $needle)
                    ->orWhere('barcode', 'like', $needle)
                    ->orWhereHas('batch', fn ($b) => $b->where('batch_number', 'like', $needle));
            })->orderBy('box_number');
        }

        $rows = $query->limit(self::MAX_RESULTS)->get();
        $out = [];
        foreach ($rows as $r) {
            /** @var Box $r */
            $out[$r->id] = self::boxLabel($r);
        }

        return $out;
    }

    public static function boxLabel(Box $r): string
    {
        /** @var Batch|null $batch */
        $batch = $r->batch;
        $batchNumber = $batch instanceof Batch && $batch->batch_number !== null
            ? $batch->batch_number
            : '?';

        $type = $r->box_type ?? '—';
        $status = $r->barcode_status ?? '—';

        return "Batch {$batchNumber}/Box {$r->box_number} — {$type} — {$status}";
    }

    /* =========================================================================
     |  Authority
     |========================================================================*/

    /**
     * Build a Select bound to an Authority FK column.
     *
     * Label format: `<identifier> — <surname> <given_names> (<practice_dates>)`.
     */
    public static function authority(
        string $name = 'authority_id',
        string $relationship = 'authority',
    ): Select {
        return Select::make($name)
            ->relationship($relationship, 'surname')
            ->searchable(['identifier', 'surname', 'given_names', 'alternative_identifier'])
            ->preload(false)
            ->getOptionLabelFromRecordUsing(fn (Authority $r): string => self::authorityLabel($r))
            ->getSearchResultsUsing(fn (string $search): array => self::authoritySearchResults($search))
            ->getOptionLabelUsing(fn ($value): ?string => self::authorityOptionLabel($value));
    }

    /**
     * Build a multi-Select bound to the BelongsToMany Authority relation.
     * Used by Document's "Authorities (Creators)" section.
     */
    public static function authoritiesMulti(string $name = 'authorities'): Select
    {
        return Select::make($name)
            ->multiple()
            ->relationship('authorities', 'surname')
            ->searchable(['identifier', 'surname', 'given_names', 'alternative_identifier'])
            ->preload(false)
            ->getOptionLabelFromRecordUsing(fn (Authority $r): string => self::authorityLabel($r))
            ->getSearchResultsUsing(fn (string $search): array => self::authoritySearchResults($search))
            ->getOptionLabelsUsing(fn (array $values): array => self::authorityOptionLabels($values));
    }

    /**
     * @return array<int|string, string>
     */
    public static function authoritySearchResults(string $search): array
    {
        $search = trim($search);

        $query = Authority::query();

        if ($search === '') {
            $query->orderBy('surname');
        } else {
            $needle = '%' . $search . '%';
            $query->where(function ($q) use ($needle) {
                $q->where('identifier', 'like', $needle)
                    ->orWhere('alternative_identifier', 'like', $needle)
                    ->orWhere('surname', 'like', $needle)
                    ->orWhere('given_names', 'like', $needle);
            })->orderBy('surname');
        }

        $rows = $query->limit(self::MAX_RESULTS)->get();
        $out = [];
        foreach ($rows as $r) {
            /** @var Authority $r */
            $out[$r->id] = self::authorityLabel($r);
        }

        return $out;
    }

    public static function authorityLabel(Authority $r): string
    {
        // Feedback1 — creator dropdown label reads "identifier – firstname
        // lastname" (given name before surname). Search still works on
        // identifier + name because the underlying query targets all of
        // identifier/surname/given_names/alternative_identifier.
        $identifier = $r->identifier ?? '—';
        $name = trim(implode(' ', array_filter([
            $r->given_names !== null && $r->given_names !== '' ? $r->given_names : null,
            $r->surname !== null && $r->surname !== '' ? $r->surname : null,
        ])));
        if ($name === '') {
            $name = '—';
        }

        $start = $r->practice_dates_start;
        $end = $r->practice_dates_end;
        $dates = '';
        if ($start !== null || $end !== null) {
            $dates = ' (' . ($start ?? '?') . '-' . ($end ?? '?') . ')';
        }

        return "{$identifier} – {$name}{$dates}";
    }

    /* =========================================================================
     |  Batch
     |========================================================================*/

    /**
     * Build a Select bound to a Batch FK column.
     *
     * Label format: `Batch <batch_number> — <type>`.
     */
    public static function batch(
        string $name = 'batch_id',
        string $relationship = 'batch',
    ): Select {
        return Select::make($name)
            ->relationship($relationship, 'batch_number')
            ->searchable(['batch_number'])
            ->preload(false)
            ->getOptionLabelFromRecordUsing(fn (Batch $r): string => self::batchLabel($r))
            ->getSearchResultsUsing(fn (string $search): array => self::batchSearchResults($search))
            ->getOptionLabelUsing(fn ($value): ?string => self::batchOptionLabel($value));
    }

    /**
     * @return array<int|string, string>
     */
    public static function batchSearchResults(string $search): array
    {
        $search = trim($search);

        $query = Batch::query();

        if ($search === '') {
            $query->orderBy('batch_number');
        } else {
            $needle = '%' . $search . '%';
            $query->where(function ($q) use ($needle) {
                $q->where('batch_number', 'like', $needle)
                    ->orWhere('description', 'like', $needle)
                    ->orWhere('type', 'like', $needle);
            })->orderBy('batch_number');
        }

        $rows = $query->limit(self::MAX_RESULTS)->get();
        $out = [];
        foreach ($rows as $r) {
            /** @var Batch $r */
            $out[$r->id] = self::batchLabel($r);
        }

        return $out;
    }

    public static function batchLabel(Batch $r): string
    {
        $type = $r->type ?? '—';

        return "Batch {$r->batch_number} — {$type}";
    }

    /* =========================================================================
     |  Series
     |========================================================================*/

    /**
     * Build a Select bound to a Series FK column.
     *
     * Label format: `<code> — <title>`.
     */
    public static function series(string $name = 'series_id'): Select
    {
        return Select::make($name)
            ->relationship('series', 'code')
            ->searchable(['code', 'title'])
            ->preload(false)
            ->getOptionLabelFromRecordUsing(fn (Series $r): string => self::seriesLabel($r))
            ->getSearchResultsUsing(fn (string $search): array => self::seriesSearchResults($search))
            ->getOptionLabelUsing(fn ($value): ?string => self::seriesOptionLabel($value));
    }

    /**
     * @return array<int|string, string>
     */
    public static function seriesSearchResults(string $search): array
    {
        $search = trim($search);

        $query = Series::query();

        if ($search === '') {
            $query->orderBy('code');
        } else {
            $needle = '%' . $search . '%';
            $query->where(function ($q) use ($needle) {
                $q->where('code', 'like', $needle)
                    ->orWhere('title', 'like', $needle);
            })->orderBy('code');
        }

        $rows = $query->limit(self::MAX_RESULTS)->get();
        $out = [];
        foreach ($rows as $r) {
            /** @var Series $r */
            $out[$r->id] = self::seriesLabel($r);
        }

        return $out;
    }

    public static function seriesLabel(Series $r): string
    {
        $title = $r->title !== null && $r->title !== '' ? $r->title : '—';

        return "{$r->code} — {$title}";
    }

    /* =========================================================================
     |  Repository
     |========================================================================*/

    /**
     * Build a Select bound to a Repository FK column.
     *
     * Label format: `<code> — <name>`.
     *
     * The caller passes the query modifier (Repository selects are typically
     * scoped to the user's assigned tenants — see DocumentResource /
     * BatchResource / AccessionResource).
     */
    public static function repository(
        string $name = 'repository_id',
        ?\Closure $queryModifier = null,
    ): Select {
        $relationshipQuery = $queryModifier ?? fn ($q) => $q;

        return Select::make($name)
            ->relationship('repository', 'name', $relationshipQuery)
            ->searchable(['code', 'name'])
            ->preload(false)
            ->getOptionLabelFromRecordUsing(fn (Repository $r): string => self::repositoryLabel($r))
            ->getSearchResultsUsing(function (string $search) use ($queryModifier): array {
                return self::repositorySearchResults($search, $queryModifier);
            })
            ->getOptionLabelUsing(fn ($value): ?string => self::repositoryOptionLabel($value));
    }

    /**
     * @return array<int|string, string>
     */
    public static function repositorySearchResults(string $search, ?\Closure $queryModifier = null): array
    {
        $search = trim($search);

        $query = Repository::query();
        if ($queryModifier !== null) {
            $queryModifier($query);
        }

        if ($search === '') {
            $query->orderBy('code');
        } else {
            $needle = '%' . $search . '%';
            $query->where(function ($q) use ($needle) {
                $q->where('code', 'like', $needle)
                    ->orWhere('name', 'like', $needle);
            })->orderBy('code');
        }

        $rows = $query->limit(self::MAX_RESULTS)->get();
        $out = [];
        foreach ($rows as $r) {
            /** @var Repository $r */
            $out[$r->id] = self::repositoryLabel($r);
        }

        return $out;
    }

    public static function repositoryLabel(Repository $r): string
    {
        $name = $r->name !== null && $r->name !== '' ? $r->name : '—';

        return "{$r->code} — {$name}";
    }

    /* =========================================================================
     |  Accession
     |========================================================================*/

    /**
     * Build a Select bound to an Accession FK column.
     *
     * Label format: the accession `code` is already unique and short — we
     * keep it but append the linked batch number when available.
     */
    public static function accession(string $name = 'accession_id'): Select
    {
        return Select::make($name)
            ->relationship('accession', 'code')
            ->searchable(['code'])
            ->preload(false)
            ->getOptionLabelFromRecordUsing(fn (Accession $r): string => self::accessionLabel($r))
            ->getSearchResultsUsing(fn (string $search): array => self::accessionSearchResults($search))
            ->getOptionLabelUsing(fn ($value): ?string => self::accessionOptionLabel($value));
    }

    /**
     * @return array<int|string, string>
     */
    public static function accessionSearchResults(string $search): array
    {
        $search = trim($search);

        $query = Accession::query()->with('batch');

        if ($search === '') {
            $query->orderBy('code');
        } else {
            $needle = '%' . $search . '%';
            $query->where(function ($q) use ($needle) {
                $q->where('code', 'like', $needle)
                    ->orWhereHas('batch', fn ($b) => $b->where('batch_number', 'like', $needle));
            })->orderBy('code');
        }

        $rows = $query->limit(self::MAX_RESULTS)->get();
        $out = [];
        foreach ($rows as $r) {
            /** @var Accession $r */
            $out[$r->id] = self::accessionLabel($r);
        }

        return $out;
    }

    public static function accessionLabel(Accession $r): string
    {
        /** @var Batch|null $batch */
        $batch = $r->batch;
        $batchNumber = $batch instanceof Batch ? $batch->batch_number : null;

        return $batchNumber !== null
            ? "{$r->code} — batch {$batchNumber}"
            : (string) $r->code;
    }

    /* =========================================================================
     |  Location
     |========================================================================*/

    /**
     * Build a Select bound to a Location FK column.
     *
     * Label format: full breadcrumb path (`Repository > Room > Shelf`).
     * The caller passes the query modifier (typically `->active()->forRepository(...)`).
     */
    public static function location(
        string $name = 'location_id',
        ?\Closure $queryModifier = null,
        string $relationship = 'location',
    ): Select {
        $relationshipQuery = $queryModifier ?? fn ($q) => $q;

        return Select::make($name)
            ->relationship($relationship, 'name', $relationshipQuery)
            ->searchable(['name', 'code'])
            ->preload(false)
            ->getOptionLabelFromRecordUsing(fn (Location $r): string => $r->breadcrumb())
            ->getSearchResultsUsing(function (string $search) use ($queryModifier): array {
                return self::locationSearchResults($search, $queryModifier);
            })
            ->getOptionLabelUsing(fn ($value): ?string => self::locationOptionLabel($value));
    }

    /**
     * @return array<int|string, string>
     */
    public static function locationSearchResults(string $search, ?\Closure $queryModifier = null): array
    {
        $search = trim($search);

        $query = Location::query();
        if ($queryModifier !== null) {
            $queryModifier($query);
        }

        if ($search === '') {
            $query->orderBy('path');
        } else {
            $needle = '%' . $search . '%';
            $query->where(function ($q) use ($needle) {
                $q->where('name', 'like', $needle)
                    ->orWhere('code', 'like', $needle);
            })->orderBy('path');
        }

        $rows = $query->limit(self::MAX_RESULTS)->get();
        $out = [];
        foreach ($rows as $r) {
            /** @var Location $r */
            $out[$r->id] = $r->breadcrumb();
        }

        return $out;
    }

    /* =========================================================================
     |  User (for box_movements.user_id, document_flags.flagged_by_user_id, ...)
     |========================================================================*/

    /**
     * Build a Select bound to a User FK column.
     */
    public static function user(string $name, string $relationship): Select
    {
        return Select::make($name)
            ->relationship($relationship, 'name')
            ->searchable(['name', 'email'])
            ->preload(false)
            ->getOptionLabelFromRecordUsing(fn (User $r): string => self::userLabel($r))
            ->getSearchResultsUsing(fn (string $search): array => self::userSearchResults($search))
            ->getOptionLabelUsing(fn ($value): ?string => self::userOptionLabel($value));
    }

    /**
     * @return array<int|string, string>
     */
    public static function userSearchResults(string $search): array
    {
        $search = trim($search);

        $query = User::query();

        if ($search === '') {
            $query->orderBy('name');
        } else {
            $needle = '%' . $search . '%';
            $query->where(function ($q) use ($needle) {
                $q->where('name', 'like', $needle)
                    ->orWhere('email', 'like', $needle);
            })->orderBy('name');
        }

        $rows = $query->limit(self::MAX_RESULTS)->get();
        $out = [];
        foreach ($rows as $r) {
            /** @var User $r */
            $out[$r->id] = self::userLabel($r);
        }

        return $out;
    }

    public static function userLabel(User $r): string
    {
        $email = $r->email !== null && $r->email !== '' ? " ({$r->email})" : '';

        return "{$r->name}{$email}";
    }

    private static function documentOptionLabel(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        // withTrashed so a soft-deleted parent still shows a meaningful label
        // instead of falling back to a raw ID or blank string.
        $record = Document::withTrashed()->with('authorities')->find($value);

        if ($record === null) {
            return null;
        }

        $label = self::documentLabel($record);

        if ($record->trashed()) {
            $label .= ' (deleted)';
        }

        return $label;
    }

    private static function boxOptionLabel(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $record = Box::withTrashed()->with('batch')->find($value);

        if ($record === null) {
            return null;
        }

        $label = self::boxLabel($record);

        if ($record->trashed()) {
            $label .= ' (deleted)';
        }

        return $label;
    }

    private static function authorityOptionLabel(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $record = Authority::withTrashed()->find($value);

        if ($record === null) {
            return null;
        }

        $label = self::authorityLabel($record);

        if ($record->trashed()) {
            $label .= ' (deleted)';
        }

        return $label;
    }

    /**
     * @param array<int, int|string> $values
     * @return array<int|string, string>
     */
    private static function authorityOptionLabels(array $values): array
    {
        if ($values === []) {
            return [];
        }

        $rows = Authority::withTrashed()->whereIn('id', $values)->get();
        $out = [];
        foreach ($rows as $r) {
            /** @var Authority $r */
            $label = self::authorityLabel($r);
            if ($r->trashed()) {
                $label .= ' (deleted)';
            }
            $out[$r->id] = $label;
        }

        return $out;
    }

    private static function batchOptionLabel(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $record = Batch::withTrashed()->find($value);

        if ($record === null) {
            return null;
        }

        $label = self::batchLabel($record);

        if ($record->trashed()) {
            $label .= ' (deleted)';
        }

        return $label;
    }

    private static function seriesOptionLabel(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $record = Series::withTrashed()->find($value);

        if ($record === null) {
            return null;
        }

        $label = self::seriesLabel($record);

        if ($record->trashed()) {
            $label .= ' (deleted)';
        }

        return $label;
    }

    private static function repositoryOptionLabel(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $record = Repository::withTrashed()->find($value);

        if ($record === null) {
            return null;
        }

        $label = self::repositoryLabel($record);

        if ($record->trashed()) {
            $label .= ' (deleted)';
        }

        return $label;
    }

    private static function accessionOptionLabel(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $record = Accession::withTrashed()->with('batch')->find($value);

        if ($record === null) {
            return null;
        }

        $label = self::accessionLabel($record);

        if ($record->trashed()) {
            $label .= ' (deleted)';
        }

        return $label;
    }

    private static function locationOptionLabel(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $record = Location::withTrashed()->find($value);

        if ($record === null) {
            return null;
        }

        $label = $record->breadcrumb();

        if ($record->trashed()) {
            $label .= ' (deleted)';
        }

        return $label;
    }

    private static function userOptionLabel(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $record = User::withTrashed()->find($value);

        if ($record === null) {
            return null;
        }

        $label = self::userLabel($record);

        if ($record->trashed()) {
            $label .= ' (deleted)';
        }

        return $label;
    }
}
