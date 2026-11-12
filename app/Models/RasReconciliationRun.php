<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One upload of the RAS export, compared with our RAS boxes (client
 * 2026-11-10, Report 1). Only the latest run per repository is kept.
 *
 * @property int $id
 * @property int|null $repository_id
 * @property int|null $user_id
 * @property string $original_filename
 * @property int $ras_rows
 * @property int $our_boxes
 * @property Carbon|null $compared_at
 * @property Carbon|null $created_at
 */
class RasReconciliationRun extends Model
{
    protected $fillable = [
        'repository_id', 'user_id', 'original_filename', 'ras_rows', 'our_boxes', 'compared_at',
    ];

    protected $casts = [
        'ras_rows' => 'integer',
        'our_boxes' => 'integer',
        'compared_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Repository, $this> */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /** @return HasMany<RasReconciliationRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(RasReconciliationRow::class, 'run_id');
    }

    /**
     * The run that belongs to this repository scope. With "All repositories"
     * active (null) the run is also the user's own: what it lists depends on
     * the repositories that user may see, so it is not shared.
     *
     * @param Builder<self> $query
     * @return Builder<self>
     */
    public function scopeForScope(Builder $query, ?int $repositoryId, ?int $userId): Builder
    {
        return $repositoryId === null
            ? $query->whereNull('repository_id')->where('user_id', $userId)
            : $query->where('repository_id', $repositoryId);
    }
}
