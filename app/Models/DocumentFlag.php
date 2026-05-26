<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * DocumentFlag — structured replacement for the legacy spreadsheet
 * colour-coding (RFQ §3.1.12).
 *
 * Each row attaches an *issue flag* to a Document with a category (`type`),
 * a severity, a workflow status, and full audit trail (who flagged, who
 * resolved, when, with what notes). The `context` JSON column carries any
 * structured payload (e.g. `{"duplicate_of": 42}`) so consumers can render
 * deep links / drill-downs without overloading the free-text description.
 *
 * Tenancy: `repository_id` is mirrored from the parent Document by a
 * `creating` hook (see {@see self::booted()}); the BelongsToRepository
 * trait then both global-scopes reads and validates cross-tenant writes.
 */
class DocumentFlag extends Model implements AuditableContract
{
    use Auditable;
    use BelongsToRepository;
    use HasFactory;

    /**
     * Workflow vocabulary — exported so callers (forms, filters, tests) can
     * iterate without hard-coding strings.
     */
    public const TYPES = [
        'needs_review',
        'missing_data',
        'duplicate_suspect',
        'damaged',
        'restoration_needed',
        'wrongly_catalogued',
        'authority_mismatch',
        'barcode_issue',
        'disinfestation_overdue',
        'other',
    ];

    public const SEVERITIES = ['info', 'warning', 'critical'];

    public const STATUSES = ['open', 'acknowledged', 'resolved', 'dismissed'];

    /** Statuses considered "actionable" — surfaced on the alerts dashboard. */
    public const OPEN_STATUSES = ['open', 'acknowledged'];

    /** Statuses considered "closed" — archived, no further action. */
    public const CLOSED_STATUSES = ['resolved', 'dismissed'];

    protected $fillable = [
        'document_id',
        'repository_id',
        'type',
        'severity',
        'status',
        'title',
        'description',
        'context',
        'flagged_by_user_id',
        'flagged_at',
        'resolved_by_user_id',
        'resolved_at',
        'resolution_notes',
    ];

    protected $casts = [
        'context' => 'array',
        'flagged_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected $attributes = [
        'severity' => 'warning',
        'status' => 'open',
    ];

    /**
     * Mutator on `document_id` — whenever a caller sets the parent document
     * (via mass assignment, ->fill(), or ->document_id =), we mirror the
     * parent's `repository_id` onto this row. This is the single source of
     * truth for tenancy and runs BEFORE any `creating` event fires, so by
     * the time BelongsToRepository's `creating` hook runs to validate the
     * tenant the value is already correct.
     *
     * We bypass the global RepositoryScope when looking up the parent so a
     * super_admin / admin creating a flag for a document outside their
     * default tenant doesn't trip on the scope (the cross-tenant check
     * remains the trait's responsibility — it bypasses for admins).
     */
    public function setDocumentIdAttribute(int|string|null $value): void
    {
        $this->attributes['document_id'] = $value;

        // Only mirror if the caller didn't explicitly set a repository_id —
        // otherwise we'd overwrite an admin's intentional cross-tenant value
        // and silently fight with their input.
        if (! array_key_exists('repository_id', $this->attributes) || empty($this->attributes['repository_id'])) {
            if (! empty($value)) {
                $parent = Document::withoutGlobalScopes()
                    ->whereKey($value)
                    ->first();

                if ($parent !== null) {
                    $this->attributes['repository_id'] = $parent->repository_id;
                }
            }
        }
    }

    /* ---------------------------------------------------------------------
     |  Relationships
     |---------------------------------------------------------------------*/

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function flaggedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'flagged_by_user_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    /* ---------------------------------------------------------------------
     |  Query scopes
     |---------------------------------------------------------------------*/

    /** Open + acknowledged — flags that still need someone's eyes on them. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    /** Resolved + dismissed — flags that are archived. */
    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereIn('status', self::CLOSED_STATUSES);
    }

    /**
     * @param string|array<int, string> $type
     */
    public function scopeOfType(Builder $query, string|array $type): Builder
    {
        return $query->whereIn('type', (array) $type);
    }

    /**
     * @param string|array<int, string> $severity
     */
    public function scopeOfSeverity(Builder $query, string|array $severity): Builder
    {
        return $query->whereIn('severity', (array) $severity);
    }

    /* ---------------------------------------------------------------------
     |  Workflow helpers
     |---------------------------------------------------------------------*/

    /**
     * Move the flag to "resolved" — the issue was real and has been handled.
     * Idempotent: calling it on an already-resolved flag is a no-op.
     */
    public function markResolved(?User $user = null, ?string $notes = null): void
    {
        if ($this->status === 'resolved') {
            return;
        }

        $this->forceFill([
            'status' => 'resolved',
            'resolved_by_user_id' => $user?->getKey() ?? auth()->id(),
            'resolved_at' => now(),
            'resolution_notes' => $notes ?? $this->resolution_notes,
        ])->save();
    }

    /**
     * Move the flag to "dismissed" — false positive or not actionable.
     * Idempotent: calling it on an already-dismissed flag is a no-op.
     */
    public function markDismissed(?User $user = null, ?string $notes = null): void
    {
        if ($this->status === 'dismissed') {
            return;
        }

        $this->forceFill([
            'status' => 'dismissed',
            'resolved_by_user_id' => $user?->getKey() ?? auth()->id(),
            'resolved_at' => now(),
            'resolution_notes' => $notes ?? $this->resolution_notes,
        ])->save();
    }

    /**
     * Move the flag to "acknowledged" — someone has seen it and is on it.
     * Does NOT set `resolved_by`/`resolved_at`; the flag is still open.
     */
    public function markAcknowledged(?User $user = null): void
    {
        if ($this->status === 'acknowledged') {
            return;
        }

        // Only meaningful from "open" — already-closed flags should not slide
        // backwards into the open queue.
        if (in_array($this->status, self::CLOSED_STATUSES, true)) {
            return;
        }

        $this->forceFill([
            'status' => 'acknowledged',
            // Stamp the acknowledger on flagged_by? No — that would erase the
            // original reporter. We just leave the flag visible on the open
            // queue with a different status; the audit log captures the who.
        ])->save();
    }

    /** True if the flag is in `open` or `acknowledged` state. */
    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** True if the flag is in `resolved` or `dismissed` state. */
    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    /**
     * Lifecycle hooks: default `flagged_at` to now() and `flagged_by_user_id`
     * to the authenticated user when not provided. Both columns also have
     * sensible DB-side defaults (`useCurrent()` on flagged_at) — these are
     * here so the values are already populated when validation / auditing
     * hooks read them, and so tests can rely on `$flag->flagged_at` being
     * non-null immediately after `save()`.
     */
    protected static function booted(): void
    {
        static::creating(function (DocumentFlag $flag): void {
            if (empty($flag->flagged_at)) {
                $flag->flagged_at = now();
            }

            if (empty($flag->flagged_by_user_id) && auth()->check()) {
                $flag->flagged_by_user_id = auth()->id();
            }
        });
    }
}
