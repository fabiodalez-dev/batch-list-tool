<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\FieldPermissionOverride;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

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
 * - When `$user` is null (no authenticated user), the behaviour depends
 *   on the runtime context to balance safety against developer ergonomics:
 *     * In CONSOLE context (CLI seeders, queue workers, scheduled jobs,
 *       artisan tinker) all checks default to ALLOW so trusted local
 *       maintenance code is never blocked.
 *     * In HTTP context (web request without an authenticated session)
 *       all checks default to DENY (fail-closed) — an upstream Filament
 *       Shield gate normally short-circuits this path, but if it ever
 *       fails open the field-permission layer becomes the safety net
 *       instead of leaking the form. OWASP A01 hardening (2026-05-28).
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
    public const string SUPER_ADMIN_ROLE = 'super_admin';

    /**
     * Cache key for the persisted {@see FieldPermissionOverride} matrix.
     * In production the cache survives across requests and is flushed by the
     * model's saved/deleted events; in tests the array cache store is reset
     * with the application between cases, so each test reads fresh.
     */
    public const string OVERRIDE_CACHE_KEY = 'field_permission_overrides';

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

        if (! $user instanceof User) {
            // Console: trusted local code (seeders, queue, tinker) — allow.
            // HTTP: no auth context, fail closed so the form layer never
            // leaks fields when an upstream policy gate misfires.
            return self::isConsole();
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

        if (! $user instanceof User) {
            return self::isConsole();
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

        if (! $user instanceof User) {
            // Console: never hide (CLI / queue / tinker trusted).
            // HTTP without auth context: hide (fail-safe — never expose
            // a field whose policy decision we cannot evaluate).
            return ! self::isConsole();
        }

        // Defence-in-depth: super_admin is never hidden.
        if (self::isSuperAdmin($user)) {
            return false;
        }

        return self::roleIn($user, self::fieldConfig($resource, $field)['hidden_from'] ?? []);
    }

    /**
     * Forget the cached override matrix. Called by
     * {@see FieldPermissionOverride}'s model events on every write.
     */
    public static function flushCache(): void
    {
        Cache::forget(self::OVERRIDE_CACHE_KEY);
    }

    /* ---------------------------------------------------------------- *
     | Internal helpers                                                  |
     * ---------------------------------------------------------------- */

    /**
     * `true` when the current process is a CLI invocation (artisan, queue
     * worker, scheduled task, tinker). Used to distinguish trusted local
     * maintenance code from unauthenticated HTTP requests for the
     * fail-closed-in-HTTP semantics described in the class docblock.
     */
    private static function isConsole(): bool
    {
        try {
            return function_exists('app') && app()->runningInConsole();
        } catch (\Throwable) {
            // If the container is not bound yet (very early boot, unit tests
            // without a Laravel kernel) treat as console — there is no HTTP
            // request to leak into.
            return true;
        }
    }

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
            return $user->hasRole(self::SUPER_ADMIN_ROLE);
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
     * Resolve the config block for one (resource, field), giving a persisted
     * {@see FieldPermissionOverride} precedence over the config baseline.
     *
     * @return array<string,array<int,string>>
     */
    private static function fieldConfig(string $resource, string $field): array
    {
        $override = self::overrides()[$resource . '.' . $field] ?? null;
        if (is_array($override)) {
            return $override;
        }

        $cfg = config('field_permissions.' . $resource . '.' . $field);

        return is_array($cfg) ? $cfg : [];
    }

    /**
     * @return array<string,array<int,string>>
     */
    private static function defaultConfig(string $resource): array
    {
        return self::fieldConfig($resource, '_default');
    }

    /**
     * Persisted overrides keyed by "resource.field". Cached for the request
     * lifetime (and across requests in production), flushed by
     * {@see FieldPermissionOverride}'s saved/deleted events.
     *
     * Only non-null lists survive so a per-key `?? config` fallback still
     * works for a partially-specified override.
     *
     * @return array<string, array<string, array<int, string>>>
     */
    private static function overrides(): array
    {
        return Cache::rememberForever(self::OVERRIDE_CACHE_KEY, static function (): array {
            // Guard: during early boot / before the migration has run there is
            // no table to query. Returning [] (cached) is correct — there are
            // no overrides yet, and flushCache() runs on the first write.
            try {
                if (! Schema::hasTable('field_permission_overrides')) {
                    return [];
                }
            } catch (\Throwable) {
                return [];
            }

            return FieldPermissionOverride::query()->get()
                ->mapWithKeys(static function (FieldPermissionOverride $o): array {
                    $block = array_filter([
                        'read' => $o->read,
                        'write' => $o->write,
                        'hidden_from' => $o->hidden_from,
                    ], is_array(...));

                    return [$o->resource . '.' . $o->field => $block];
                })
                ->all();
        });
    }
}
