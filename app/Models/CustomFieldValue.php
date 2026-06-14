<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Date;

class CustomFieldValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'custom_field_definition_id',
        'customizable_type',
        'customizable_id',
        'value',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(CustomFieldDefinition::class, 'custom_field_definition_id');
    }

    public function customizable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Return the stored value cast to its correct PHP type according to the
     * linked definition's `type`.
     *
     * Types handled:
     *   boolean  → (bool)
     *   number   → numeric string preserved when possible (int or float)
     *   date     → Carbon (date only)
     *   datetime → Carbon (date + time)
     *   select   → single string in v1 (JSON-ready for multi-select later)
     *   all else → plain string
     *
     * Returns null when the stored `value` is null or the definition is not loaded.
     *
     * @return bool|int|float|string|Carbon|null
     */
    public function getTypedValueAttribute(): mixed
    {
        if ($this->value === null) {
            return null;
        }

        $definition = $this->relationLoaded('definition') ? $this->definition : null;
        $type = $definition instanceof CustomFieldDefinition ? $definition->type : null;

        // When definition not loaded fall back to returning the raw string so
        // callers that have not eager-loaded the relation still get something usable.
        if ($type === null) {
            return $this->value;
        }

        return match ($type) {
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'number' => is_numeric($this->value)
                ? (str_contains($this->value, '.') ? (float) $this->value : (int) $this->value)
                : $this->value,
            'date' => Date::parse($this->value)->startOfDay(),
            'datetime' => Date::parse($this->value),
            // v1: single-select stored as plain string.
            // JSON array stored here signals a future multi-select upgrade path.
            'select' => $this->value,
            default => (string) $this->value,
        };
    }
}
