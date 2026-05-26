<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToRepository;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Batch extends Model implements AuditableContract
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;
    use Auditable;
    use BelongsToRepository;

    /** Reserved batch numbers — cannot be used (RFQ rule #1) */
    public const FORBIDDEN_NUMBERS = [33, 34, 36];

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
     * @see \App\Models\Concerns\BelongsToRepository
     */
    protected $fillable = [
        'batch_number', 'description', 'type', 'repository_id', 'is_active',
    ];

    protected $casts = [
        'batch_number' => 'integer',
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

    public function accessions(): HasMany
    {
        return $this->hasMany(Accession::class);
    }

    public function isForbidden(): bool
    {
        return in_array($this->batch_number, self::FORBIDDEN_NUMBERS, true);
    }

    public function isWillsOnly(): bool
    {
        return $this->batch_number === self::WILLS_BATCH;
    }
}
