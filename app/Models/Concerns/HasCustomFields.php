<?php

namespace App\Models\Concerns;

use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Applied to Document, Batch, Box, Volume.
 *
 * Provides:
 *   - customFieldValues()         — MorphMany relationship
 *   - customFieldEntityType()     — returns the entity key (document/batch/box/volume)
 *   - customFieldRepositoryId()   — returns the repository_id for scoping definitions;
 *                                   Box and Volume OVERRIDE this method
 *   - customFieldDefinitions()    — scoped query (active, ordered)
 *   - getCustomFieldData()        — [key => typed_value] for all active definitions
 *   - setCustomFieldData(array)   — upsert / delete values for active definitions only
 *
 * Field-permission-matrix integration is OUT OF SCOPE for v1.
 */
trait HasCustomFields
{
    /**
     * All stored custom-field values for this record (polymorphic).
     */
    public function customFieldValues(): MorphMany
    {
        return $this->morphMany(CustomFieldValue::class, 'customizable');
    }

    /**
     * The entity key for this model as stored in custom_field_definitions.entity_type.
     * Must match one of CustomFieldDefinition::ENTITY_TYPES keys.
     * Defaults to a lowercase short class name — concrete models should not need
     * to override this because Document→'document', Batch→'batch', Box→'box', Volume→'volume'.
     */
    public function customFieldEntityType(): string
    {
        return strtolower(class_basename(static::class));
    }

    /**
     * The repository_id used to scope definitions to this record.
     *
     * Default: read `repository_id` directly from the model column
     * (works for Document and Batch, both of which have the column).
     *
     * Box and Volume OVERRIDE this method because they derive the value
     * indirectly (via batch and document respectively).
     */
    public function customFieldRepositoryId(): ?int
    {
        $value = $this->getAttribute('repository_id');

        return $value !== null ? (int) $value : null;
    }

    /**
     * Base query for active definitions that belong to this entity type and
     * repository, ordered by sort_order ascending.
     *
     * @return Builder<CustomFieldDefinition>
     */
    public function customFieldDefinitions(): Builder
    {
        return CustomFieldDefinition::query()
            ->where('repository_id', $this->customFieldRepositoryId())
            ->where('entity_type', $this->customFieldEntityType())
            ->where('is_active', true)
            ->orderBy('sort_order');
    }

    /**
     * Return an associative array of [definition_key => typed_value] for every
     * active definition belonging to this entity's repository + entity_type,
     * including null entries when no value has been stored yet.
     *
     * Uses eager-loaded `customFieldValues.definition` when already available,
     * otherwise performs two queries (definitions + values).
     *
     * @return array<string, mixed>
     */
    public function getCustomFieldData(): array
    {
        $definitions = $this->customFieldDefinitions()->get();

        if ($definitions->isEmpty()) {
            return [];
        }

        // Load stored values keyed by definition_id for fast lookup.
        /** @var Collection<int,CustomFieldValue> $stored */
        $stored = $this->customFieldValues()
            ->whereIn('custom_field_definition_id', $definitions->pluck('id'))
            ->with('definition')
            ->get()
            ->keyBy('custom_field_definition_id');

        $data = [];
        foreach ($definitions as $def) {
            /** @var CustomFieldValue|null $valueModel */
            $valueModel = $stored->get($def->id);
            $data[$def->key] = $valueModel?->getTypedValueAttribute();
        }

        return $data;
    }

    /**
     * Upsert / delete custom-field value rows for this record.
     *
     * Only keys that correspond to an active definition (in this repository +
     * entity_type) are processed; unknown or inactive definition keys in $data
     * are silently ignored to prevent storing orphaned data.
     *
     * Rows for definitions whose key is NOT present in $data (or whose value is
     * null) are deleted (clean slate for omitted fields). This mirrors the
     * behaviour of the Filament form: unchecking a required toggle submits null,
     * and null means "remove the stored row".
     *
     * @param array<string, mixed> $data Key-value pairs keyed by definition key.
     */
    public function setCustomFieldData(array $data): void
    {
        $definitions = $this->customFieldDefinitions()->get()->keyBy('key');

        if ($definitions->isEmpty()) {
            return;
        }

        $morphType = get_class($this);
        $morphId = $this->getKey();

        foreach ($definitions as $key => $def) {
            if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                // Remove stored value when omitted or null.
                $this->customFieldValues()
                    ->where('custom_field_definition_id', $def->id)
                    ->delete();

                continue;
            }

            // Coerce booleans / other types to their string representation for storage.
            $raw = $data[$key];
            if (is_bool($raw)) {
                $raw = $raw ? '1' : '0';
            } elseif ($raw instanceof Carbon || $raw instanceof CarbonInterface) {
                $raw = $def->type === 'date'
                    ? $raw->toDateString()
                    : $raw->toDateTimeString();
            } else {
                $raw = (string) $raw;
            }

            // updateOrCreate on the polymorphic + definition composite key.
            CustomFieldValue::updateOrCreate(
                [
                    'custom_field_definition_id' => $def->id,
                    'customizable_type' => $morphType,
                    'customizable_id' => $morphId,
                ],
                ['value' => $raw],
            );
        }
    }
}
