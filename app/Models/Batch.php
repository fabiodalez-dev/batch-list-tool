<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRepository;
use App\Models\Concerns\HasCustomFields;
use App\Models\Pivots\AccessionBatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Batch extends Model implements AuditableContract
{
    use Auditable;
    use BelongsToRepository;
    use HasCustomFields;
    use HasFactory;
    use SoftDeletes;

    /**
     * Forbidden batch numbers — cannot be used for any record (RFQ Appendix 2).
     * 34 and 36 are unused and will never be used.
     * Note: 33 is NOT forbidden — it is reserved for old MAV boxes only; see RESERVED_MAV_BATCH.
     */
    public const FORBIDDEN_NUMBERS = [34, 36];

    /**
     * Batch number reserved exclusively for old MAV boxes (RFQ Appendix 2).
     * It is a VALID batch number — it is NOT in FORBIDDEN_NUMBERS.
     */
    public const RESERVED_MAV_BATCH = 33;

    /** Batch number exclusively for wills documents (RWL, OWL) — RFQ rule #2 */
    public const WILLS_BATCH = 50;

    /** Main collection batches range */
    public const MAIN_COLLECTION_MAX = 29;

    /**
     * `repository_id` is mass-assignable so Filament admins can write it via
     * `create()` — but the BelongsToRepository `creating` hook is the security
     * gate: it validates the value against the user's pivot and throws for
     * any non-privileged attempt to write to a foreign tenant.
     *
     * @see BelongsToRepository
     */
    protected $fillable = [
        'batch_number', 'description', 'type', 'repository_id', 'is_active',
    ];

    protected $casts = [
        // batch_number is a SHORT STRING: it is usually numeric ("1".."50") but
        // the archive also has non-numeric catch-all batches ("Unknown", "NULL").
        // The RFQ reserved-number helpers below cast to int before comparing, so
        // a non-numeric batch simply never matches a reserved number.
        'batch_number' => 'string',
        'is_active' => 'boolean',
    ];

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function boxes(): HasMany
    {
        return $this->hasMany(Box::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function accessions(): BelongsToMany
    {
        // F041 — ->using() wires the AccessionBatch pivot model so its
        // same-repository creating() guard fires on every attach/sync.
        return $this->belongsToMany(Accession::class, 'accession_batch')
            ->using(AccessionBatch::class)
            ->withTimestamps();
    }

    /**
     * Active batches only. NAF Feedback-1 comment #9 gave `is_active` a real
     * meaning: an inactive batch stays visible/editable on the Batches list
     * (so staff can reactivate it) but must not be offered as a selectable
     * parent when creating Boxes or Documents. Use this scope on those
     * selects: `Batch::active()->...`.
     *
     * @param Builder<Batch> $query
     * @return Builder<Batch>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isForbidden(): bool
    {
        $number = $this->numericBatchNumber();

        return $number !== null && in_array($number, self::FORBIDDEN_NUMBERS, true);
    }

    /**
     * Returns true when this batch is the MAV-reserved batch (33).
     * This is a VALID batch number — not forbidden — but restricted to old MAV boxes only.
     */
    public function isReservedMav(): bool
    {
        return $this->numericBatchNumber() === self::RESERVED_MAV_BATCH;
    }

    public function isWillsOnly(): bool
    {
        return $this->numericBatchNumber() === self::WILLS_BATCH;
    }

    /**
     * The RFQ reserved-number rules apply only to NUMERIC batch numbers. A
     * non-numeric batch ("Unknown", "NULL") is never reserved, so the helpers
     * only compare when the value is a canonical integer string — `is_numeric`
     * plus an int round-trip guards against "34abc" or float-ish inputs slipping
     * through `(int)` coercion and falsely matching a reserved number.
     */
    private function numericBatchNumber(): ?int
    {
        $value = $this->batch_number;
        if ($value === null || ! is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return (string) $int === (string) $value ? $int : null;
    }
}
