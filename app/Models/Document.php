<?php

namespace App\Models;

use App\Models\Builders\DocumentBuilder;
use App\Models\Concerns\BelongsToRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Laravel\Scout\Searchable;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\SchemalessAttributes\Casts\SchemalessAttributes;
use Spatie\Tags\HasTags;

class Document extends Model implements AuditableContract, HasMedia, Sortable
{
    use Auditable;
    use BelongsToRepository;  // RFQ §3.5.1 — multi-tenant scope
    use HasFactory;
    use HasTags;
    use InteractsWithMedia;
    use Searchable;
    use SoftDeletes;
    use SortableTrait;

    public array $sortable = [
        'order_column_name' => 'sort_order',
        'sort_when_creating' => true,
    ];

    /**
     * `repository_id` is mass-assignable so Filament admins (who legitimately
     * pick a target tenant from the Repository Select) can write it through
     * `create()` — but the BelongsToRepository `creating` hook is the security
     * gate: it validates the chosen `repository_id` against the user's pivot
     * and throws \DomainException for any non-privileged write that targets a
     * foreign tenant. Defence-in-depth here is the hook, NOT $guarded.
     *
     * @see BelongsToRepository
     */
    protected $fillable = [
        'sort_order',
        // Normalised columns
        'identifier', 'document_type', 'series_id', 'accession_id',
        'current_box_id', 'batch_id', 'repository_id', 'volume_label',
        'dates_start', 'dates_end', 'dates_year_start', 'dates_year_end',
        'disinfestation_date', 'extra', 'notes',
        // Legacy POC columns (parity with raw-PHP schema)
        'ras_batch_1', 'ras_box_1', 'ras_batch_2', 'ras_box_2',
        'in_situ_box_1', 'in_situ_box_2', 'in_situ_box_3',
        'ras_1_box_destroyed', 'ras_2_box_destroyed',
        'in_situ_box_1_destroyed', 'in_situ_box_2_destroyed', 'in_situ_box_3_destroyed',
        'barcode_in', 'barcode_ras_1', 'status_1', 'barcode_ras_2', 'status_2',
        'barcode_ras_3', 'status_3', 'barcode_ras_4', 'status_4',
        'barcode_in_2', 'barcode_ras_2_alt', 'status_1_alt',
        'barcode_ras_2_alt2', 'status_2_alt',
        'seal_number', 'disinfestation_date_1', 'disinfestation_date_2', 'disinfestation_date_3',
        'catalogue_identifier', 'nra_location', 'museum_location', 'practice',
        'dates', 'deeds', 'current_box_type', 'colour_code', 'digitised', 'torre',
        'accession_code_legacy', 'object_reference_number', 'tracking', 'museum_reference',
        'custom_fields', 'metadata',
    ];

    protected $casts = [
        'dates_start' => 'date',
        'dates_end' => 'date',
        'disinfestation_date' => 'date',
        'disinfestation_date_1' => 'date',
        'disinfestation_date_2' => 'date',
        'disinfestation_date_3' => 'date',
        'torre' => 'boolean',
        'extra' => SchemalessAttributes::class,
        'custom_fields' => 'array',
        'metadata' => 'array',
    ];

    /**
     * When true, the DocumentBuilder bulk-update guard is suspended so a
     * caller can intentionally run a query-level update that touches the
     * `identifier` column (e.g., a one-off back-fill migration where the
     * audit row is written manually). Always flipped back to false in the
     * `finally` block of withoutAuditGuards().
     */
    private static bool $bypassAuditGuard = false;

    /**
     * Sort within the current_box. Documents in box A and documents in box B
     * each have their own 1..N sequence.
     */
    public function buildSortQuery(): Builder
    {
        return static::query()->where('current_box_id', $this->current_box_id);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    public function accession(): BelongsTo
    {
        return $this->belongsTo(Accession::class);
    }

    public function currentBox(): BelongsTo
    {
        return $this->belongsTo(Box::class, 'current_box_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    public function authorities(): BelongsToMany
    {
        return $this->belongsToMany(Authority::class, 'document_authority')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function volumes(): HasMany
    {
        return $this->hasMany(Volume::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(BoxMovement::class)->latest('movement_date');
    }

    /**
     * Chronological identifier-change log for this document (newest first).
     */
    public function identifierHistory(): HasMany
    {
        return $this->hasMany(DocumentIdentifierHistory::class)
            ->orderByDesc('changed_at');
    }

    /**
     * Distinct list of previous_identifier values from the history, useful for
     * surfacing "also known as" labels and feeding the global search index.
     */
    public function previousIdentifiers(): Collection
    {
        return $this->identifierHistory()
            ->pluck('previous_identifier')
            ->filter(fn ($v) => $v !== null && $v !== '')
            ->unique()
            ->values();
    }

    /**
     * F-011 alignment: this MUST mirror the attributes exposed in
     * DocumentResource::getGloballySearchableAttributes() so that swapping
     * Scout drivers (database / Meilisearch / Algolia) does not change
     * which fields the user sees in global search.
     */
    public function toSearchableArray(): array
    {
        return [
            'identifier' => $this->identifier,
            'catalogue_identifier' => $this->catalogue_identifier,
            'document_type' => $this->document_type,
            'practice' => $this->practice,
            'volume_label' => $this->volume_label,
            'dates' => $this->dates,
            'notes' => $this->notes,
            'barcode_in' => $this->barcode_in,
            'series_code' => $this->series?->code,
            'series_title' => $this->series?->title,
            'authorities_surnames' => $this->authorities()->pluck('surname')->implode(' '),
            'authorities_idents' => $this->authorities()->pluck('identifier')->implode(' '),
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')
            ->acceptsMimeTypes([
                'application/pdf',
                'image/jpeg', 'image/png', 'image/tiff',
            ]);
    }

    /**
     * Wire Eloquent up to our custom DocumentBuilder so that bulk
     * `Document::query()->update([...])` calls go through the audit guard.
     */
    public function newEloquentBuilder($query): DocumentBuilder
    {
        return new DocumentBuilder($query);
    }

    /**
     * Run a callback with the DocumentBuilder bulk-update guard temporarily
     * suspended. Intended for genuinely needed back-fill migrations where
     * the caller takes responsibility for recording the audit rows.
     *
     * Example:
     *   Document::withoutAuditGuards(function () use ($ids, $newId) {
     *       Document::query()->whereIn('id', $ids)->update(['identifier' => $newId]);
     *       // ... and now manually insert into document_identifier_history
     *   });
     *
     * @template T
     *
     * @param callable(): T $callback
     * @return T
     */
    public static function withoutAuditGuards(callable $callback): mixed
    {
        static::$bypassAuditGuard = true;

        try {
            return $callback();
        } finally {
            static::$bypassAuditGuard = false;
        }
    }

    /**
     * Used by DocumentBuilder to decide whether to enforce the bulk-update
     * guard on the `identifier` column.
     */
    public static function shouldBypassAuditGuard(): bool
    {
        return static::$bypassAuditGuard;
    }

    /**
     * Override performUpdate so single-model `save()` calls bypass the
     * DocumentBuilder bulk-update guard. Per-instance saves DO fire the
     * `updating`/`updated` events that DocumentObserver hooks into, so the
     * identifier-history audit trail is preserved — only the guard, which
     * exists for query-level bulk updates, must be temporarily suspended.
     */
    protected function performUpdate(Builder $query)
    {
        $previous = static::$bypassAuditGuard;
        static::$bypassAuditGuard = true;

        try {
            return parent::performUpdate($query);
        } finally {
            static::$bypassAuditGuard = $previous;
        }
    }
}
