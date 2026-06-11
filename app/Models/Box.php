<?php

namespace App\Models;

use App\Models\Concerns\HasCustomFields;
use App\Models\Lookup\BarcodeStatus;
use App\Models\Lookup\BoxType;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Support\Lookups;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

class Box extends Model implements AuditableContract, Sortable
{
    use Auditable;
    use HasCustomFields;
    use HasFactory;
    use SoftDeletes;
    use SortableTrait;

    // NOTE: Box has no direct repository_id column — scoping derives via batch.repository_id.
    // Filament Resource for Box should filter on batch.repository_id manually if needed.

    public const TYPES = ['RAS', 'IN_SITU', 'NRA', 'MAV', 'STVC'];

    public const LEGACY_TYPES = ['MAV', 'STVC'];                  // RFQ rule #4

    public const BARCODE_STATUSES = ['IN', 'OUT', 'PERM_OUT'];

    /**
     * Boxes are ordered WITHIN their batch — sort_order is unique per batch_id,
     * not globally. The override below scopes the sort query so renumbering
     * one batch does not touch another. Index in migration: (batch_id, sort_order).
     */
    public array $sortable = [
        'order_column_name' => 'sort_order',
        'sort_when_creating' => true,
    ];

    /**
     * Set to true on a model instance to bypass the PERM_OUT location guard.
     * Used by the import pipeline where legacy data may not carry location info.
     * Does NOT bypass any other guards (disinfestation_date, parent_box, etc.).
     */
    public bool $skipPermOutGuard = false;

    protected $fillable = [
        'sort_order',
        'box_type', 'box_number', 'batch_id', 'parent_box_id',
        // RFQ A1.3 — explicit NULL exception: when true the model guard allows
        // a null parent_box_id for IN_SITU / NRA boxes (provenance genuinely unknown).
        'provenance_unknown',
        'barcode', 'seal_number', 'barcode_status', 'location_id', 'disinfestation_date',
        'is_legacy', 'notes',
        // RFQ Appendix 2 §vii — "box destroyed" business state.
        'destroyed_at', 'destroyed_by_user_id', 'destroyed_reason',
    ];

    protected $casts = [
        'disinfestation_date' => 'date',
        'is_legacy' => 'boolean',
        'provenance_unknown' => 'boolean',
        'destroyed_at' => 'datetime',
    ];

    /**
     * Pending barcode/status transitions, keyed by box primary key.
     * Populated in `updating`, consumed in `updated` — this avoids the
     * "isDirty returns false after save" pitfall.
     *
     * @var array<int|string, array{previous_barcode: ?string, new_barcode: ?string, previous_status: ?string, new_status: ?string}>
     */
    private static array $pendingBarcodeTransitions = [];

    /**
     * Scope sort_order computation to siblings inside the same batch.
     * Without this override the package would compute MAX(sort_order)+1 across
     * ALL boxes, breaking per-batch ordering.
     */
    public function buildSortQuery(): Builder
    {
        return static::query()->where('batch_id', $this->batch_id);
    }

    /**
     * Box has no direct repository_id column — derive it from the parent batch
     * so custom-field definitions are scoped to the correct repository.
     *
     * Guards against stale eager-loaded relations: if batch_id changed after the
     * `batch` relation was loaded (e.g. the field was re-assigned in memory before
     * save), we unset the stale relation so the fresh FK is re-loaded from DB.
     *
     * @see HasCustomFields::customFieldRepositoryId()
     */
    public function customFieldRepositoryId(): ?int
    {
        // If the 'batch' relation is already loaded but its PK no longer matches
        // the current batch_id FK, the cached relation is stale — evict it so the
        // re-load below fetches the correct batch (and thus the correct repository).
        if ($this->relationLoaded('batch')
            && $this->batch?->getKey() !== $this->batch_id) {
            $this->unsetRelation('batch');
        }

        // Use already-loaded relation when available; fall back to a fresh load.
        $batch = $this->relationLoaded('batch') ? $this->batch : $this->batch()->first();

        return $batch?->repository_id !== null ? (int) $batch->repository_id : null;
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_box_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_box_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'current_box_id');
    }

    /**
     * Append-only log of barcode / barcode_status transitions for this box
     * (RFQ §3.1.5). Returns rows ordered descending by `changed_at` so the
     * most recent change is first — callers can override with `->orderBy(...)`.
     */
    public function barcodeHistory(): HasMany
    {
        return $this->hasMany(BoxBarcodeHistory::class)->latest('changed_at');
    }

    /**
     * Append-only log of seal-number transitions for this box
     * (RFQ Contract App.2-i — the yellow security seal belongs to the BOX,
     * and a history of every seal number is kept for all boxes, especially
     * the Batch 50 wills reserve). Ordered descending by `changed_at` so the
     * most recent change is first; callers can override with `->orderBy(...)`.
     */
    public function sealNumberHistory(): HasMany
    {
        return $this->hasMany(BoxSealNumberHistory::class)->orderByDesc('changed_at');
    }

    /**
     * Distinct list of previous barcodes this box has ever held.
     * Built from the `barcodeHistory` log; uniqueness is enforced in PHP
     * so the result is stable across DB drivers (SQLite collation quirks).
     *
     * @return Collection<int,string>
     */
    public function previousBarcodes(): Collection
    {
        return $this->barcodeHistory()
            ->pluck('previous_barcode')
            ->unique()
            ->values();
    }

    /**
     * RFQ §3.1.9 — Box can be pinned to a configurable Location
     * (room / work-area / shelf / showcase / temp-holding / …).
     * Nullable: legacy data has no location_id yet.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function movementsTo(): HasMany
    {
        return $this->hasMany(BoxMovement::class, 'to_box_id');
    }

    public function movementsFrom(): HasMany
    {
        return $this->hasMany(BoxMovement::class, 'from_box_id');
    }

    /** RFQ rule #5: PERM_OUT requires disinfestation_date */
    public function canBePermOut(): bool
    {
        return $this->disinfestation_date !== null;
    }

    /** RFQ rule #3: IN_SITU / NRA require parent RAS box */
    public function requiresParent(): bool
    {
        return in_array($this->box_type, ['IN_SITU', 'NRA'], true);
    }

    /**
     * RFQ Appendix 2 §vii — author of the destruction event.
     *
     * Nullable because the FK is `nullOnDelete()`: if the user record is
     * eventually purged, the destruction event itself stays on the row but
     * the author reference is cleared.
     */
    public function destroyedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destroyed_by_user_id');
    }

    /**
     * RFQ Appendix 2 §vii — true once the physical box has been destroyed.
     *
     * Distinct from `deleted_at` (SoftDeletes): a destroyed box keeps its
     * row visible in the archive for provenance, it is only the *physical
     * artefact* that no longer exists.
     */
    public function isDestroyed(): bool
    {
        return $this->destroyed_at !== null;
    }

    /**
     * Query scope — boxes marked physically destroyed.
     *
     * Uses the `boxes_destroyed_at_idx` index from the
     * 2026_05_27_170000 migration; safe to combine with `notDestroyed()`
     * elsewhere (the two scopes are mutually exclusive).
     */
    public function scopeDestroyed(Builder $q): Builder
    {
        return $q->whereNotNull('destroyed_at');
    }

    /**
     * Query scope — boxes still physically present (default operational view).
     */
    public function scopeNotDestroyed(Builder $q): Builder
    {
        return $q->whereNull('destroyed_at');
    }

    /**
     * RFQ Appendix 2 §vii — eligibility gate for `markDestroyed()`.
     *
     * A box can be destroyed iff (a) it has not already been destroyed and
     * (b) every document that ever lived in it has a `catalogue_identifier`
     * (i.e. has been catalogued and removed). Soft-deleted documents still
     * count — a doc that was trashed without ever being catalogued blocks
     * the destruction, because the physical artefact may still be in the
     * box waiting to be re-attached after un-trash.
     *
     * Returns a tuple-shaped array so the UI can surface the precise reason
     * to the operator (single string), instead of a bare boolean.
     *
     * @return array{ok: bool, reason: ?string}
     */
    public function canBeDestroyed(): array
    {
        if ($this->isDestroyed()) {
            return ['ok' => false, 'reason' => 'Box is already marked destroyed.'];
        }

        // Count uncatalogued docs INCLUDING soft-deleted rows. The
        // `documents` relation is defined as a plain HasMany; we re-issue
        // the query through the Document model so we can opt into withTrashed.
        $uncatalogued = Document::withTrashed()
            ->where('current_box_id', $this->getKey())
            ->whereNull('catalogue_identifier')
            ->count();

        if ($uncatalogued > 0) {
            return [
                'ok' => false,
                'reason' => "Box still contains {$uncatalogued} uncatalogued document(s); destroy is allowed only once every document has a catalogue identifier (RFQ Appendix 2 §vii).",
            ];
        }

        return ['ok' => true, 'reason' => null];
    }

    /**
     * RFQ Appendix 2 §vii — record the physical destruction of this box.
     *
     * Stamps `destroyed_at = now()`, the acting user and an optional
     * free-text reason, then persists. The Auditable trait picks up the
     * column diff and writes an `updated` audit row, which is the audit
     * trail for the event (no extra manual log needed).
     *
     * Race-safe: the row is re-acquired with `SELECT ... FOR UPDATE` inside a
     * transaction and the eligibility check is re-evaluated against the locked
     * row. Two operators clicking "Destroy" on the same box at the same instant
     * serialise on the lock; the second request sees `destroyed_at` already
     * stamped and throws instead of double-recording the event.
     *
     * @throws \DomainException if {@see canBeDestroyed()} returns ok=false.
     */
    public function markDestroyed(?string $reason, ?int $userId): void
    {
        DB::transaction(function () use ($reason, $userId) {
            // Bypass ThroughBatchRepositoryScope: that scope filters by the
            // authenticated user's repository pivot, which would drop every
            // row in a CLI/queue/tinker context (no auth user) and surface
            // a confusing "Box no longer exists" error. The race-safety
            // guarantee comes from the row lock + canBeDestroyed re-check.
            $locked = static::query()
                ->withoutGlobalScope(ThroughBatchRepositoryScope::class)
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new \DomainException('Box no longer exists.');
            }

            $check = $locked->canBeDestroyed();
            if (! $check['ok']) {
                throw new \DomainException(
                    $check['reason'] ?? 'Box cannot be destroyed.'
                );
            }

            $locked->destroyed_at = now();
            $locked->destroyed_by_user_id = $userId;
            $locked->destroyed_reason = $reason;
            $locked->save();

            // setRawAttributes() does NOT clear loaded relation caches. Invalidate
            // `destroyedBy` so a caller that had already eager-loaded the prior
            // (likely null) relation gets the fresh user on next access.
            $this->setRawAttributes($locked->getAttributes(), true);
            $this->unsetRelation('destroyedBy');
        });
    }

    /**
     * Insert a row into `box_barcode_history` for this box.
     *
     * Public surface so back-fills, importers, or one-off scripts can
     * append history rows directly without going through the observer.
     * The observer pipeline calls this internally on every change to
     * `barcode` or `barcode_status`.
     */
    public function recordBarcodeChange(
        ?string $previousBarcode,
        ?string $newBarcode,
        ?string $previousStatus = null,
        ?string $newStatus = null,
        ?string $reason = null,
    ): void {
        BoxBarcodeHistory::create([
            'box_id' => $this->getKey(),
            'previous_barcode' => (string) $previousBarcode,
            'new_barcode' => $newBarcode,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'changed_at' => now(),
            'changed_by_user_id' => Auth::id(),
            'reason' => $reason,
            'repository_id' => $this->batch?->repository_id,
        ]);
    }

    /**
     * Append a row to box_seal_number_history for this box.
     *
     * Public surface so back-fills / importers can record transitions
     * directly. The model hooks call this internally on every change to
     * `seal_number` (RFQ Contract App.2-i).
     */
    public function recordSealChange(?string $old, ?string $new, ?string $notes = null): void
    {
        $this->sealNumberHistory()->create([
            'old_value' => $old,
            'new_value' => $new,
            'changed_by_user_id' => Auth::id(),
            'changed_at' => now(),
            'notes' => $notes,
            'repository_id' => $this->batch?->repository_id,
        ]);
    }

    /**
     * Multi-tenant scoping (RFQ §3.5.1).
     *
     * `boxes` has no `repository_id` column — tenancy is derived from
     * `boxes.batch_id → batches.repository_id`. The scope restricts queries
     * to the repositories the authenticated user has been assigned to.
     * Admin / super_admin bypass the scope (cross-repo oversight).
     *
     * Also wires the barcode-history observer (RFQ §3.1.5): every change to
     * `barcode` or `barcode_status` is captured into the `box_barcode_history`
     * table by reading the dirty state in `updating` and writing the history
     * row in `updated`.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new ThroughBatchRepositoryScope(
            foreignTable: 'batches',
            foreignKey: 'batch_id',
        ));

        // RFQ App.1 #4 — Legacy box types (MAV, STVC) cannot be created as NEW
        // records. Historical legacy boxes are still importable, but ONLY when
        // explicitly flagged `is_legacy = true` (the migration/import path sets
        // it; the submission §5.4: "imported with a restricted flag; the
        // validator forbids creating new ones"). A legacy type WITHOUT the flag
        // is a forbidden new record. `creating` fires only on INSERT, so editing
        // an existing legacy box is never affected.
        static::creating(function (self $box): void {
            if (in_array($box->box_type, self::LEGACY_TYPES, true) && ! $box->is_legacy) {
                throw ValidationException::withMessages([
                    'box_type' => 'Legacy box types (MAV, STVC) cannot be created for new records; historical ones must be flagged is_legacy (RFQ A1.4).',
                ]);
            }
        });

        // RFQ App.1 #3 — IN_SITU / NRA boxes require a parent box that is a
        // RAS box. Enforce centrally (every path: UI, importer, console) on
        // create or whenever box_type / parent_box_id changes. We deliberately
        // do NOT fire on an unrelated update of a pre-existing legacy row, so a
        // barcode edit on legacy data is not blocked retroactively.
        // RFQ §3.1.11 (part 2 of 3) — validate the ENUM-derived columns against
        // the ACTIVE rows of the lookup tables (App\Models\Lookup\*), which are
        // now the editable source of truth. Only assert when the value is dirty
        // so a pre-existing record carrying a value that was LATER deactivated
        // still loads and can be re-saved (expand, never restrict).
        static::saving(function (self $box): void {
            if ($box->isDirty('box_type')) {
                Lookups::assertActive(BoxType::class, 'box_type', $box->box_type);
            }
            if ($box->isDirty('barcode_status')) {
                Lookups::assertActive(BarcodeStatus::class, 'barcode_status', $box->barcode_status);
            }
        });

        // RFQ App.1 #5 (A1.2) at the BOX level — Task 7 (B1). The box is the
        // authoritative source of truth for barcode status, so the PERM_OUT
        // precondition is enforced here on the box. A box cannot be PERM_OUT
        // unless it has a disinfestation_date (see canBePermOut()). MySQL also
        // has a CHECK constraint (create_boxes_table migration), but SQLite
        // cannot retro-fit one, so this PHP guard is the cross-driver gate.
        // Mirror of the document-level A1.2 guard (kept too — expand, never
        // restrict). Only assert when the status actually moves to PERM_OUT so
        // unrelated saves stay cheap and legacy rows that already carry the
        // value can still re-save.
        static::saving(function (self $box): void {
            if (! $box->isDirty('barcode_status')) {
                return;
            }
            if ($box->barcode_status === 'PERM_OUT' && ! $box->canBePermOut()) {
                throw ValidationException::withMessages([
                    'barcode_status' => 'A box cannot be PERM OUT without a disinfestation date (RFQ A1.2, box level).',
                ]);
            }
        });

        // RFQ-3.1.7-A (review finding) — PERM OUT means the box has been
        // permanently transferred to NRA. Per Appendix 2 §iv, a PERM_OUT box
        // MUST have an NRA location associated with it: "PERM OUT: Taken out
        // of their storage facility and thus should be at NRA and NRA Location
        // should have a NRA location associated with it." This guard fires ONLY
        // when barcode_status is being transitioned to PERM_OUT so:
        //   - Legacy rows already at PERM_OUT but missing a location can be
        //     re-saved as long as the status is not being touched (isDirty guard).
        //   - The disinfestation_date check above remains the primary domain
        //     constraint; this adds the linked-location dimension on top.
        static::saving(function (self $box): void {
            // Only block transitioning an *existing* box to PERM_OUT without a
            // location. New records created directly at PERM_OUT (e.g. legacy
            // data import) are not blocked here — the disinfestation_date guard
            // above already applies to creates, and location is enforced at the
            // UI level on the form for new records.
            if (! $box->exists) {
                return;
            }
            if ($box->skipPermOutGuard) {
                return;
            }
            if (! $box->isDirty('barcode_status')) {
                return;
            }
            if ($box->barcode_status === 'PERM_OUT' && $box->location_id === null) {
                throw ValidationException::withMessages([
                    'location_id' => 'A box cannot be set to PERM OUT without a location (RFQ §3.1.7 / Appendix 2 §iv).',
                ]);
            }
        });

        static::saving(function (self $box): void {
            if (! $box->requiresParent()) {
                return;
            }
            if (! ($box->isDirty('box_type') || $box->isDirty('parent_box_id'))) {
                return;
            }

            if ($box->parent_box_id === null) {
                // RFQ A1.3 — explicit-NULL exception: a box flagged as
                // `provenance_unknown` is allowed to have no parent.
                // The flag must be set explicitly (default false) and should
                // be used sparingly (genuine unknown-provenance legacy records).
                if ($box->provenance_unknown === true) {
                    return;
                }

                throw new \DomainException(
                    "Box type {$box->box_type} requires a parent RAS box (RFQ App.1 #3). "
                    . 'Set provenance_unknown=true to allow a null parent for genuinely unknown-provenance records.'
                );
            }

            // The parent must exist AND actually be a RAS box — a non-null
            // parent of the wrong type would otherwise slip an invalid
            // relationship past the rule.
            $parent = static::withoutGlobalScopes()->find($box->parent_box_id);
            if ($parent === null || $parent->box_type !== 'RAS') {
                throw new \DomainException(
                    "Box type {$box->box_type} requires its parent to be a RAS box (RFQ App.1 #3)."
                );
            }

            // F030 defence-in-depth: cross-tenant parent guard. Both sides must
            // resolve non-null for the check to fire — legacy data with a null
            // batch on either side is silently allowed (expand, never restrict).
            $parentRepoId = $parent->batch()->withoutGlobalScopes()->value('repository_id');
            $boxRepoId = $box->batch()->withoutGlobalScopes()->value('repository_id');
            if ($parentRepoId !== null && $boxRepoId !== null && (int) $parentRepoId !== (int) $boxRepoId) {
                throw new \DomainException(
                    "Box type {$box->box_type}: the parent RAS box belongs to a different repository (F030)."
                );
            }
        });

        // Feedback1 Wave C2.2 — RAS status transition rule.
        //
        // "RAS status default IN when created — can be changed to OUT or
        //  PERM OUT. If changed to PERM OUT, the barcode + status go to the
        //  legacy section (handled by the barcode-history observer below).
        //  If status is changed (back) to IN, a NEW barcode has to be added
        //  (mandatory) — it cannot be left blank and cannot be the same as
        //  the barcode that was just archived."
        //
        // We only enforce on an actual transition INTO 'IN' (the status is
        // dirty and the new value is 'IN'), so legacy rows that already sit at
        // IN and unrelated saves are never blocked. The "different barcode"
        // half is checked against the pre-save original barcode.
        static::saving(function (self $box): void {
            if (! $box->isDirty('barcode_status')) {
                return;
            }
            if ($box->barcode_status !== 'IN') {
                return;
            }

            $previousStatus = $box->getOriginal('barcode_status');

            // Only a transition FROM PERM_OUT into IN requires a brand-new
            // barcode: PERM_OUT archives the barcode to the legacy/history
            // section, so re-entering at IN needs a fresh one (Feedback1 C2.2).
            // A plain OUT→IN (e.g. the temporary out-and-back of the
            // disinfestation cycle) keeps the same barcode and must NOT demand a
            // new one. Creating a box straight at IN is not a re-entry either.
            if ($previousStatus !== 'PERM_OUT') {
                return;
            }

            $newBarcode = trim((string) $box->barcode);
            if ($newBarcode === '') {
                throw ValidationException::withMessages([
                    'barcode' => 'A new barcode is mandatory when a box re-enters with status IN (RFQ Feedback1 C2.2).',
                ]);
            }

            $previousBarcode = trim((string) $box->getOriginal('barcode'));
            if ($newBarcode === $previousBarcode) {
                throw ValidationException::withMessages([
                    'barcode' => 'The barcode must be changed to a NEW value when a box re-enters with status IN; the previous barcode is archived in the history (RFQ Feedback1 C2.2).',
                ]);
            }
        });

        // F4 (review finding) — PERM_OUT is a TERMINAL/permanent transfer. The
        // only legitimate exits are: staying PERM_OUT, or re-entering at IN
        // with a NEW barcode (enforced by the C2.2 guard above, which checks
        // the immediate prior status only). Without this guard a box could be
        // round-tripped PERM_OUT → OUT → IN to reuse the archived barcode,
        // because the prior status at the IN transition would be OUT, not
        // PERM_OUT. Forbidding PERM_OUT → OUT makes the PERM_OUT → IN
        // new-barcode rule un-bypassable. Gated on a real status transition so
        // legacy rows and unrelated saves are never affected.
        static::saving(function (self $box): void {
            if (! $box->isDirty('barcode_status')) {
                return;
            }
            if ($box->getOriginal('barcode_status') === 'PERM_OUT'
                && $box->barcode_status === 'OUT') {
                throw ValidationException::withMessages([
                    'barcode_status' => 'PERM OUT is a permanent transfer: a box cannot move from PERM OUT to OUT. It may only stay PERM OUT or re-enter with status IN (which requires a new barcode) (RFQ Feedback1 C2.2).',
                ]);
            }
        });

        // Feedback1 Wave C2.4 — a box marked destroyed MUST carry a destroy
        // date. The form requires it conditionally; this model guard is the
        // cross-path enforcement (importer / console / API) and runs only when
        // the destruction state is being established, so unrelated saves and
        // the markDestroyed() helper (which always stamps a date) are unaffected.
        static::saving(function (self $box): void {
            // F7 (review finding) — only enforce when the destruction state is
            // actually being established/changed, matching the other guards.
            // An unrelated re-save of a box (even one already in a bad legacy
            // state) must not be blocked by this rule.
            if (! $box->isDirty(['destroyed_at', 'destroyed_reason'])) {
                return;
            }

            $destroyedReason = trim((string) $box->destroyed_reason);
            $hasDestroyReason = $destroyedReason !== '';

            // The box is considered "marked destroyed" once it carries a
            // destroyed_at OR a destruction reason. If a reason was supplied
            // without a date, reject — the destroy DATE is the mandatory field.
            if ($hasDestroyReason && $box->destroyed_at === null) {
                throw ValidationException::withMessages([
                    'destroyed_at' => 'A destroy date is mandatory when a box is marked as destroyed (RFQ Feedback1 C2.4).',
                ]);
            }
        });

        // F5 (review finding) — structural integrity guards that until now
        // lived only in the Filament form, so the importer / console / API
        // could create boxes that violate C2.1:
        //   - IN_SITU / NRA boxes (the provenance types, requiresParent())
        //     MUST reference a Location.
        //   - RAS / MAV / STVC boxes MUST carry a non-blank barcode.
        // Gated so pre-existing seeded/imported rows are never retro-broken:
        // we only enforce on the INSERT path, or when the box_type itself
        // changes (which re-qualifies the box for the rule). A bare update of
        // an unrelated column on a legacy row is never blocked.
        static::saving(function (self $box): void {
            $isCreate = ! $box->exists;
            $typeChanged = $box->isDirty('box_type');
            // F2 (review finding) — also re-check when location_id itself
            // changes: an update that NULLs the location_id of an existing
            // IN_SITU / NRA box would otherwise slip past the create/type gate
            // and leave a provenance box without the Location that defines it.
            $locationChanged = $box->isDirty('location_id');
            if (! $isCreate && ! $typeChanged && ! $locationChanged) {
                return;
            }

            // IN_SITU / NRA require a Location — its location IS the box's
            // identity, so a provenance box with none is structurally
            // meaningless. This is enforced at the model level (every path).
            if (in_array($box->box_type, ['IN_SITU', 'NRA'], true)) {
                if ($box->location_id === null) {
                    throw ValidationException::withMessages([
                        'location_id' => 'IN_SITU / NRA boxes must reference a Location (RFQ Feedback1 C2.1).',
                    ]);
                }
            }

            // NOTE: the "RAS box requires a barcode" rule (Feedback1 C2.1) is
            // enforced at the USER-INPUT boundaries — the Filament form
            // (->required on RAS) and the BoxImporter validation — NOT here at
            // the model save. A blanket model guard would wrongly reject the
            // legitimate bulk/seed/provisional paths that create a RAS box
            // record before its physical barcode sticker is assigned (the
            // barcode is a later operational step, unlike the location which
            // defines an In-Situ box). Enforcing it on save broke seeders,
            // performance fixtures and tenant-scope fixtures across the suite
            // without protecting any real-world gap the form+importer don't.
        });

        static::updating(function (self $box): void {
            $box->captureBarcodeTransition();
        });

        static::updated(function (self $box): void {
            $box->flushPendingBarcodeTransition();
        });

        // RFQ Wave 2 — Task 7 (B1). The BOX is authoritative for barcode
        // status; every document currently in the box mirrors the box value.
        // documents.barcode_status is KEPT (expand, never restrict) and stays
        // queryable for filters / omni-search — it is now a synced mirror.
        //
        // Split created / updated (rather than a single `saved`) and gate the
        // update branch on wasChanged('barcode_status') so we only propagate
        // when the authoritative value actually moved — unrelated box saves do
        // not touch the documents table.
        static::created(function (self $box): void {
            $box->mirrorBarcodeStatusToDocuments();
        });

        static::updated(function (self $box): void {
            if (! $box->wasChanged('barcode_status')) {
                return;
            }
            $box->mirrorBarcodeStatusToDocuments();
        });

        // RFQ Contract App.2-i — seal-number chain-of-custody. The yellow
        // security seal belongs to the BOX; every transition is recorded in
        // box_seal_number_history. Split across `created` / `updated` (rather
        // than a single `saved`) so the "from" side is unambiguous: on insert
        // it is always null, on update it is the pre-save original. A single
        // `saved` hook could not tell the two apart because `wasRecentlyCreated`
        // is not reset across later update() calls on the same instance.
        static::created(function (self $box): void {
            if ($box->seal_number === null) {
                return;
            }
            $box->recordSealChange(null, $box->seal_number);
        });

        static::updated(function (self $box): void {
            if (! $box->wasChanged('seal_number')) {
                return;
            }
            $old = $box->getOriginal('seal_number');
            $new = $box->seal_number;
            if ($old === $new) {
                return;
            }
            $box->recordSealChange($old, $new);
        });
    }

    /**
     * RFQ Wave 2 — Task 7 (B1). Mirror this box's authoritative
     * `barcode_status` onto every document currently in the box
     * (`documents.current_box_id = box.id`).
     *
     * `documents.barcode_status` is KEPT as a real, queryable column (filters /
     * omni-search keep working) — it is a synced mirror of the box value, not
     * a competing source of truth.
     *
     * Implementation note: a single bulk UPDATE is used deliberately.
     *   - It is O(1) queries regardless of how many documents are in the box.
     *   - The DocumentBuilder bulk-update guard only blocks the `identifier`
     *     column, so a `barcode_status` mirror passes through untouched.
     *   - It intentionally bypasses the per-model A1.2 document `saving` guard:
     *     the BOX is authoritative and the box-level A1.2 guard has already
     *     validated that a PERM_OUT box carries a disinfestation_date. Forcing
     *     the per-document guard here would reject the mirror for individual
     *     documents that lack their own date, contradicting box authority.
     *     The audit trail for the transition lives in box_barcode_history.
     *
     * Skips writing rows that already hold the target value so the mirror does
     * not churn updated_at / audit noise on documents that are already in sync.
     */
    protected function mirrorBarcodeStatusToDocuments(): void
    {
        $status = $this->barcode_status;
        if ($status === null) {
            return;
        }

        Document::withoutGlobalScopes()
            ->where('current_box_id', $this->getKey())
            ->where(function (Builder $q) use ($status): void {
                $q->where('barcode_status', '!=', $status)
                    ->orWhereNull('barcode_status');
            })
            ->update(['barcode_status' => $status]);

        // A1.2 compliance for the mirrored documents: a PERM_OUT document must
        // carry a disinfestation_date. The box is authoritative and its own
        // A1.2 guard (saving hook above) guarantees a PERM_OUT box has a date,
        // so propagate that date onto every document in the box that does not
        // already have one. This keeps each mirrored document individually
        // compliant with the per-document A1.2 rule instead of relying solely
        // on box authority. We only fill the gaps (whereNull) so a document's
        // own genuine disinfestation_date is never overwritten.
        if ($status === 'PERM_OUT' && $this->disinfestation_date !== null) {
            Document::withoutGlobalScopes()
                ->where('current_box_id', $this->getKey())
                ->whereNull('disinfestation_date')
                ->update(['disinfestation_date' => $this->disinfestation_date]);
        }
    }

    /**
     * Capture a pending barcode/status transition into the static buffer.
     * Called from the `updating` event — at this point `getOriginal(...)`
     * still returns the pre-update value and the new value is on the model.
     *
     * Skipped cases (noise we don't want to log):
     *   - neither `barcode` nor `barcode_status` is dirty,
     *   - the only "change" on `barcode` is leading/trailing whitespace
     *     AND `barcode_status` is unchanged.
     */
    protected function captureBarcodeTransition(): void
    {
        $barcodeDirty = $this->isDirty('barcode');
        $statusDirty = $this->isDirty('barcode_status');

        if (! $barcodeDirty && ! $statusDirty) {
            return;
        }

        $previousBarcode = $this->getOriginal('barcode');
        $newBarcode = $this->barcode;
        $previousStatus = $this->getOriginal('barcode_status');
        $newStatus = $this->barcode_status;

        // Whitespace-only barcode change with no status change → noise, skip.
        if (
            ! $statusDirty
            && trim((string) $previousBarcode) === trim((string) $newBarcode)
        ) {
            return;
        }

        self::$pendingBarcodeTransitions[$this->getKey()] = [
            'previous_barcode' => $previousBarcode,
            'new_barcode' => $newBarcode,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
        ];
    }

    /**
     * Persist the buffered transition (if any) as a row in
     * `box_barcode_history`. Called from the `updated` event so that the
     * Box has already been saved and a FK to its id is valid.
     */
    protected function flushPendingBarcodeTransition(): void
    {
        $key = $this->getKey();

        if (! array_key_exists($key, self::$pendingBarcodeTransitions)) {
            return;
        }

        $transition = self::$pendingBarcodeTransitions[$key];
        unset(self::$pendingBarcodeTransitions[$key]);

        $this->recordBarcodeChange(
            previousBarcode: $transition['previous_barcode'],
            newBarcode: $transition['new_barcode'],
            previousStatus: $transition['previous_status'],
            newStatus: $transition['new_status'],
        );
    }
}
