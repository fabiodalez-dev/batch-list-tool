<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'destination_disk',
        'status',
        'started_at',
        'finished_at',
        'duration_seconds',
        'size_bytes',
        'message',
        'triggered_by_user_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_seconds' => 'integer',
        'size_bytes' => 'integer',
    ];

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Order by started_at descending (most recent first).
     *
     * @param Builder<self> $q
     * @return Builder<self>
     */
    public function scopeRecent(Builder $q): Builder
    {
        return $q->latest('started_at');
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    /**
     * The user who triggered this backup run (nullable — nullOnDelete).
     *
     * @return BelongsTo<User, $this>
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
