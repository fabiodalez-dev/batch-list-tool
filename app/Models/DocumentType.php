<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AnchorsCustomFieldDefinitions;
use App\Models\Concerns\HasCustomFields;
use App\Support\CustomFields\CustomFieldResolver;
use Illuminate\Database\Eloquent\Builder;
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
    use AnchorsCustomFieldDefinitions;
    use Auditable;
    use HasCustomFields;
    use HasFactory;

    protected $fillable = ['name', 'identifier', 'description', 'is_active'];

    /**
     * The entity key as stored in custom_field_definitions.entity_type.
     *
     * The trait default lowercases the class name, which would give
     * "documenttype" — but every other part of the system (the template
     * registry, the admin's entity picker, ColumnLabels) spells this entity
     * "documentType", and two spellings of one key would silently split the
     * definitions in half.
     */
    public function customFieldEntityType(): string
    {
        return 'documentType';
    }

    /**
     * Document types are shared across the archive: the table has no
     * repository_id column, so the only repository available is whichever one
     * is active. See {@see AnchorsCustomFieldDefinitions} for why the read is
     * anchored to the stored values as well.
     */
    public function customFieldRepositoryId(): ?int
    {
        return CustomFieldResolver::activeRepositoryId();
    }

    /**
     * @return Builder<CustomFieldDefinition>
     */
    public function customFieldDefinitions(): Builder
    {
        return $this->anchoredCustomFieldDefinitions();
    }

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
