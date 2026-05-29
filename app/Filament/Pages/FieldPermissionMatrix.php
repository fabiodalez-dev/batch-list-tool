<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\FieldPermissionOverride;
use App\Support\FieldPermissions;
use App\Support\RoleLabels;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * RFQ §3.1.8 — administration screen for the field-level permission matrix.
 *
 * The submission promises an Administrator can "review and adjust" per-field
 * access. This page does both:
 *
 *   - REVIEW: it renders the effective per-resource × field × role
 *     read / write / hidden matrix.
 *   - ADJUST: an Administrator edits the matrix inline and saves; the change
 *     is persisted as a {@see FieldPermissionOverride} (audited) that takes
 *     precedence over the `config/field_permissions.php` baseline, with no
 *     deploy required. "Reset to config defaults" drops every override.
 *
 * The config file remains the version-controlled baseline; overrides are the
 * runtime, UI-editable layer on top of it. `super_admin` is always read+write
 * and never hidden (enforced in {@see FieldPermissions}), so its column is not
 * editable here.
 */
class FieldPermissionMatrix extends Page
{
    /**
     * All four operator roles, in privilege order, for display.
     *
     * @var array<int, string>
     */
    public const ROLES = ['super_admin', 'admin', 'editor', 'viewer'];

    /**
     * Roles whose access is editable here. `super_admin` is omitted: it is
     * hard-wired to full access in {@see FieldPermissions}.
     *
     * @var array<int, string>
     */
    public const EDITABLE_ROLES = ['admin', 'editor', 'viewer'];

    /**
     * Editable matrix state, bound to the Blade toggles via wire:model.
     * Shape: state[resource][field][role] = ['read'=>bool, 'write'=>bool, 'hidden'=>bool].
     *
     * @var array<string, array<string, array<string, array<string, bool>>>>
     */
    public array $state = [];

    protected string $view = 'filament.pages.field-permission-matrix';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 40;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'Field permissions';

    protected static ?string $title = 'Field-level permission matrix';

    protected static ?string $slug = 'field-permissions';

    /**
     * Administrators only — this controls the security posture of every
     * resource. Shield auto-discovers this Page for permission generation.
     */
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasAnyRole(['super_admin', 'admin']);
    }

    public static function shouldRegisterNavigation(): bool
    {
        if (auth()->guest()) {
            return true; // CLI / Shield discovery
        }

        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->state = $this->buildState();
    }

    /**
     * RFQ display label for a role slug (Administrator / ReadingRoom / General).
     */
    public function roleLabel(string $slug): string
    {
        return RoleLabels::for($slug);
    }

    /**
     * Persist the current toggle state as overrides (one row per
     * resource × field), then flush the resolver cache so the new matrix is
     * live immediately.
     */
    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        foreach ($this->state as $resource => $fields) {
            foreach ($fields as $field => $roles) {
                // super_admin is always allowed; store it so the persisted row
                // is self-describing even though FieldPermissions hard-codes it.
                $read = ['super_admin'];
                $write = ['super_admin'];
                $hidden = [];

                foreach (self::EDITABLE_ROLES as $role) {
                    $perm = is_array($roles[$role] ?? null) ? $roles[$role] : [];
                    if (! empty($perm['hidden'])) {
                        $hidden[] = $role; // hidden wins over read/write
                        continue;
                    }
                    if (! empty($perm['read']) || ! empty($perm['write'])) {
                        $read[] = $role; // write implies read
                    }
                    if (! empty($perm['write'])) {
                        $write[] = $role;
                    }
                }

                FieldPermissionOverride::updateOrCreate(
                    ['resource' => (string) $resource, 'field' => (string) $field],
                    [
                        'read' => array_values(array_unique($read)),
                        'write' => array_values(array_unique($write)),
                        'hidden_from' => array_values(array_unique($hidden)),
                    ],
                );
            }
        }

        FieldPermissions::flushCache();
        $this->state = $this->buildState();

        Notification::make()
            ->title('Field permissions saved')
            ->body('The matrix is live for every user on their next page load.')
            ->success()
            ->send();
    }

    /**
     * Drop every override and fall back to the config baseline.
     */
    public function resetToDefaults(): void
    {
        abort_unless(static::canAccess(), 403);

        // Mass delete does not fire model events, so flush the cache by hand.
        FieldPermissionOverride::query()->delete();
        FieldPermissions::flushCache();
        $this->state = $this->buildState();

        Notification::make()
            ->title('Reset to config defaults')
            ->body('All UI overrides were removed; the matrix now matches config/field_permissions.php.')
            ->success()
            ->send();
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save changes')
                ->icon('heroicon-o-check')
                ->color('primary')
                ->action(fn () => $this->save()),

            Action::make('resetToDefaults')
                ->label('Reset to config defaults')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Remove all UI overrides and revert to config/field_permissions.php?')
                ->action(fn () => $this->resetToDefaults()),
        ];
    }

    /**
     * Build the editable state from the config baseline merged with any
     * persisted overrides (override wins). Mirrors {@see FieldPermissions}
     * resolution so the toggles reflect the effective runtime decision.
     *
     * @return array<string, array<string, array<string, array<string, bool>>>>
     */
    protected function buildState(): array
    {
        /** @var array<string, array<string, mixed>> $config */
        $config = (array) config('field_permissions', []);

        $overrides = FieldPermissionOverride::query()->get()
            ->keyBy(fn (FieldPermissionOverride $o): string => $o->resource . '.' . $o->field);

        $state = [];
        foreach ($config as $resource => $fields) {
            if (! is_array($fields)) {
                continue;
            }

            $default = $this->effectiveBlock((string) $resource, '_default', $config, $overrides);

            foreach ($fields as $field => $cfg) {
                if (! is_array($cfg)) {
                    continue;
                }

                $block = $this->effectiveBlock((string) $resource, (string) $field, $config, $overrides);
                $hiddenFrom = $block['hidden_from'] ?? [];
                $readList = $block['read'] ?? $default['read'] ?? null;
                $writeList = $block['write'] ?? $default['write'] ?? null;

                foreach (self::EDITABLE_ROLES as $role) {
                    $hidden = in_array($role, $hiddenFrom, true);
                    $read = ! $hidden && ($readList === null || in_array($role, (array) $readList, true));
                    $write = $read && ($writeList === null || in_array($role, (array) $writeList, true));

                    $state[(string) $resource][(string) $field][$role] = [
                        'read' => $read,
                        'write' => $write,
                        'hidden' => $hidden,
                    ];
                }
            }
        }

        return $state;
    }

    /**
     * The effective config block for one (resource, field): the override if
     * one exists, otherwise the config baseline.
     *
     * @param array<string, array<string, mixed>> $config
     * @param Collection<string, FieldPermissionOverride> $overrides
     * @return array<string, array<int, string>>
     */
    private function effectiveBlock(string $resource, string $field, array $config, $overrides): array
    {
        $override = $overrides->get($resource . '.' . $field);
        if ($override instanceof FieldPermissionOverride) {
            return array_filter([
                'read' => $override->read,
                'write' => $override->write,
                'hidden_from' => $override->hidden_from,
            ], static fn ($v): bool => is_array($v));
        }

        $cfg = $config[$resource][$field] ?? [];

        return is_array($cfg) ? $cfg : [];
    }
}
