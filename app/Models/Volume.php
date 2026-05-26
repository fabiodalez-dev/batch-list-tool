<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Volume extends Model implements AuditableContract
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'document_id', 'volume_number', 'dates_start', 'dates_end', 'notes',
    ];

    protected $casts = [
        'dates_start' => 'date',
        'dates_end' => 'date',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
