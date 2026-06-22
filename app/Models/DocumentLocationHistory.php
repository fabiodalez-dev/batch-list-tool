<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DocumentLocationHistory — append-only log of a document's location changes.
 *
 * NAF Feedback-1 (comment #19): the app previously showed only a document's
 * CURRENT location. Each row here captures a `from_location → to_location`
 * transition at `changed_at` by `changed_by_user_id`, with a snapshot of each
 * location's breadcrumb label so the trail survives later location renames /
 * deletions. Rows are written by the Document model's created / updated hooks;
 * there is no public create surface (history is immutable). Mirrors
 * {@see DocumentBarcodeHistory}.
 */
class DocumentLocationHistory extends Model
{
    use BelongsToRepository;

    protected $table = 'document_location_history';

    protected $fillable = [
        'document_id',
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

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
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
