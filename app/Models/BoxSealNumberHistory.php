<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BoxSealNumberHistory — append-only log of seal-number changes for a Box.
 *
 * RFQ Contract App.2-i: the yellow security seal that closes a box belongs to
 * the BOX, and a history of every seal number must be kept for ALL boxes
 * (especially the Batch 50 wills reserve). Each row is a transition
 * `old_value -> new_value`, captured at `changed_at` by `changed_by_user_id`.
 *
 * Rows are written by the Box model's `saved` hook; there is no public
 * create surface in the UI (history is immutable).
 */
class BoxSealNumberHistory extends Model
{
    protected $table = 'box_seal_number_history';

    protected $fillable = [
        'box_id',
        'old_value',
        'new_value',
        'changed_by_user_id',
        'changed_at',
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
}
