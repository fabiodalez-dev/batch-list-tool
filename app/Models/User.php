<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Lab404\Impersonate\Models\Impersonate;
use Laravel\Fortify\TwoFactorAuthenticatable;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property bool $must_change_password
 * @property int $preferred_page_size
 * @property string|null $locale
 * @property string|null $timezone
 */
class User extends Authenticatable implements AuditableContract, FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use Auditable;

    use HasFactory;
    use HasRoles;
    use Impersonate;
    use Notifiable;
    use SoftDeletes;
    use TwoFactorAuthenticatable;

    protected $fillable = [
        'name', 'email', 'password', 'default_repository_id', 'is_active', 'must_change_password',
        'preferred_page_size', 'locale', 'timezone',
    ];

    protected $attributes = [
        'must_change_password' => false,
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $auditExclude = [
        'password', 'remember_token',
        'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at',
    ];

    /**
     * All audit events performed BY this user (i.e. `audits.user_id = $this->id`).
     *
     * Named `activityAudits` to avoid clashing with owen-it's built-in `audits()`
     * relation, which returns audits OF this record (where auditable = user).
     */
    public function activityAudits(): HasMany
    {
        return $this->hasMany(Audit::class, 'user_id')->latest();
    }

    public function defaultRepository(): BelongsTo
    {
        return $this->belongsTo(Repository::class, 'default_repository_id');
    }

    /**
     * The user's *active* repository (RFQ Wave 2 Task 10).
     * null = "All repositories" — the persisted mirror of the session-backed
     * App\Support\ActiveRepository selection.
     */
    public function activeRepository(): BelongsTo
    {
        return $this->belongsTo(Repository::class, 'active_repository_id');
    }

    public function repositories(): BelongsToMany
    {
        return $this->belongsToMany(Repository::class)
            ->withPivot('is_default', 'role')
            ->withTimestamps();
    }

    /**
     * Resolve the effective role for a given repository.
     *
     * Priority:
     *  1. super_admin always wins — the global role is returned unchanged.
     *  2. A non-null pivot `role` is returned as-is.
     *  3. Falls back to the user's first global Spatie role (e.g. admin/editor/viewer).
     */
    public function effectiveRoleFor(Repository $repository): ?string
    {
        if ($this->hasRole('super_admin')) {
            return 'super_admin';
        }

        $pivot = $this->repositories()
            ->where('repositories.id', $repository->getKey())
            ->first()
            ?->pivot;

        /** @var string|null $pivotRole */
        $pivotRole = $pivot?->getAttribute('role');

        // Defence-in-depth (review F5): the pivot `role` column is free-text
        // from the DB's point of view. This method is not yet wired into any
        // authorization decision, but to keep it from becoming a privilege-
        // escalation trap when it is, only trust a pivot value that is a real,
        // defined application role. An unknown / forged string (a row hand-
        // edited to a non-existent or mis-cased role) is ignored and we fall
        // back to the user's global role — never escalate on garbage.
        if ($pivotRole !== null && self::isKnownRoleName($pivotRole)) {
            return $pivotRole;
        }

        return $this->getRoleNames()->first();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && (
            $this->hasRole('super_admin')
            || $this->hasAnyRole(['admin', 'editor', 'viewer'])
        );
    }

    /**
     * Impersonation gate — only super_admin may impersonate.
     * Used by lab404/laravel-impersonate.
     */
    public function canImpersonate(): bool
    {
        return $this->hasRole('super_admin');
    }

    /**
     * Privilege-escalation guard: super_admin can NEVER be impersonated.
     * If we ever allowed it, an admin could "become" super_admin and bypass
     * the canImpersonate() check above. Other roles are fair game.
     */
    public function canBeImpersonated(): bool
    {
        return ! $this->hasRole('super_admin');
    }

    /**
     * True when $name is a real, defined application role (Spatie `roles`
     * table, `web` guard). Used to reject unknown / forged pivot role values.
     */
    protected static function isKnownRoleName(string $name): bool
    {
        return Role::query()
            ->where('name', $name)
            ->where('guard_name', 'web')
            ->exists();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'preferred_page_size' => 'integer',
        ];
    }

    /**
     * Clear `must_change_password` when a user changes their OWN password.
     *
     * The edge-case rule: the admin "reset password" action deliberately sets
     * `password` AND `must_change_password = true` for a DIFFERENT user. We
     * must NOT clear the flag there. The guard is:
     *   auth()->check() AND auth()->id() === this user's PK.
     *
     * Uses the same `static::saving(...)` pattern as Box::booted().
     */
    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            if (
                $user->must_change_password
                && $user->isDirty('password')
                && auth()->check()
                && (int) auth()->id() === (int) $user->getKey()
            ) {
                $user->must_change_password = false;
            }
        });
    }
}
