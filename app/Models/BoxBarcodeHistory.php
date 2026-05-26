<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRepository;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BoxBarcodeHistory — append-only log of barcode / barcode_status changes
 * for a Box (RFQ §3.1.5).
 *
 * Each row represents a transition: (previous_barcode, previous_status)
 * -> (new_barcode, new_status), captured at `changed_at` by
 * `changed_by_user_id`, with an optional `reason`.
 *
 * The history table is itself the audit trail for the Box's barcode field;
 * we therefore do NOT make this model Auditable — that would double-log every
 * insertion into owen-it/laravel-auditing (which already audits the Box).
 *
 * The `repository_id` is mirrored from the parent box's batch for tenant
 * scoping (boxes themselves don't carry a repository_id column).
 */
class BoxBarcodeHistory extends Model
{
    use BelongsToRepository;
    use HasFactory;

    /**
     * Table name is explicit because the conventional plural would mangle "history".
     */
    protected $table = 'box_barcode_history';

    protected $fillable = [
        'box_id',
        'previous_barcode',
        'new_barcode',
        'previous_status',
        'new_status',
        'changed_at',
        'changed_by_user_id',
        'reason',
        'repository_id',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function box(): BelongsTo
    {
        return $this->belongsTo(Box::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    /**
     * Record a barcode/status transition for the given box.
     *
     * - `$userId` defaults to `auth()->id()` when null.
     * - `repository_id` is inherited from the box's batch if not overridden
     *   (the Box::recordBarcodeChange() observer hook is the normal call-site
     *   and passes the value explicitly).
     * - `changed_at` defaults to now() at the DB layer (`useCurrent()`),
     *    but is set explicitly here so factories / back-fills can override it.
     */
    public static function recordChange(
        Box $box,
        ?string $previousBarcode,
        ?string $newBarcode,
        ?string $previousStatus = null,
        ?string $newStatus = null,
        ?string $reason = null,
        ?int $userId = null,
        ?int $repositoryId = null,
    ): self {
        return static::create([
            'box_id' => $box->getKey(),
            'previous_barcode' => (string) $previousBarcode,
            'new_barcode' => $newBarcode,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'changed_at' => now(),
            'changed_by_user_id' => $userId ?? auth()->id(),
            'reason' => $reason,
            'repository_id' => $repositoryId ?? $box->batch?->repository_id,
        ]);
    }
}
