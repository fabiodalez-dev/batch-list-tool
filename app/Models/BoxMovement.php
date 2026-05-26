<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class BoxMovement extends Model implements AuditableContract
{
    use Auditable;
    use HasFactory;

    protected $fillable = [
        'document_id', 'from_box_id', 'to_box_id', 'movement_date', 'reason', 'user_id',
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
