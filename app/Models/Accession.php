<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRepository;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Accession extends Model implements AuditableContract
{
    use Auditable;
    use BelongsToRepository;
    use HasFactory;
    use SoftDeletes;

    /**
     * `repository_id` is mass-assignable so Filament admins can write it via
     * `create()` — but the BelongsToRepository `creating` hook is the security
     * gate: it validates the value against the user's pivot and throws for
     * any non-privileged attempt to write to a foreign tenant.
     *
     * @see BelongsToRepository
     */
    protected $fillable = [
        'code', 'accession_number', 'accession_date', 'authority_id', 'batch_id', 'repository_id', 'notes',
    ];

    protected $casts = [
        'accession_date' => 'date',
    ];

    public function authority(): BelongsTo
    {
        return $this->belongsTo(Authority::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
