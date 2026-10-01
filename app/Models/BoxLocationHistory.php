<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BoxLocationHistory — append-only log of a box's location changes
 * (RFQ §3.1.6).
 *
 * Each row captures a `from_location → to_location` transition at
 * `changed_at` by `changed_by_user_id`, with a snapshot of each location's
 * breadcrumb so the trail survives a later rename or deletion of the
 * location. Rows are written by the Box model's created / updated hooks;
 * there is no create surface in the interface (history is immutable).
 * Mirrors {@see DocumentLocationHistory}.
 */
class BoxLocationHistory extends Model
{
    use BelongsToRepository;

    protected $table = 'box_location_history';

    protected $fillable = [
        'box_id',
        'repository_id',
        'from_location_id',
        'to_location_id',
        'from_location_label',
        'to_location_label',
        'changed_by_user_id',
        'changed_at',
        'source',
        'notes',
    ];

    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function box(): BelongsTo
    {
        return $this->belongsTo(Box::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }
}
