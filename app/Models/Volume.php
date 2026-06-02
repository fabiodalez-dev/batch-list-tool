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
     * Guards against stale eager-loaded relations: if document_id changed after
     * the `document` relation was loaded (e.g. the field was re-assigned in
     * memory before save), we unset the stale relation so the fresh FK is
     * re-loaded from DB.
     *
     * @see HasCustomFields::customFieldRepositoryId()
     */
    public function customFieldRepositoryId(): ?int
    {
        // If the 'document' relation is already loaded but its PK no longer matches
        // the current document_id FK, the cached relation is stale — evict it so the
        // re-load below fetches the correct document (and thus the correct repository).
        if ($this->relationLoaded('document')
            && $this->document?->getKey() !== $this->document_id) {
            $this->unsetRelation('document');
        }

        $document = $this->relationLoaded('document') ? $this->document : $this->document()->first();

        return $document?->repository_id !== null ? (int) $document->repository_id : null;
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
