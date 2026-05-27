<?php

declare(strict_types=1);

namespace App\Models;

use App\Filament\Pages\ImportWizard;
use App\Models\Concerns\BelongsToRepository;
use Database\Factories\ImportProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * ImportProfile — reusable column-mapping presets for the Import Wizard.
 *
 * RFQ §3.1.3. Persists the column_map (and optional per-profile synonyms)
 * an operator hand-tuned in the wizard so future imports of spreadsheets
 * with the same layout pick up the mapping in one click instead of column
 * by column.
 *
 * Ownership / sharing semantics mirror {@see ReportTemplate}:
 *   - owner sees their own private + shared profiles always;
 *   - other users in the same repository see only the owner's `is_shared`
 *     profiles (RepositoryScope clips out cross-tenant rows);
 *   - super_admin / admin bypass the RepositoryScope and see everything.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $repository_id
 * @property string $name
 * @property string|null $description
 * @property string $import_type
 * @property array<string, string|null> $column_map
 * @property array<string, array<int, string>>|null $synonyms
 * @property bool $is_shared
 * @property Carbon|null $last_used_at
 * @property int $use_count
 */
class ImportProfile extends Model implements AuditableContract
{
    use Auditable;
    use BelongsToRepository;

    /** @use HasFactory<ImportProfileFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * Valid `import_type` values — these must match keys of
     * {@see ImportWizard::IMPORTERS}. Kept as constants
     * so the Resource form + tests never drift from the wizard.
     */
    public const TYPE_SERIES = 'series';

    public const TYPE_AUTHORITIES = 'authorities';

    public const TYPE_BATCHES = 'batches';

    public const TYPE_BOXES = 'boxes';

    public const TYPE_DOCUMENTS = 'documents';

    /** @var array<int, string> */
    public const TYPES = [
        self::TYPE_SERIES,
        self::TYPE_AUTHORITIES,
        self::TYPE_BATCHES,
        self::TYPE_BOXES,
        self::TYPE_DOCUMENTS,
    ];

    /**
     * `repository_id` is mass-assignable; the BelongsToRepository `creating`
     * hook validates that non-privileged users only stamp their own tenant.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'repository_id',
        'name',
        'description',
        'import_type',
        'column_map',
        'synonyms',
        'is_shared',
        'last_used_at',
        'use_count',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'column_map' => 'array',
        'synonyms' => 'array',
        'is_shared' => 'boolean',
        'last_used_at' => 'datetime',
        'use_count' => 'integer',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_shared' => false,
        'use_count' => 0,
    ];

    /* ------------------------------------------------------------------ */
    /* Relationships */
    /* ------------------------------------------------------------------ */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* ------------------------------------------------------------------ */
    /* Query scopes */
    /* ------------------------------------------------------------------ */

    /**
     * Restrict to profiles the given user is allowed to see:
     *   - owner sees their own (private + shared);
     *   - other users in the same repository see only `is_shared = true`
     *     profiles (the RepositoryScope already filters cross-tenant rows
     *     for non-admins, so this just ORs in the share-flag predicate);
     *   - admin / super_admin already bypass the RepositoryScope, so they
     *     see every shared profile regardless of tenant.
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user): void {
            $q->where('user_id', $user->getKey())
                ->orWhere('is_shared', true);
        });
    }

    /**
     * Narrow to a single importer entity (e.g. only profiles for `documents`).
     * Convenience scope used by the wizard's "starting profile" dropdown.
     */
    public function scopeOfType(Builder $query, string $importType): Builder
    {
        return $query->where('import_type', $importType);
    }

    /* ------------------------------------------------------------------ */
    /* Mutators */
    /* ------------------------------------------------------------------ */

    /**
     * Telemetry: bump `last_used_at` and `use_count` whenever the wizard
     * dispatches an import that started from this profile. The wizard
     * uses `last_used_at` to sort the "starting profile" dropdown so the
     * operator's most-recent mappings float to the top.
     *
     * Uses `forceFill` + `saveQuietly` to avoid producing an audit event
     * for what is effectively a counter bump — the Auditable trait would
     * otherwise generate noise on every import.
     */
    public function markUsed(): void
    {
        $this->forceFill([
            'last_used_at' => now(),
            'use_count' => ($this->use_count ?? 0) + 1,
        ])->saveQuietly();
    }

    /* ------------------------------------------------------------------ */
    /* Lifecycle hooks */
    /* ------------------------------------------------------------------ */

    protected static function booted(): void
    {
        static::creating(function (ImportProfile $profile): void {
            // Default `user_id` to the authenticated user if the caller
            // didn't stamp it explicitly. Keeps "save as profile" in the
            // wizard a one-line operation.
            if (empty($profile->user_id) && auth()->check()) {
                $profile->user_id = (int) auth()->id();
            }
        });
    }

    protected static function newFactory(): ImportProfileFactory
    {
        return ImportProfileFactory::new();
    }
}
