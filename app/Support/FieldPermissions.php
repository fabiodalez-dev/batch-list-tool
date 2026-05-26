<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

/**
 * Field-level permission resolver (RFQ §3.1.8).
 *
 * Backs the per-field, per-role read/write/hidden matrix declared in
 * {@see config/field_permissions.php}. The class is intentionally stateless
 * — all decisions are pure functions of (resource, field, user). This makes
 * it cheap to call from Filament Form closures, which are evaluated per
 * Livewire render cycle.
 *
 * ## Semantics
 *
 * - `super_admin` is ALWAYS allowed (read + write) and NEVER hidden,
 *   regardless of config — this is defence-in-depth so that an empty,
 *   broken, or accidentally-tightened config can never lock out the
 *   only operator capable of fixing it.
 * - When `$user` is null (no authenticated user, e.g. CLI seeders or
 *   queued jobs), all checks default to ALLOW — production gates already
 *   live above this layer (Filament Shield resource policies). This
 *   layer is a UX/UI restriction, not an authentication boundary.
 * - When the resource key or field is absent from the config, the
 *   `_default` block for the resource is consulted; if `_default` is
 *   also missing, the check defaults to ALLOW (implicit-allow for
 *   forward-compat — adding a new `$fillable` column does not silently
 *   lock out users).
 * - `hidden_from` takes precedence over `read`: if a role is listed in
 *   `hidden_from`, the field is removed from the form and the column
 *   from the table for that role.
 *
 * ## Why static
 *
 * Filament Form schemas evaluate gate closures lazily at render time —
 * the natural call-site is `->visible(fn () => FieldPermissions::canRead(...))`
 * which expects a free function, not an injected service. Going through
 * `app(FieldPermissions::class)` works but adds noise; the static facade
 * matches the call ergonomics of Filament's own `auth()->user()` pattern.
 */
final class FieldPermissions
{
    /**
     * Role name reserved for the super-admin escape hatch. Hard-coded
     * here so the defence-in-depth check never depends on the config
     * file the operator is trying to debug.
     */
    public const SUPER_ADMIN_ROLE = 'super_admin';

    /**
     * Can this role READ the given field?
     *
     * @param string $resource e.g. 'document', 'authority' — must match the top-level key in config/field_permissions.php
     * @param string $field column name as declared in the Model's $fillable
     * @param User|null $user defaults to auth()->user() when null
     */
    public static function canRead(string $resource, string $field, ?User $user = null): bool
    {
        $user ??= self::resolveUser();

        // No user → no UI context to gate. Allow; resource-level Shield
        // policies above this layer will have already denied unauthorised
        // access if applicable.
        if ($user === null) {
            return true;
        }

        // Defence-in-depth: super_admin is always allowed.
        if (self::isSuperAdmin($user)) {
            return true;
        }

        // hidden_from is a stronger control than read — it implies the user
        // should not even know the field exists, which subsumes read denial.
        if (self::roleIn($user, self::fieldConfig($resource, $field)['hidden_from'] ?? [])) {
            return false;
        }

        $allowed = self::fieldConfig($resource, $field)['read']
            ?? self::defaultConfig($resource)['read']
            ?? null;

        // No explicit config and no default → implicit-allow.
        if ($allowed === null) {
            return true;
        }

        return self::roleIn($user, $allowed);
    }

    /**
     * Can this role WRITE the given field?
     *
     * Writing implies reading: a role that cannot read cannot write (the
     * form input wouldn't be visible). We still evaluate the `write` list
     * independently because some operators may legitimately model fields
     * as "read-only for editor, read+write for admin".
     */
    public static function canWrite(string $resource, string $field, ?User $user = null): bool
    {
        $user ??= self::resolveUser();

        if ($user === null) {
            return true;
        }

        if (self::isSuperAdmin($user)) {
            return true;
        }

        if (! self::canRead($resource, $field, $user)) {
            return false;
        }

        $allowed = self::fieldConfig($resource, $field)['write']
            ?? self::defaultConfig($resource)['write']
            ?? null;

        if ($allowed === null) {
            return true;
        }

        return self::roleIn($user, $allowed);
    }

    /**
     * Should the form input / table column be HIDDEN (removed from DOM)
     * for this role?
     *
     * This is stronger than "read-only" — the user cannot see the value
     * at all. Use it for sensitive fields (audit trails, schemaless
     * metadata, internal flags).
     */
    public static function isHidden(string $resource, string $field, ?User $user = null): bool
    {
        $user ??= self::resolveUser();

        if ($user === null) {
            return false;
        }

        // Defence-in-depth: super_admin is never hidden.
        if (self::isSuperAdmin($user)) {
            return false;
        }

        return self::roleIn($user, self::fieldConfig($resource, $field)['hidden_from'] ?? []);
    }

    /* ---------------------------------------------------------------- *
     | Internal helpers                                                  |
     * ---------------------------------------------------------------- */

    /**
     * Resolve the current user without crashing if the auth manager is
     * not bound (e.g. in unit tests that exercise this class directly).
     */
    private static function resolveUser(): ?User
    {
        if (! function_exists('auth')) {
            return null;
        }

        try {
            $user = auth()->user();
        } catch (\Throwable) {
            return null;
        }

        return $user instanceof User ? $user : null;
    }

    /**
     * `true` iff `$user` has the super_admin role. Wrapped in try/catch
     * because `hasRole()` will throw if the spatie permission cache is
     * not yet warmed (e.g. during early boot) — the safe answer in that
     * case is "not super_admin", same as any other unprivileged check.
     */
    private static function isSuperAdmin(User $user): bool
    {
        try {
            return (bool) $user->hasRole(self::SUPER_ADMIN_ROLE);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * `true` iff any of `$user`'s assigned roles appears in `$allowed`.
     *
     * @param array<int,string> $allowed
     */
    private static function roleIn(User $user, array $allowed): bool
    {
        if ($allowed === []) {
            return false;
        }

        try {
            $names = $user->getRoleNames();
        } catch (\Throwable) {
            return false;
        }

        foreach ($names as $name) {
            if (in_array($name, $allowed, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,array<int,string>>
     */
    private static function fieldConfig(string $resource, string $field): array
    {
        $cfg = config('field_permissions.' . $resource . '.' . $field);

        return is_array($cfg) ? $cfg : [];
    }

    /**
     * @return array<string,array<int,string>>
     */
    private static function defaultConfig(string $resource): array
    {
        $cfg = config('field_permissions.' . $resource . '._default');

        return is_array($cfg) ? $cfg : [];
    }
}
