<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class BackupDestination extends Model implements AuditableContract
{
    use Auditable;
    use HasFactory;

    protected $fillable = [
        'name',
        'driver',
        'disk_key',
        'config',
        'is_active',
        'is_default',
        'sort_order',
    ];

    protected $casts = [
        'config' => 'encrypted:array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Exclude config from audit log so decrypted credentials are never written.
     *
     * @var list<string>
     */
    protected $auditExclude = ['config'];

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Return only active destinations, ordered by sort_order ascending.
     *
     * @param Builder<self> $q
     * @return Builder<self>
     */
    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true)->orderBy('sort_order');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Disk name used by Laravel's Storage facade / backup package.
     */
    public function diskName(): string
    {
        return $this->disk_key;
    }
}
