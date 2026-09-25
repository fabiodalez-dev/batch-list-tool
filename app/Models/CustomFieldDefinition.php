<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class CustomFieldDefinition extends Model implements AuditableContract
{
    use Auditable;
    use HasFactory;

    /**
     * Allowed entity types — map of key to model class.
     * Used by the RelationManager form Select and trait routing.
     *
     * @var array<string,class-string<Model>>
     */
    public const ENTITY_TYPES = [
        'document' => Document::class,
        'batch' => Batch::class,
        'box' => Box::class,
        'volume' => Volume::class,
        // Client 2026-09-24: she had been asking for new Authority columns
        // every few days, each one a code change. Authorities join the
        // entities whose extra columns she can add herself.
        'authority' => Authority::class,
        // Client 2026-09-25: "Is it possible to add new columns that are
        // independent (standalone)?" — extended to every remaining entity, so
        // no template is left needing a developer for an extra column.
        //
        // 'documentType' is camelCase on purpose: it matches the template
        // registry and the model's own customFieldEntityType(). Document types
        // have no repository_id of their own, so their definitions are scoped to
        // the active repository and anchored to stored values.
        'series' => Series::class,
        'location' => Location::class,
        'documentType' => DocumentType::class,
        'accession' => Accession::class,
    ];

    /**
     * Allowed field types (spec §Architecture custom_field_definitions.type).
     *
     * @var array<int,string>
     */
    public const TYPES = [
        'text',
        'textarea',
        'number',
        'boolean',
        'date',
        'datetime',
        'select',
        'email',
        'url',
    ];

    protected $fillable = [
        'repository_id',
        'entity_type',
        'key',
        'label',
        'type',
        'options',
        'is_required',
        'is_active',
        'help_text',
        'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'is_required' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }
}
