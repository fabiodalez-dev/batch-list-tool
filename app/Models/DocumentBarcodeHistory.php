<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DocumentBarcodeHistory — append-only log of per-document barcode changes.
 *
 * Task 7b (RFQ Wave 2 expansion): beyond the box-level barcode (contract),
 * each document may carry its own optional barcode value for individual
 * labelling. Each row captures a `old_value → new_value` transition at
 * `changed_at` by `changed_by_user_id`.
 *
 * Rows are written by the Document model's `created` / `updated` hooks; there
 * is no public create surface in the UI (history is immutable). Mirrors the
 * BoxSealNumberHistory pattern.
 *
 * The `repository_id` is taken directly from the parent document's own
 * `repository_id` column (unlike boxes, which derive it via batch).
 */
class DocumentBarcodeHistory extends Model
{
    use BelongsToRepository;

    protected $table = 'document_barcode_history';

    protected $fillable = [
        'document_id',
        'repository_id',
        'old_value',
        'new_value',
        'changed_by_user_id',
        'changed_at',
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
}
