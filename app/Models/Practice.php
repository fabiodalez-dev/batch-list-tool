<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * RFQ §3.1.11 — Controlled vocabulary for `documents.practice`
 * (typical values: NTG, PrivatePractice, mixed).
 *
 * D4 (Feedback1 Wave D) — optional `identifier` used by the import pipeline
 * (mirrors DocumentType); optional `repository_id` so a practice can be
 * scoped to a specific repository (NULL = global).
 */
class Practice extends Model implements AuditableContract
{
    use Auditable;
    use HasFactory;

    protected $fillable = ['name', 'description', 'is_active', 'identifier', 'repository_id'];

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
