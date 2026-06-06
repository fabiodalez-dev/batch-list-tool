<?php

namespace App\Models;

use App\Models\Builders\DocumentBuilder;
use App\Models\Concerns\BelongsToRepository;
use App\Models\Concerns\HasCustomFields;
use App\Models\Lookup\CurrentBoxType;
use App\Models\Lookup\DigitisationStatus;
use App\Observers\DocumentObserver;
use App\Support\Lookups;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Scout\Searchable;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\SchemalessAttributes\Casts\SchemalessAttributes;
use Spatie\Tags\HasTags;

class Document extends Model implements AuditableContract, HasMedia, Sortable
{
    use Auditable;
    use BelongsToRepository;  // RFQ §3.5.1 — multi-tenant scope
    use HasCustomFields;
    use HasFactory;
    use HasTags;
    use InteractsWithMedia;
    use Searchable;
    use SoftDeletes;
    use SortableTrait;

    /**
     * Canonical whitelist of columns that participate in the FULLTEXT
     * search index (MySQL) and the LIKE-based fallback (SQLite / Postgres).
     *
     * Any column passed to {@see self::scopeSearchFullText()} that is NOT in
     * this list triggers an InvalidArgumentException at the call-site —
     * fail-fast over silently scanning the wrong index or rejecting at
     * query time. Keep this in sync with the migration that creates the
     * matching `idx_documents_*_ft` indexes on MySQL.
     *
     * @var array<int,string>
     */
    public const FULLTEXT_COLUMNS = [
        'notes',
        'deeds',
        'museum_reference',
        'practice',
        'dates',
    ];

    /**
     * RFQ-2026-06 APP2-xiii — allowed values for the `digitised` lookup.
     * Tracks the digitisation source. NULL means "not yet digitised".
     *
     * Mirrored by a CHECK constraint on MySQL (see
     * 2026_05_27_170100_tighten_document_lookups). The PHP-side guard in
     * booted() is the cross-driver enforcement (SQLite cannot retro-fit a
     * CHECK constraint).
     *
     * @var array<int,string>
     */
    public const DIGITISED_VALUES = ['VHMML', 'NRA', 'none'];

    /**
     * RFQ-2026-06 APP2-ix — allowed values for `current_box_type`. The
     * disinfestation planner counts 'Big Brown Box' as 2 boxes against the
     * 250-box per-cycle limit, so the enum lock-down also gates that
     * downstream business logic.
     *
     * Mirrored by a CHECK constraint on MySQL (see
     * 2026_05_27_170100_tighten_document_lookups). The PHP-side guard in
     * booted() is the cross-driver enforcement (SQLite cannot retro-fit a
     * CHECK constraint).
     *
     * @var array<int,string>
     */
    public const CURRENT_BOX_TYPES = ['RAS Box', 'Big Brown Box', 'Small Brown Box'];

    /**
     * RFQ-2026-06 App.2-ii — physical custody categories for a document.
     * Stored in `documents.custody_status`; default is `in_box`.
     *
     * - `in_box`        — document is physically inside a box
     * - `not_in_box`    — in situ at NRA, not yet boxed
     * - `mounted_no_box`— framed / mounted; no box applicable
     *
     * @var array<int,string>
     */
    public const CUSTODY_STATUSES = ['in_box', 'not_in_box', 'mounted_no_box'];

    public array $sortable = [
        'order_column_name' => 'sort_order',
        'sort_when_creating' => true,
    ];

    /**
     * `repository_id` is mass-assignable so Filament admins (who legitimately
     * pick a target tenant from the Repository Select) can write it through
     * `create()` — but the BelongsToRepository `creating` hook is the security
     * gate: it validates the chosen `repository_id` against the user's pivot
     * and throws \DomainException for any non-privileged write that targets a
     * foreign tenant. Defence-in-depth here is the hook, NOT $guarded.
     *
     * @see BelongsToRepository
     */
    protected $fillable = [
        'sort_order',
        // Normalised columns
        'identifier', 'document_type', 'series_id', 'accession_id',
        'current_box_id', 'location_id', 'batch_id', 'repository_id', 'volume_number', 'part_number',
        'dates_start', 'dates_end', 'dates_year_start', 'dates_year_end',
        'disinfestation_date', 'is_in_disinfestation', 'extra', 'notes',
        // Legacy POC columns (parity with raw-PHP schema)
        'ras_batch_1', 'ras_box_1', 'ras_batch_2', 'ras_box_2',
        'in_situ_box_1', 'in_situ_box_2', 'in_situ_box_3',
        'ras_1_box_destroyed', 'ras_2_box_destroyed',
        'in_situ_box_1_destroyed', 'in_situ_box_2_destroyed', 'in_situ_box_3_destroyed',
        'barcode_in', 'barcode_status', 'barcode', 'custody_status', 'barcode_ras_1', 'status_1', 'barcode_ras_2', 'status_2',
        'barcode_ras_3', 'status_3', 'barcode_ras_4', 'status_4',
        'barcode_in_2', 'barcode_ras_2_alt', 'status_1_alt',
        'barcode_ras_2_alt2', 'status_2_alt',
        'disinfestation_date_1', 'disinfestation_date_2', 'disinfestation_date_3',
        'catalogue_identifier', 'nra_location', 'museum_location', 'practice',
        'dates', 'deeds', 'current_box_type', 'colour_code', 'digitised', 'torre',
        'accession_code_legacy', 'object_reference_number', 'tracking', 'museum_reference',
        'custom_fields', 'metadata',
    ];

    protected $casts = [
        'dates_start' => 'date',
        'dates_end' => 'date',
        'disinfestation_date' => 'date',
        'disinfestation_date_1' => 'date',
        'disinfestation_date_2' => 'date',
        'disinfestation_date_3' => 'date',
        'is_in_disinfestation' => 'boolean',
        'torre' => 'boolean',
        'extra' => SchemalessAttributes::class,
        'custom_fields' => 'array',
        'metadata' => 'array',
    ];

    /** @internal DocumentBuilder bulk-update guard bypass flag */
    private static bool $bypassAuditGuard = false;

    /**
     * Sort within the current_box. Documents in box A and documents in box B
     * each have their own 1..N sequence.
     */
    public function buildSortQuery(): Builder
    {
        return static::query()->where('current_box_id', $this->current_box_id);
    }

    /**
     * The "best-available" identifier for display:
     *   catalogue_identifier > object_reference_number > identifier
     *
     * Per RFQ Appendix-2 §xv: `object_reference_number` is "a temporary
     * identifier used in past projects" and serves as a fallback when the
     * canonical `catalogue_identifier` has not yet been assigned. The
     * legacy POC `identifier` column is the last-resort fallback so the
     * accessor never silently degrades to an empty string for a record
     * that does have *some* identifier on file.
     *
     * Non-invasive accessor only — no DB change required. Used by the
     * Document Resource table column and the Document Infolist hero so the
     * shown value flows through this fallback chain consistently.
     */
    public function getDisplayIdentifierAttribute(): ?string
    {
        return $this->catalogue_identifier
            ?? $this->object_reference_number
            ?? $this->identifier
            ?? null;
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    public function accession(): BelongsTo
    {
        return $this->belongsTo(Accession::class);
    }

    public function currentBox(): BelongsTo
    {
        return $this->belongsTo(Box::class, 'current_box_id');
    }

    /**
     * RFQ §3.1.9 — Document can be pinned to a configurable Location
     * independently of (or in addition to) its current Box. Useful when a
     * document is on display in a museum showcase or temporarily on a
     * conservation work-area without being re-boxed.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function authorities(): BelongsToMany
    {
        return $this->belongsToMany(Authority::class, 'document_authority')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function volumes(): HasMany
    {
        return $this->hasMany(Volume::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(BoxMovement::class)->latest('movement_date');
    }

    /**
     * Append-only log of identifier transitions for this document (PR #8).
     * Returns rows ordered descending by `changed_at` so the most recent
     * change is first — callers can override with `->orderBy(...)`.
     */
    public function identifierHistory(): HasMany
    {
        return $this->hasMany(DocumentIdentifierHistory::class)->latest('changed_at');
    }

    /**
     * Append-only log of per-document barcode value changes (Task 7b).
     *
     * The document's custody STATUS comes from its box (Task 7 mirror).
     * This relation tracks only the document's OWN barcode VALUE history,
     * mirroring the BoxSealNumberHistory pattern.
     */
    public function barcodeHistory(): HasMany
    {
        return $this->hasMany(DocumentBarcodeHistory::class)->orderByDesc('changed_at');
    }

    /**
     * Distinct list of previous identifiers this document has ever held.
     * Built from the `identifierHistory` log; uniqueness is enforced in PHP
     * so the result is stable across DB drivers (SQLite collation quirks).
     *
     * @return Collection<int,string>
     */
    public function previousIdentifiers(): Collection
    {
        return $this->identifierHistory()
            ->pluck('previous_identifier')
            ->unique()
            ->values();
    }

    /**
     * Operational flags / alerts attached to this document (RFQ §3.1.12).
     * Replaces the legacy spreadsheet colour-coding with a structured,
     * searchable, filterable, resolvable issue list.
     */
    public function flags(): HasMany
    {
        return $this->hasMany(DocumentFlag::class)->latest('flagged_at');
    }

    /**
     * Disinfestation history rendered as a flat, ordered collection of
     * `{date, label}` rows — ready for the Filament `RepeatableEntry` on
     * the View Document page.
     *
     * Sources, ordered chronologically (oldest first):
     *   - `disinfestation_date_1` → "Legacy round #1"
     *   - `disinfestation_date_2` → "Legacy round #2"
     *   - `disinfestation_date_3` → "Legacy round #3"
     *   - `disinfestation_date`   → "Current"  (the canonical column)
     *
     * Null rows are skipped — the infolist already shows a placeholder when
     * the resulting collection is empty.
     *
     * @return Collection<int, array{date: Carbon|\Illuminate\Support\Carbon, label: string}>
     */
    public function disinfestationTimeline(): Collection
    {
        $rows = collect([
            ['date' => $this->disinfestation_date_1, 'label' => 'Legacy round #1'],
            ['date' => $this->disinfestation_date_2, 'label' => 'Legacy round #2'],
            ['date' => $this->disinfestation_date_3, 'label' => 'Legacy round #3'],
            ['date' => $this->disinfestation_date, 'label' => 'Current'],
        ])
            ->filter(fn (array $row) => $row['date'] !== null)
            ->sortBy(fn (array $row) => $row['date'])
            ->values();

        return $rows;
    }

    /**
     * Subset: only flags that are still actionable (open or acknowledged).
     * Resolved/dismissed flags are filtered out — operators care about the
     * inbox, not the historical resolved set.
     */
    public function openFlags(): HasMany
    {
        return $this->flags()->open();
    }

    /**
     * F-011 alignment: this MUST mirror the attributes exposed in
     * DocumentResource::getGloballySearchableAttributes() so that swapping
     * Scout drivers (database / Meilisearch / Algolia) does not change
     * which fields the user sees in global search.
     */
    public function toSearchableArray(): array
    {
        return [
            'identifier' => $this->identifier,
            'catalogue_identifier' => $this->catalogue_identifier,
            'document_type' => $this->document_type,
            'practice' => $this->practice,
            'volume_number' => $this->volume_number,
            'dates' => $this->dates,
            'notes' => $this->notes,
            'barcode_in' => $this->barcode_in,
            'series_code' => $this->series?->code,
            'series_title' => $this->series?->title,
            'authorities_surnames' => $this->authorities()->pluck('surname')->implode(' '),
            'authorities_idents' => $this->authorities()->pluck('identifier')->implode(' '),
            // RFQ §3.1.12 — only open flags are indexed; resolved/dismissed
            // flags would pollute the search inbox.
            'flag_tokens' => $this->openFlags->pluck('type')->map(fn ($t) => "flag:{$t}")->implode(' '),
        ];
    }

    public function registerMediaCollections(): void
    {
        // image/tif AND image/tiff: RFC 3302 lists both as valid; some
        // Windows tools + iOS Files app emit image/tif. Accept both so a
        // legitimate scan isn't silently rejected at upload time.
        $this->addMediaCollection('attachments')
            ->acceptsMimeTypes([
                'application/pdf',
                'image/jpeg', 'image/png', 'image/tiff', 'image/tif',
            ]);
    }

    /**
     * Wire Eloquent up to our custom DocumentBuilder so that bulk
     * `Document::query()->update([...])` calls go through the audit guard.
     */
    public function newEloquentBuilder($query): DocumentBuilder
    {
        return new DocumentBuilder($query);
    }

    /**
     * Run a callback with the DocumentBuilder bulk-update guard temporarily
     * suspended. Intended for genuinely needed back-fill migrations where
     * the caller takes responsibility for recording the audit rows.
     *
     * Example:
     *   Document::withoutAuditGuards(function () use ($ids, $newId) {
     *       Document::query()->whereIn('id', $ids)->update(['identifier' => $newId]);
     *       // ... and now manually insert into document_identifier_history
     *   });
     *
     * @template T
     *
     * @param callable(): T $callback
     * @return T
     */
    public static function withoutAuditGuards(callable $callback): mixed
    {
        static::$bypassAuditGuard = true;

        try {
            return $callback();
        } finally {
            static::$bypassAuditGuard = false;
        }
    }

    /**
     * Used by DocumentBuilder to decide whether to enforce the bulk-update
     * guard on the `identifier` column.
     */
    /**
     * Return the canonical case spelling of `$raw` if it matches any value in
     * the `$allowed` list case-insensitively (trimmed). Returns null if the
     * value isn't in the enum at all. Used by the booted() enum gate so that
     * legacy spreadsheet data carrying mixed case ('Vhmml' vs 'VHMML') is
     * accepted and normalised on save instead of being rejected.
     *
     * @param array<int, string> $allowed
     */
    public static function canonicalEnumValue(string $raw, array $allowed): ?string
    {
        $needle = mb_strtolower(trim($raw));
        foreach ($allowed as $value) {
            if (mb_strtolower($value) === $needle) {
                return $value;
            }
        }

        return null;
    }

    public static function shouldBypassAuditGuard(): bool
    {
        return static::$bypassAuditGuard;
    }

    /**
     * Full-text search across one or more text columns.
     *
     * On MySQL this expands to `MATCH (col1, col2) AGAINST (? IN NATURAL
     * LANGUAGE MODE)` and uses whichever FULLTEXT index covers the exact
     * column set passed in. The migration creates one single-column index
     * per searchable column (notes, deeds, museum_reference); MySQL only
     * uses a FULLTEXT index whose column list matches the MATCH() list
     * exactly, so callers should pass one column at a time.
     *
     * On any other driver (SQLite for the test suite, Postgres for
     * hypothetical staging) we silently degrade to the same `LIKE '%term%'`
     * chain that the resource used before this change — slower, but
     * functionally identical, and the unit tests cover both branches.
     *
     * The whole clause is wrapped in a where(fn $q => ...) closure so it
     * composes with `where(...)->searchFullText(...)->where(...)` chains
     * without leaking an OR into the outer WHERE group.
     *
     * ## MySQL FULLTEXT quirks operators should know about
     *
     * - **Minimum token size.** InnoDB defaults to `innodb_ft_min_token_size = 3`:
     *   terms shorter than 3 characters silently match *nothing*. We short-circuit
     *   below that threshold to avoid issuing a useless query and to make the
     *   "empty result" obvious to the caller instead of being a silent miss.
     * - **Stopword list.** The default English InnoDB stopword list drops common
     *   words such as `will`, `the`, `of`, `an`, `is`, `was`. A FULLTEXT search
     *   for "will" against an Italian/Maltese notarial dataset returns zero rows.
     * - **Not for identifiers.** Identifier-like searches ("R7", "R12") MUST go
     *   through the B-tree index on `documents.identifier`; this scope is the
     *   wrong tool for short codes (see min-token-size note). The Filament
     *   resource keeps identifier lookups on the B-tree column.
     * - **Production tuning for IT/MT corpora.** For the NRA archive in
     *   production we recommend `innodb_ft_min_token_size = 2` and disabling
     *   the English stopword list at the MySQL server level (`my.cnf` →
     *   `innodb_ft_enable_stopword = OFF` or a custom stopword table). Changes
     *   to these settings require a rebuild of the FULLTEXT indexes
     *   (`OPTIMIZE TABLE documents` after the restart).
     *
     * @param array<int, string> $columns Must be a subset of {@see self::FULLTEXT_COLUMNS}.
     *
     * @throws \InvalidArgumentException when $columns contains a non-whitelisted column.
     */
    public function scopeSearchFullText(
        Builder $query,
        string $term,
        array $columns = ['notes', 'deeds', 'museum_reference'],
    ): Builder {
        // Whitelist check — fail fast on typos / SQL-injection attempts via
        // user-controlled column names. We compare against the canonical
        // FULLTEXT_COLUMNS constant; callers may pass any subset of it.
        $invalid = array_diff($columns, self::FULLTEXT_COLUMNS);
        if ($invalid !== []) {
            throw new \InvalidArgumentException(
                'scopeSearchFullText: columns must be a subset of '
                . implode(',', self::FULLTEXT_COLUMNS)
                . '; got: ' . implode(',', $invalid)
            );
        }

        $term = trim($term);

        // Empty term → no-op, returning the unchanged builder lets callers
        // chain ->searchFullText($value) inside a ->when() with no extra
        // guard (Filament's filter ->query() callback expects exactly this).
        if ($term === '' || $columns === []) {
            return $query;
        }

        // Short-circuit on terms shorter than MySQL's default minimum token
        // size (3 chars). Hitting MySQL with such a term would silently
        // return an empty set and waste a round-trip; returning the
        // unmodified builder makes the no-op visible to the caller and
        // gives identical results between MySQL and the SQLite fallback.
        if (mb_strlen($term) < 3) {
            return $query;
        }

        $driver = $query->getConnection()->getDriverName();

        return $query->where(function (Builder $inner) use ($term, $columns, $driver) {
            if ($driver === 'mysql') {
                $columnList = implode(', ', array_map(
                    fn (string $c) => '`' . str_replace('`', '``', $c) . '`',
                    $columns,
                ));

                $inner->whereRaw(
                    "MATCH ({$columnList}) AGAINST (? IN NATURAL LANGUAGE MODE)",
                    [$term],
                );

                return;
            }

            // Non-MySQL fallback: chain OR LIKEs across the same columns.
            // The `%term%` shape matches Eloquent's LIKE convention; we
            // escape the term's wildcards so a user searching for "100%"
            // doesn't accidentally match every row.
            $needle = '%' . addcslashes($term, '%_\\') . '%';

            foreach ($columns as $i => $col) {
                $i === 0
                    ? $inner->where($col, 'like', $needle)
                    : $inner->orWhere($col, 'like', $needle);
            }
        });
    }

    /**
     * F1 (review finding) — re-sync this document's mirrored custody fields
     * (`barcode_status`, `disinfestation_date`, `batch_id`) from its current
     * box. No-op when the document has no current box.
     *
     * Uses `saveQuietly()` to persist the realigned columns without firing
     * model events (so the box mirror / barcode-history observers are not
     * re-triggered). Only writes when at least one field is stale.
     */
    public function syncCustodyFromCurrentBox(): void
    {
        if ($this->current_box_id === null) {
            return;
        }

        /** @var Box|null $box */
        $box = Box::withoutGlobalScopes()->find($this->current_box_id);
        if ($box === null) {
            return;
        }

        $dirty = false;

        // The document's barcode_status BEFORE this sync — used to detect a
        // transition OUT of PERM_OUT custody below.
        $previousStatus = $this->getOriginal('barcode_status');

        if ($box->barcode_status !== null && $this->barcode_status !== $box->barcode_status) {
            $this->barcode_status = $box->barcode_status;
            $dirty = true;
        }

        // A1.2 — keep the document's disinfestation_date in step with the box.
        //
        //   - Box PERM_OUT: realign the document to the box's date (the box
        //     guard guarantees a PERM_OUT box carries one). Realign even when
        //     the document already had a date — the box is authoritative for a
        //     PERM_OUT custody, so a doc moving BETWEEN PERM_OUT boxes reflects
        //     the destination's date and never drifts.
        //
        //   - Box NOT PERM_OUT, and the document is LEAVING a PERM_OUT custody
        //     (its prior status was PERM_OUT): the date it carried was the
        //     mirror of the old PERM_OUT box, so clear it — it no longer
        //     reflects the current box.
        //
        // We deliberately do NOT clear a date on a non-PERM_OUT box when the
        // document was not previously PERM_OUT: a document can legitimately
        // hold a disinfestation_date while sitting IN a box (it was disinfested
        // but stays in storage — see MarkDisinfested and RFQ §3.1.4). Blanket
        // clearing would destroy that genuine record.
        if ($box->barcode_status === 'PERM_OUT') {
            if ($box->disinfestation_date !== null
                && (string) $this->disinfestation_date !== (string) $box->disinfestation_date) {
                $this->disinfestation_date = $box->disinfestation_date;
                $dirty = true;
            }
        } elseif ($previousStatus === 'PERM_OUT' && $this->disinfestation_date !== null) {
            $this->disinfestation_date = null;
            $dirty = true;
        }

        if ($box->batch_id !== null && (int) $this->batch_id !== (int) $box->batch_id) {
            $this->batch_id = $box->batch_id;
            $dirty = true;
        }

        if ($dirty) {
            // F1 (review finding) — saveQuietly() bypasses the model `saving`
            // hooks, including the Batch-50 wills-only guard. Re-apply that
            // guard here so re-mirroring batch_id into (or out of) the wills
            // reserve cannot establish an inconsistent state behind the quiet
            // save. Recursion safety is preserved: saveQuietly() still fires
            // no events.
            self::assertWillsBatchInvariant($this->batch_id, $this->series_id);

            $this->saveQuietly();
        }
    }

    /**
     * Boot hooks for Document — enforces the enum + Batch-50 invariants on
     * every save(). The identifier audit trail lives in the dedicated
     * {@see DocumentObserver} class. (Seal-number history is a property of
     * the Box, not the Document — see {@see Box::sealNumberHistory()}.)
     */
    protected static function booted(): void
    {
        // RFQ-2026-06 APP2-ix / APP2-xiii — gate the two lookup enums in PHP
        // so the constraint is enforced on every driver (SQLite test runs
        // included, where a DB-level CHECK cannot be retro-fitted).
        // Runs on both create and update via the `saving` event.
        //
        // Legacy data accepts mixed case ('Vhmml', 'vhmml', 'VHMML') and the
        // sample spreadsheet uses 'Vhmml' — normalise case-insensitively to
        // the canonical value before the strict membership check, so the
        // import survives without rewriting the source data.
        static::saving(function (Document $document): void {
            if ($document->digitised !== null) {
                $normalized = self::canonicalEnumValue($document->digitised, self::DIGITISED_VALUES);
                if ($normalized === null) {
                    throw new \DomainException(
                        "Invalid digitised value '{$document->digitised}'. Allowed: "
                        . implode(', ', self::DIGITISED_VALUES)
                    );
                }
                $document->digitised = $normalized;

                // RFQ §3.1.11 (part 2 of 3) — beyond the frozen-const enum
                // gate above, the (now-canonical) value must be an ACTIVE row
                // in the digitisation_statuses lookup. Dirty-check so a record
                // carrying a value that was LATER deactivated still re-saves.
                if ($document->isDirty('digitised')) {
                    Lookups::assertActive(DigitisationStatus::class, 'digitised', $document->digitised);
                }
            }

            if ($document->current_box_type !== null) {
                $normalized = self::canonicalEnumValue($document->current_box_type, self::CURRENT_BOX_TYPES);
                if ($normalized === null) {
                    throw new \DomainException(
                        "Invalid current_box_type '{$document->current_box_type}'. Allowed: "
                        . implode(', ', self::CURRENT_BOX_TYPES)
                    );
                }
                $document->current_box_type = $normalized;

                if ($document->isDirty('current_box_type')) {
                    Lookups::assertActive(CurrentBoxType::class, 'current_box_type', $document->current_box_type);
                }
            }

            // Gate `custody_status` in PHP too: the MySQL CHECK does not run on
            // SQLite, so mirror the digitised / current_box_type guards above.
            // Normalise case-insensitively (consistency with the siblings),
            // then reject anything outside the enum.
            if ($document->custody_status !== null) {
                $normalized = self::canonicalEnumValue($document->custody_status, self::CUSTODY_STATUSES);
                if ($normalized === null) {
                    throw ValidationException::withMessages([
                        'custody_status' => "Invalid custody_status '{$document->custody_status}'. Allowed: "
                            . implode(', ', self::CUSTODY_STATUSES),
                    ]);
                }
                $document->custody_status = $normalized;
            }

            // F1 (review finding) — when current_box_id is being set/changed
            // (create prefill, or any programmatic write), the target box must
            // exist and not be destroyed. We deliberately DO NOT reject a
            // PERM_OUT box here: a document legitimately RESIDES in a PERM_OUT
            // box (the box and its contents were permanently transferred out
            // together). Custody consistency for that case is handled by the
            // re-mirror below (syncCustodyFromCurrentBox), which makes the
            // document reflect the box's PERM_OUT status and backfills its
            // disinfestation_date — so there is no drift and no A1.2 breach.
            // Gated on dirty so untouched re-saves of pre-existing rows are
            // never re-validated.
            if ($document->current_box_id !== null && $document->isDirty('current_box_id')) {
                $box = Box::withoutGlobalScopes()->withTrashed()->find($document->current_box_id);
                if ($box === null) {
                    throw ValidationException::withMessages([
                        'current_box_id' => 'The selected box does not exist.',
                    ]);
                }
                if ($box->trashed() || $box->isDestroyed()) {
                    throw ValidationException::withMessages([
                        'current_box_id' => 'Cannot place a document in a destroyed box.',
                    ]);
                }
            }

            // A1.2 — a document cannot be PERM_OUT without a disinfestation date.
            if ($document->barcode_status === 'PERM_OUT' && empty($document->disinfestation_date)) {
                throw ValidationException::withMessages([
                    'barcode_status' => 'A document cannot be PERM OUT without a disinfestation date (RFQ A1.2).',
                ]);
            }

            // RFQ App.1 #2 — Batch 50 is reserved for wills. Enforce centrally
            // (every path, not just the UI actions): a document placed in the
            // wills-reserve batch MUST belong to a wills series. Only evaluated
            // when the placement actually changes, to keep unrelated saves cheap.
            if ($document->batch_id !== null
                && ($document->isDirty('batch_id') || $document->isDirty('series_id'))) {
                self::assertWillsBatchInvariant($document->batch_id, $document->series_id);
            }
        });

        // Task 7b — per-document barcode value change history.
        //
        // Mirror the box seal-number history pattern (BoxSealNumberHistory /
        // Box::booted()): split across `created` / `updated` (not a single
        // `saved`) so the "from" side is always unambiguous:
        //   - created: old_value = null (document did not exist before)
        //   - updated: old_value = getOriginal('barcode') before the save
        //
        // `repository_id` is taken directly from the document's own column
        // (unlike Box, which derives it via batch).
        //
        // Does NOT interfere with the existing `barcode_status` mirror/guards —
        // those handle the custody STATUS from the box; this handles only the
        // document's own barcode VALUE.
        static::created(function (self $document): void {
            if ($document->barcode === null) {
                return;
            }
            $document->barcodeHistory()->create([
                'old_value' => null,
                'new_value' => $document->barcode,
                'changed_by_user_id' => Auth::id(),
                'changed_at' => now(),
                'repository_id' => $document->repository_id,
            ]);
        });

        static::updated(function (self $document): void {
            if (! $document->wasChanged('barcode')) {
                return;
            }
            $old = $document->getOriginal('barcode');
            $new = $document->barcode;
            if ($old === $new) {
                return;
            }
            $document->barcodeHistory()->create([
                'old_value' => $old,
                'new_value' => $new,
                'changed_by_user_id' => Auth::id(),
                'changed_at' => now(),
                'repository_id' => $document->repository_id,
            ]);
        });

        // F1 (review finding) — re-mirror custody state when a document
        // changes box. The Box mirror (Box::mirrorBarcodeStatusToDocuments)
        // only fires on a box's OWN barcode_status change, so a document that
        // moves between boxes would otherwise keep the status/date/batch of
        // its OLD box — it could read IN while sitting in a PERM_OUT box.
        //
        // Here we re-apply the DESTINATION box's authoritative
        // `barcode_status`, backfill `disinfestation_date` when that box is
        // PERM_OUT (mirroring the box-side backfill so the per-document A1.2
        // rule still holds), and align `batch_id` with the new box.
        //
        // Recursion safety: the Box mirror does a bulk UPDATE (no model
        // events) — it never re-saves this Document instance. This hook does
        // a per-document `saveQuietly()` only when a field is actually stale,
        // and saveQuietly() does not fire model events, so it can never
        // re-enter the box mirror or this hook. The guard also short-circuits
        // when nothing is stale, so a re-save is a no-op.
        static::updated(function (self $document): void {
            if (! $document->wasChanged('current_box_id')) {
                return;
            }
            $document->syncCustodyFromCurrentBox();
        });

        // Same re-mirror on CREATE: a document created already inside a box
        // (add-document-from-box prefill, bulk import of a box's contents, a
        // fixture placing a doc in an already-PERM_OUT box) must reflect that
        // box's authoritative barcode_status + disinfestation_date from the
        // start — not just on a later move. saveQuietly() inside the hook fires
        // no events, so it can't re-enter the box mirror or this hook.
        static::created(function (self $document): void {
            if ($document->current_box_id === null) {
                return;
            }
            $document->syncCustodyFromCurrentBox();
        });
    }

    /**
     * Override performUpdate so single-model `save()` calls bypass the
     * DocumentBuilder bulk-update guard. Per-instance saves DO fire the
     * `updating`/`updated` events that DocumentObserver hooks into, so the
     * identifier-history audit trail is preserved — only the guard, which
     * exists for query-level bulk updates, must be temporarily suspended.
     */
    protected function performUpdate(Builder $query)
    {
        $previous = static::$bypassAuditGuard;
        static::$bypassAuditGuard = true;

        try {
            return parent::performUpdate($query);
        } finally {
            static::$bypassAuditGuard = $previous;
        }
    }

    /**
     * RFQ App.1 #2 — Batch 50 is reserved for wills documents. A document
     * placed in the wills-reserve batch MUST belong to a wills series.
     *
     * Extracted so BOTH the `saving()` guard and the quiet custody re-mirror
     * ({@see self::syncCustodyFromCurrentBox()}) enforce the same invariant —
     * the latter uses saveQuietly() and would otherwise skip the guard.
     *
     * @throws \DomainException when the placement violates the wills reserve.
     */
    private static function assertWillsBatchInvariant(int|string|null $batchId, int|string|null $seriesId): void
    {
        if ($batchId === null) {
            return;
        }

        $batch = Batch::withoutGlobalScopes()->find($batchId);
        if ($batch === null || (int) $batch->batch_number !== Batch::WILLS_BATCH) {
            return;
        }

        $series = $seriesId !== null ? Series::find($seriesId) : null;
        if ($series === null || ! $series->is_wills_series) {
            throw new \DomainException(
                'Batch ' . Batch::WILLS_BATCH
                . ' is reserved for wills documents (RFQ App.1 #2); '
                . 'assign a wills series before placing the document there.'
            );
        }
    }
}
