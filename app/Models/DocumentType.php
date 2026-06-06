<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * RFQ §3.1.11 — Controlled vocabulary for `documents.document_type`.
 *
 * Reference-only model (no FK on documents to keep the legacy import
 * permissive). Auditable so admin changes to the canonical list are
 * traceable.
 */
class DocumentType extends Model implements AuditableContract
{
    use Auditable;
    use HasFactory;

    protected $fillable = ['name', 'identifier', 'description', 'is_active'];

    /**
     * Feedback1 Wave D1 — N:N relation to Series via the
     * document_type_series pivot table.
     */
    public function series(): BelongsToMany
    {
        return $this->belongsToMany(Series::class, 'document_type_series')
            ->withTimestamps();
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
