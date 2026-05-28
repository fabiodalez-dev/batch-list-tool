<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRepository;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class BoxMovement extends Model implements AuditableContract
{
    use Auditable;
    use BelongsToRepository; // RFQ §3.5.1 — own repository_id + direct scope
    use HasFactory;

    /**
     * `repository_id` is mass-assignable (mirroring Document): the
     * BelongsToRepository `creating` hook is the security gate that validates
     * the chosen value against the acting user's repositories. Callers pass
     * the destination box's repository so a movement is always stamped with
     * the tenant it physically belongs to.
     */
    protected $fillable = [
        'document_id', 'repository_id', 'from_box_id', 'to_box_id', 'movement_date', 'reason', 'user_id',
    ];

    protected $casts = [
        'movement_date' => 'datetime',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function fromBox(): BelongsTo
    {
        return $this->belongsTo(Box::class, 'from_box_id');
    }

    public function toBox(): BelongsTo
    {
        return $this->belongsTo(Box::class, 'to_box_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
