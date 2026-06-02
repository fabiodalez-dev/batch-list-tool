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
