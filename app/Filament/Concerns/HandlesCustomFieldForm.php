<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

/**
 * Mix this trait into the Create and Edit page classes of Document, Batch,
 * Box, and Volume resources to wire the custom-field EAV storage into the
 * Filament page lifecycle.
 *
 * Lifecycle hooks used:
 *   - mutateFormDataBeforeFill(): loads saved custom-field values from the
 *     EAV table and injects them as 'custom.{key}' entries in the form data
 *     array. Called by Filament on EDIT (fill from record); on CREATE the
 *     form is filled with an empty array so the fields render with defaults.
 *
 *   - mutateFormDataBeforeCreate() / mutateFormDataBeforeSave(): strips the
 *     'custom' sub-array from the data before it reaches Eloquent's fill()
 *     (preventing an "add [custom] to fillable" MassAssignmentException) and
 *     stashes it in $this->pendingCustomFieldData for the after-hooks.
 *
 *   - afterCreate() / afterSave(): delegates the stashed custom-field payload
 *     to $record->setCustomFieldData().
 *
 * Requirements on the host page class:
 *   - The resource's form() schema must include a Section whose fields are
 *     produced by CustomFieldSchema::for($entityType, $repositoryId) and are
 *     all keyed under 'custom.{key}'.
 *   - The underlying model must use the HasCustomFields trait.
 *
 * Field-permission-matrix integration is OUT OF SCOPE for v1.
 */
trait HandlesCustomFieldForm
{
    /**
     * Temporary store for the 'custom' sub-array between the
     * mutateFormDataBeforeCreate/Save hook (where we strip it) and the
     * afterCreate/afterSave hook (where we persist it).
     *
     * @var array<string, mixed>
     */
    private array $pendingCustomFieldData = [];

    /**
     * Inject saved custom-field values into the fill data so the form shows
     * the stored values on edit. On create the record is null so this returns
     * the unchanged $data (the Section renders with empty / default values).
     *
     * Filament calls this method only when filling the form from an existing
     * record (EditRecord::fillForm).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        if ($record === null) {
            return $data;
        }

        // Only proceed when the model has the trait methods available.
        if (! method_exists($record, 'getCustomFieldData')) {
            return $data;
        }

        // getCustomFieldData() returns [key => typed_value] for active definitions.
        $customData = $record->getCustomFieldData();

        // Merge under the 'custom' key so Filament populates 'custom.{key}' fields.
        $data['custom'] = $customData;

        return $data;
    }

    /**
     * Strip the 'custom' sub-array from the create payload before it reaches
     * Eloquent's fill() — custom_field_values are stored via EAV, not as
     * direct model columns. The payload is stashed for afterCreate().
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->mutateFormDataBeforeCreateCustomFields($data);
    }

    /**
     * Strip the 'custom' sub-array from the save payload before it reaches
     * Eloquent's fill() on an existing record. The payload is stashed for
     * afterSave().
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->mutateFormDataBeforeSaveCustomFields($data);
    }

    /**
     * Named helper so page classes that override mutateFormDataBeforeCreate
     * can explicitly call this logic without triggering an infinite loop.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreateCustomFields(array $data): array
    {
        $this->pendingCustomFieldData = $data['custom'] ?? [];
        unset($data['custom']);

        return $data;
    }

    /**
     * Named helper so page classes that override mutateFormDataBeforeSave
     * can explicitly call this logic without triggering an infinite loop.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSaveCustomFields(array $data): array
    {
        $this->pendingCustomFieldData = $data['custom'] ?? [];
        unset($data['custom']);

        return $data;
    }

    /**
     * Persist custom-field values after a new record has been created.
     */
    protected function afterCreate(): void
    {
        $this->saveCustomFields();
    }

    /**
     * Persist custom-field values after an existing record has been saved.
     */
    protected function afterSave(): void
    {
        $this->saveCustomFields();
    }

    /**
     * Delegate the stashed custom payload to the model's setCustomFieldData().
     * Silently skips when the record does not implement HasCustomFields.
     */
    private function saveCustomFields(): void
    {
        $record = $this->getRecord();

        if ($record === null) {
            return;
        }

        if (! method_exists($record, 'setCustomFieldData')) {
            return;
        }

        $customData = $this->pendingCustomFieldData;

        if (! is_array($customData)) {
            $customData = [];
        }

        $record->setCustomFieldData($customData);

        // Reset to avoid stale data if the page is re-used (create-another).
        $this->pendingCustomFieldData = [];
    }
}
