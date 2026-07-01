<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRepository;
use Database\Factories\ReportTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * ReportTemplate (RFQ §3.2.2 — "Save report templates").
 *
 * Persists a saved filter+column+sort snapshot against one of the canned
 * report pages, so an operator can bookmark a recurring view ("open
 * critical flags this month", "documents from notary R12 in batch 41")
 * and reopen it with one click without rebuilding the filters.
 *
 * Ownership: every template has an owner (`user_id`). A template is
 * accessible to:
 *   - its owner, always;
 *   - any user in the same repository when `is_shared = true`;
 *   - super_admin / admin (bypass via the BelongsToRepository scope).
 *
 * The {@see scopeAccessibleBy()} scope codifies that policy so callers
 * can simply `ReportTemplate::query()->accessibleBy($user)->get()`.
 */
class ReportTemplate extends Model implements AuditableContract
{
    use Auditable;
    use BelongsToRepository;

    /** @use HasFactory<ReportTemplateFactory> */
    use HasFactory;

    use SoftDeletes;

    /** Valid `source` values — must match the REPORT_SOURCE const on each report page. */
    public const SOURCE_DOCUMENTS = 'documents';

    public const SOURCE_DOCUMENTS_BY_BATCH = 'documents_by_batch';

    public const SOURCE_DOCUMENTS_BY_CREATOR = 'documents_by_creator';

    public const SOURCE_DOCUMENTS_BY_SERIES = 'documents_by_series';

    public const SOURCE_PENDING_DISINFESTATION = 'pending_disinfestation';

    public const SOURCE_BOX_MOVEMENTS = 'box_movements';

    public const SOURCE_FLAGS_BY_TYPE = 'flags_by_type';

    // NAF Queries reports (Q1/Q3/Q4).
    public const SOURCE_DISINFESTATION_CYCLE = 'disinfestation_cycle';

    public const SOURCE_RAS_NRA_RECONCILIATION = 'ras_nra_reconciliation';

    public const SOURCE_STOCK_TAKE = 'stock_take';

    /**
     * Whitelist of accepted source values — exposed so forms / validation
     * never drift from the actual report pages.
     *
     * @var array<int, string>
     */
    public const SOURCES = [
        self::SOURCE_DOCUMENTS,
        self::SOURCE_DOCUMENTS_BY_BATCH,
        self::SOURCE_DOCUMENTS_BY_CREATOR,
        self::SOURCE_DOCUMENTS_BY_SERIES,
        self::SOURCE_PENDING_DISINFESTATION,
        self::SOURCE_BOX_MOVEMENTS,
        self::SOURCE_FLAGS_BY_TYPE,
        self::SOURCE_DISINFESTATION_CYCLE,
        self::SOURCE_RAS_NRA_RECONCILIATION,
        self::SOURCE_STOCK_TAKE,
    ];

    /**
     * `repository_id` is mass-assignable; the BelongsToRepository `creating`
     * hook validates that non-privileged users only stamp their own tenant.
     */
    protected $fillable = [
        'user_id',
        'repository_id',
        'name',
        'description',
        'source',
        'filters',
        'columns',
        'sort',
        'is_shared',
    ];

    protected $casts = [
        'filters' => 'array',
        'columns' => 'array',
        'sort' => 'array',
        'is_shared' => 'boolean',
    ];

    /**
     * Default to the empty-filter state if the caller forgets — the form
     * legitimately persists "no filters at all" (i.e. the full report).
     */
    protected $attributes = [
        'is_shared' => false,
    ];

    /* ---------------------------------------------------------------------
     |  Relationships
     |---------------------------------------------------------------------*/

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* ---------------------------------------------------------------------
     |  Query scopes
     |---------------------------------------------------------------------*/

    /**
     * Restrict to templates the given user is allowed to see:
     *   - owner can always read their own templates;
     *   - any user in the same repository can read `is_shared = true`
     *     templates from anyone (the RepositoryScope already filters
     *     cross-tenant rows for non-admins, so this just needs to OR
     *     in the shared-flag predicate within the visible set);
     *   - admin / super_admin already bypass the RepositoryScope, so
     *     they see every shared template regardless of tenant.
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $q) use ($user): void {
            $q->where('user_id', $user->getKey())
                ->orWhere('is_shared', true);
        });
    }

    /**
     * Lifecycle hooks:
     *   - default `user_id` to the authenticated user if the caller didn't
     *     stamp it explicitly. Keeps the field "magical enough" for the
     *     header-action shortcut on each Report page while still allowing
     *     tests and CLI tasks to specify an explicit owner.
     */
    protected static function booted(): void
    {
        static::creating(function (ReportTemplate $template): void {
            if (empty($template->user_id) && auth()->check()) {
                $template->user_id = auth()->id();
            }
        });
    }

    protected static function newFactory(): ReportTemplateFactory
    {
        return ReportTemplateFactory::new();
    }
}
