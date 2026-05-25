<?php

namespace App\Models;

use App\Models\Concerns\ConditionallyPreloadsRelations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Box extends Model implements AuditableContract
{
    use Auditable;
    use ConditionallyPreloadsRelations;
    use HasFactory;
    use SoftDeletes;

    // NOTE: Box has no direct repository_id column — scoping derives via batch.repository_id.
    // Filament Resource for Box should filter on batch.repository_id manually if needed.

    public const TYPES = ['RAS', 'IN_SITU', 'NRA', 'MAV', 'STVC'];
    public const LEGACY_TYPES = ['MAV', 'STVC'];                  // RFQ rule #4
    public const BARCODE_STATUSES = ['IN', 'OUT', 'PERM_OUT'];

    protected $fillable = [
        'box_type', 'box_number', 'batch_id', 'parent_box_id',
        'barcode', 'barcode_status', 'disinfestation_date',
        'is_legacy', 'notes',
    ];

    protected $casts = [
        'disinfestation_date' => 'date',
        'is_legacy' => 'boolean',
    ];

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
     * Multi-tenant scoping (RFQ §3.5.1).
     *
     * `boxes` has no `repository_id` column — tenancy is derived from
     * `boxes.batch_id → batches.repository_id`. The scope restricts queries
     * to the repositories the authenticated user has been assigned to.
     * Admin / super_admin bypass the scope (cross-repo oversight).
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new \App\Models\Scopes\ThroughBatchRepositoryScope(
            foreignTable: 'batches',
            foreignKey:   'batch_id',
        ));
    }
}
