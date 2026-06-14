<?php

declare(strict_types=1);

namespace App\Models\Pivots;

use App\Models\Accession;
use App\Models\Batch;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * F041 — pivot model for the Accession ↔ Batch N:N relation, carrying the
 * same-repository guard mandated by spec B5 ("pivot rows respect repository
 * scope — both sides same repo").
 *
 * Why a pivot model rather than a saving() guard on Accession/Batch: Filament's
 * BelongsToMany sync()/attach() write the pivot rows directly and fire ONLY the
 * pivot model's events — a `saving()` hook on the parent models never sees the
 * attach. Wiring this class via ->using(self::class) on both relations means the
 * `creating` event below fires for every attach/sync row.
 *
 * Null-tolerant, mirroring the F030 cross-tenant guard on Box.php: the check
 * only fires when BOTH sides resolve a non-null repository_id and they differ.
 * Legacy rows with a null repository on either side attach freely
 * (expand-never-restrict). Existing rows are not retro-validated (create only).
 */
class AccessionBatch extends Pivot
{
    public $incrementing = true;

    protected static function booted(): void
    {
        static::creating(function (self $pivot): void {
            $accessionRepoId = Accession::withoutGlobalScopes()
                ->whereKey($pivot->accession_id)
                ->value('repository_id');

            $batchRepoId = Batch::withoutGlobalScopes()
                ->whereKey($pivot->batch_id)
                ->value('repository_id');

            // Both sides must resolve non-null for the guard to fire — a null
            // repository on either side is tolerated (legacy data).
            throw_if($accessionRepoId !== null
                && $batchRepoId !== null
                && (int) $accessionRepoId !== (int) $batchRepoId, \DomainException::class, 'Accession and Batch belong to different repositories; a pivot row '
            . 'must keep both sides in the same repository (spec B5, F041).');
        });
    }
}
