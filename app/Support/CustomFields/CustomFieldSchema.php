<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

use App\Models\CustomFieldDefinition;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;

/**
 * Builds the Filament 5 form-field array for active custom field definitions
 * scoped to a repository and entity type.
 *
 * Usage:
 *   CustomFieldSchema::for('document', $repositoryId)
 *   — returns array<Component> ready to pass to Section::make()->schema([...])
 *
 * All fields are keyed under 'custom.{key}' so Livewire routes them into the
 * 'custom' sub-array of the form data, which HandlesCustomFieldForm then
 * passes to $record->setCustomFieldData().
 *
 * Type mapping (spec §"Form injection"):
 *   text      → TextInput
 *   textarea  → Textarea
 *   number    → TextInput (->numeric())
 *   boolean   → Toggle
 *   date      → DatePicker
 *   datetime  → DateTimePicker
 *   select    → Select (single; options from definition->options array)
 *   email     → TextInput (->email())
 *   url       → TextInput (->url())
 *
 * Field-permission-matrix integration is OUT OF SCOPE for v1.
 */
final class CustomFieldSchema
{
    /**
     * Return an array of Filament form components for the active custom field
     * definitions belonging to $repositoryId + $entityType, keyed
     * `custom.{key}`. Returns an empty array when there are no active
     * definitions or when $repositoryId is null.
     *
     * @param string $entityType One of: document, batch, box, volume.
     * @param int|null $repositoryId The repository to scope definitions to.
     * @return array<int, Component>
     */
    public static function for(string $entityType, ?int $repositoryId): array
    {
        if ($repositoryId === null) {
            return [];
        }

        $definitions = CustomFieldDefinition::query()
            ->where('repository_id', $repositoryId)
            ->where('entity_type', $entityType)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        if ($definitions->isEmpty()) {
            return [];
        }

        return $definitions
            ->map(fn (CustomFieldDefinition $def): Component => self::buildComponent($def))
            ->all();
    }

    /**
     * Build the Filament component for a single definition.
     */
    private static function buildComponent(CustomFieldDefinition $def): Component
    {
        // Field key routed into the 'custom' sub-array so the page trait
        // can reliably extract $data['custom'] without collision with native columns.
        $name = 'custom.' . $def->key;

        $component = match ($def->type) {
            'textarea' => Textarea::make($name)->rows(3)->columnSpanFull(),
            'number' => TextInput::make($name)->numeric(),
            'boolean' => Toggle::make($name),
            'date' => DatePicker::make($name),
            'datetime' => DateTimePicker::make($name),
            'select' => self::buildSelect($name, $def),
            'email' => TextInput::make($name)->email(),
            'url' => TextInput::make($name)->url(),
            default => TextInput::make($name),   // 'text' and fallback
        };

        // Apply label from the definition.
        $component->label($def->label);

        // Apply required validation when the definition requests it.
        if ($def->is_required) {
            $component->required();
        }

        // Apply helper text when present.
        if (filled($def->help_text)) {
            $component->helperText($def->help_text);
        }

        return $component;
    }

    /**
     * Build a Select component from the definition's options array.
     * Options stored as [{value, label}, ...] in the definition.
     * v1: single-select only (spec §"Non-goals").
     */
    private static function buildSelect(string $name, CustomFieldDefinition $def): Select
    {
        $options = [];
        if (is_array($def->options)) {
            foreach ($def->options as $opt) {
                if (isset($opt['value'], $opt['label'])) {
                    $options[(string) $opt['value']] = (string) $opt['label'];
                }
            }
        }

        return Select::make($name)
            ->options($options)
            ->native(false);
    }
}
