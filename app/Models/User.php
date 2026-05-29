<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Lab404\Impersonate\Models\Impersonate;
use Laravel\Fortify\TwoFactorAuthenticatable;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\Permission\Traits\HasRoles;

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

    public function defaultRepository(): BelongsTo
    {
        return $this->belongsTo(Repository::class, 'default_repository_id');
    }

    public function repositories(): BelongsToMany
    {
        return $this->belongsToMany(Repository::class)
            ->withPivot('is_default')
            ->withTimestamps();
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

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
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
