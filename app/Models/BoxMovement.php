<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

class BoxMovement extends Model implements AuditableContract
{
    use Auditable;
    use BelongsToRepository; // RFQ §3.5.1 — own repository_id + direct scope
    use HasFactory;

    /**
     * A real, dated custody move recorded by the app (MoveToBoxAction) or an
     * operator backfilling a legacy date. `movement_date` is non-null.
     */
    public const DATE_SOURCE_RECORDED = 'recorded';

    /**
     * A legacy move reconstructed from the box-provenance columns on the
     * document (client 2026-08-18 #1). The client cannot date these, so
     * `movement_date` is NULL and the row is sorted first in the timeline.
     */
    public const DATE_SOURCE_LEGACY = 'legacy_import';

    /**
     * `repository_id` is mass-assignable (mirroring Document): the
     * BelongsToRepository `creating` hook is the security gate that validates
     * the chosen value against the acting user's repositories. Callers pass
     * the destination box's repository so a movement is always stamped with
     * the tenant it physically belongs to.
     *
     * `date_source` + `sequence` back the legacy-timeline feature (#1).
     */
    protected $fillable = [
        'document_id', 'repository_id', 'from_box_id', 'to_box_id', 'movement_date',
        'date_source', 'sequence', 'reason', 'user_id',
    ];

    protected $casts = [
        'movement_date' => 'datetime',
        'sequence' => 'integer',
    ];

    /**
     * Chronological timeline order for a document's moves.
     *
     * Undated legacy moves (movement_date IS NULL) come FIRST — they predate
     * every dated move and their relative order is carried by `sequence` — then
     * dated moves ascending. On MariaDB/MySQL `ORDER BY movement_date ASC`
     * already sorts NULLs first, but we make the NULLs-first intent explicit
     * with `movement_date IS NULL DESC` so the order is identical on SQLite
     * (used by the test suite) and never depends on the driver's NULL-ordering
     * default. `sequence` then `id` break ties deterministically.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query
            ->orderByRaw('movement_date IS NULL DESC')
            ->orderBy('movement_date')
            ->orderBy('sequence')
            ->orderBy('id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function fromBox(): BelongsTo
    {
        return $this->belongsTo(Box::class, 'from_box_id');
    }

    public function toBox(): BelongsTo
    {
        return $this->belongsTo(Box::class, 'to_box_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        // Invariant (#1): the flag always follows the presence of a real date.
        // A NULL date is a legacy import; a non-null date is a recorded move.
        // This makes an operator typing a real date onto a legacy row auto-flip
        // the flag to 'recorded' — exactly the client's requirement — and keeps
        // the two fields from ever drifting apart, whatever path writes the row.
        static::saving(function (self $movement): void {
            $movement->date_source = $movement->movement_date === null
                ? self::DATE_SOURCE_LEGACY
                : self::DATE_SOURCE_RECORDED;
        });
    }
}
