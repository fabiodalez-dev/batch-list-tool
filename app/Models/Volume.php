<?php

namespace App\Models;

use App\Models\Concerns\HasCustomFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class Volume extends Model implements AuditableContract
{
    use Auditable;
    use HasCustomFields;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'document_id', 'volume_number', 'dates_start', 'dates_end', 'notes',
    ];

    protected $casts = [
        'dates_start' => 'date',
        'dates_end' => 'date',
    ];

    /**
     * Volume has no direct repository_id column — derive it from the parent
     * document so custom-field definitions are scoped to the correct repository.
     *
     * @see HasCustomFields::customFieldRepositoryId()
     */
    public function customFieldRepositoryId(): ?int
    {
        $document = $this->relationLoaded('document') ? $this->document : $this->document()->first();

        return $document?->repository_id !== null ? (int) $document->repository_id : null;
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
