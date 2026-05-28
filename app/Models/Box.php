<?php

namespace App\Models;

use App\Models\Scopes\ThroughBatchRepositoryScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

class Box extends Model implements AuditableContract, Sortable
{
    use Auditable;
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

    protected $fillable = [
        'sort_order',
        'box_type', 'box_number', 'batch_id', 'parent_box_id',
        'barcode', 'barcode_status', 'location_id', 'disinfestation_date',
        'is_legacy', 'notes',
        // RFQ Appendix 2 §vii — "box destroyed" business state.
        'destroyed_at', 'destroyed_by_user_id', 'destroyed_reason',
    ];

    protected $casts = [
        'disinfestation_date' => 'date',
        'is_legacy' => 'boolean',
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

        // RFQ App.1 #3 — IN_SITU / NRA boxes require a parent box that is a
        // RAS box. Enforce centrally (every path: UI, importer, console) on
        // create or whenever box_type / parent_box_id changes. We deliberately
        // do NOT fire on an unrelated update of a pre-existing legacy row, so a
        // barcode edit on legacy data is not blocked retroactively.
        static::saving(function (self $box): void {
            if (! $box->requiresParent()) {
                return;
            }
            if (! ($box->isDirty('box_type') || $box->isDirty('parent_box_id'))) {
                return;
            }

            if ($box->parent_box_id === null) {
                throw new \DomainException(
                    "Box type {$box->box_type} requires a parent RAS box (RFQ App.1 #3)."
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
        });

        static::updating(function (self $box): void {
            $box->captureBarcodeTransition();
        });

        static::updated(function (self $box): void {
            $box->flushPendingBarcodeTransition();
        });
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
