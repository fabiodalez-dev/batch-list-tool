<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\FieldPermissions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * RFQ §3.1.8 — a persisted override of one (resource, field) block in the
 * field-permission matrix, editable by an Administrator from the UI.
 *
 * Global (not tenant-scoped): field-level permissions are app-wide policy,
 * identical across repositories. Audited via owen-it/laravel-auditing so the
 * audit trail records who changed which field's access and when.
 *
 * Saving or deleting a row flushes {@see FieldPermissions} cache so the new
 * matrix takes effect on the next request without a deploy.
 */
class FieldPermissionOverride extends Model implements AuditableContract
{
    use Auditable;
    use HasFactory;

    protected $fillable = ['resource', 'field', 'read', 'write', 'hidden_from'];

    protected $casts = [
        'read' => 'array',
        'write' => 'array',
        'hidden_from' => 'array',
    ];

    protected static function booted(): void
    {
        static::saved(static fn () => FieldPermissions::flushCache());
        static::deleted(static fn () => FieldPermissions::flushCache());
    }
}
