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

    protected $fillable = [
        'name', 'email', 'password', 'default_repository_id', 'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $auditExclude = [
        'password', 'remember_token',
        'two_factor_secret', 'two_factor_recovery_codes',
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
        ];
    }
}
