<?php

namespace App\Models;

use App\Models\Concerns\ConditionallyPreloadsRelations;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;

class Box extends Model implements AuditableContract, Sortable
{
    use Auditable;
    use ConditionallyPreloadsRelations;
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
    ];

    protected $casts = [
        'disinfestation_date' => 'date',
        'is_legacy' => 'boolean',
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
