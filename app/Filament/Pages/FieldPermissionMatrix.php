<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Support\FieldPermissions;
use App\Support\RoleLabels;
use Filament\Pages\Page;

/**
 * RFQ §3.1.8 — read-only administration view of the field-level permission
 * matrix.
 *
 * The matrix itself lives in `config/field_permissions.php` (a config file,
 * not a DB table — see that file's header for the rationale). This page gives
 * Administrators and auditors a rendered, role-labelled view of the EFFECTIVE
 * permissions without having to read PHP: for every resource × field × role
 * it resolves read / write / hidden exactly the way {@see FieldPermissions}
 * does at runtime (applying the `_default` fallback and the hard-coded
 * super_admin override).
 *
 * Deliberately read-only: the matrix is small, changes rarely, and is
 * version-controlled + diff-reviewed in git. A DB-backed editor would add
 * cache-invalidation and audit surface for no real operational gain. If NAF
 * later wants in-app editing, that becomes a separate scoped change.
 */
class FieldPermissionMatrix extends Page
{
    /**
     * The four operator roles, in privilege order, with their RFQ display
     * names resolved via {@see RoleLabels}.
     *
     * @var array<int, string>
     */
    public const ROLES = ['super_admin', 'admin', 'editor', 'viewer'];

    protected string $view = 'filament.pages.field-permission-matrix';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 2;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = 'Field permissions';

    protected static ?string $title = 'Field-level permission matrix';

    protected static ?string $slug = 'field-permissions';

    /**
     * Administrators only — this exposes the full security posture of every
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

    /**
     * RFQ display label for a role slug (Administrator / ReadingRoom / General).
     */
    public function roleLabel(string $slug): string
    {
        return RoleLabels::for($slug);
    }

    /**
     * Build the rendered matrix: one entry per configured resource, each
     * carrying its fields and the effective per-role status.
     *
     * @return array<string, array{
     *     fields: array<string, array<string, array{read:bool, write:bool, hidden:bool}>>
     * }>
     */
    public function matrix(): array
    {
        /** @var array<string, array<string, mixed>> $config */
        $config = (array) config('field_permissions', []);

        $out = [];
        foreach ($config as $resource => $fields) {
            if (! is_array($fields)) {
                continue;
            }

            $default = is_array($fields['_default'] ?? null) ? $fields['_default'] : [];

            $rows = [];
            foreach ($fields as $field => $cfg) {
                if (! is_array($cfg)) {
                    continue;
                }
                $rows[$field] = $this->resolveField($cfg, $default);
            }

            $out[$resource] = ['fields' => $rows];
        }

        return $out;
    }

    /**
     * Resolve the effective read/write/hidden status of one field for every
     * role, mirroring {@see FieldPermissions} resolution order:
     *   hidden_from wins over read; missing lists fall back to `_default`;
     *   a still-missing list means implicit-allow; super_admin is always RW
     *   and never hidden.
     *
     * @param array<string, mixed> $cfg the field's own config block
     * @param array<string, mixed> $default the resource `_default` block
     * @return array<string, array{read:bool, write:bool, hidden:bool}>
     */
    private function resolveField(array $cfg, array $default): array
    {
        $hiddenFrom = is_array($cfg['hidden_from'] ?? null) ? $cfg['hidden_from'] : [];
        $readList = $cfg['read'] ?? $default['read'] ?? null;
        $writeList = $cfg['write'] ?? $default['write'] ?? null;

        $statuses = [];
        foreach (self::ROLES as $role) {
            if ($role === FieldPermissions::SUPER_ADMIN_ROLE) {
                $statuses[$role] = ['read' => true, 'write' => true, 'hidden' => false];

                continue;
            }

            $hidden = in_array($role, $hiddenFrom, true);
            // implicit-allow when no list is configured anywhere
            $canRead = ! $hidden && ($readList === null || in_array($role, (array) $readList, true));
            $canWrite = $canRead && ($writeList === null || in_array($role, (array) $writeList, true));

            $statuses[$role] = ['read' => $canRead, 'write' => $canWrite, 'hidden' => $hidden];
        }

        return $statuses;
    }
}
