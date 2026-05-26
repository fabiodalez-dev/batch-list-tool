<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Support\FieldPermissions;
use Closure;
use Filament\Forms\Components\Field as FormField;
use Filament\Schemas\Components\Component as FormComponent;
use Filament\Tables\Columns\Column as TableColumn;

/**
 * Mix this trait into a Filament Resource to wrap individual form fields
 * and table columns with the per-field, per-role gates declared in
 * `config/field_permissions.php` (RFQ §3.1.8).
 *
 * The helpers are intentionally tiny and stateless: they take a fully
 * built component, layer the gates on top of any conditions the caller
 * already configured, and return the same instance. This means existing
 * Resource code stays linear and readable — wrapping is a one-line
 * change per field:
 *
 *   self::gateField(
 *       Forms\Components\TextInput::make('identifier')->required(),
 *       'document',
 *   ),
 *
 * ## Composition with existing rules (important)
 *
 * Several resources already use `->visible(...)` / `->disabled(...)`
 * closures for their own business logic — e.g. BoxResource shows
 * `parent_box_id` only when `box_type` is `IN_SITU`, and the
 * `repository_id` Select in DocumentResource is disabled for non-admin
 * roles. The gates MUST compose with these instead of clobbering them.
 *
 * - For visibility we use Filament's `hidden(...)` channel (separate
 *   from `visible(...)`). Filament evaluates `isHidden() OR !isVisible()`
 *   so adding a `hidden(true)` for forbidden roles always wins without
 *   touching the user's `visible(...)` closure.
 * - For disabled-state we read the component's existing `$isDisabled`
 *   via reflection (the property is declared on
 *   `Concerns\CanBeDisabled` in the Filament base trait) and wrap it
 *   with an `OR` against our gate. We then re-apply `dehydrated(true)`
 *   so the disabled input still round-trips its current value on save.
 *
 * Both closures re-evaluate on every render cycle, so a role change
 * takes effect on the next Livewire refresh — same as Filament's own
 * conditional rules.
 */
trait AppliesFieldPermissions
{
    /**
     * Wrap a Form component with the field-level permission gates.
     *
     * Returns non-Field components (Section, Fieldset, Tabs, ...)
     * unchanged — they have no `getName()` to key off and conceptually
     * group rather than carry a value.
     */
    protected static function gateField(FormComponent $component, string $resource): FormComponent
    {
        if (! $component instanceof FormField) {
            return $component;
        }

        $name = $component->getName();

        // --- Visibility: compose via hidden() (not visible()) so we
        // don't clobber any conditional visible() the caller has set.
        // `isHidden() || !isVisible()` is how Filament resolves the
        // final state, so adding `hidden(true)` is a strict tightening.
        $component->hidden(static fn (): bool => FieldPermissions::isHidden($resource, $name)
            || ! FieldPermissions::canRead($resource, $name));

        // --- Disabled: combine with the existing $isDisabled closure
        // so business rules (e.g. tenant-scoped Repository Select) AND
        // role-based denial both contribute. We read the existing value
        // via reflection because Filament does not expose a getter.
        $existingDisabled = self::readProtectedProperty($component, 'isDisabled');

        $component->disabled(static function () use ($component, $existingDisabled, $resource, $name): bool {
            $existing = false;
            if ($existingDisabled instanceof Closure) {
                $existing = (bool) $component->evaluate($existingDisabled);
            } elseif (is_bool($existingDisabled)) {
                $existing = $existingDisabled;
            }

            return $existing || ! FieldPermissions::canWrite($resource, $name);
        });

        // `disabled()` auto-installs `dehydrated(fn => !disabled)`, which
        // would drop the value from the save payload. Force it true so
        // the existing column value round-trips on save — preventing
        // accidental NULL-out of read-only fields. Same pattern the
        // codebase already uses on `repository_id` Selects.
        $component->dehydrated(true);

        return $component;
    }

    /**
     * Wrap a Table column with the field-level read gate. Returns the
     * column unchanged if it is not a Filament TableColumn (defensive).
     *
     * @param mixed $column a Filament Tables\Columns\Column subclass
     * @param string $resource resource key in config/field_permissions.php
     * @param string|null $field override the column name (use when
     *                           a relationship column like `series.code`
     *                           gates on the local FK `series_id`)
     */
    protected static function gateColumn(mixed $column, string $resource, ?string $field = null): mixed
    {
        if (! $column instanceof TableColumn) {
            return $column;
        }

        $columnName = $field ?? $column->getName();

        $column->visible(
            static fn (): bool => ! FieldPermissions::isHidden($resource, $columnName)
                && FieldPermissions::canRead($resource, $columnName),
        );

        return $column;
    }

    /**
     * Read a protected/private property from a target object via
     * reflection. Returns null if the property is undefined. Used to
     * compose with traits whose state has no public getter.
     */
    private static function readProtectedProperty(object $target, string $property): mixed
    {
        try {
            $ref = new \ReflectionObject($target);
            if (! $ref->hasProperty($property)) {
                return null;
            }
            $prop = $ref->getProperty($property);
            $prop->setAccessible(true);

            return $prop->getValue($target);
        } catch (\Throwable) {
            return null;
        }
    }
}
