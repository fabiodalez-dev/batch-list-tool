<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

    protected $fillable = ['name', 'description', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
