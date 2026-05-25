<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRepository;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * DocumentIdentifierHistory — append-only log of identifier changes for a Document.
 *
 * Each row represents a transition: `previous_identifier` -> `new_identifier`,
 * captured at `changed_at` by `changed_by_user_id`, with an optional `reason`.
 * The `repository_id` is mirrored from the parent document for tenant scoping.
 */
class DocumentIdentifierHistory extends Model implements AuditableContract
{
    use HasFactory;
    use Auditable;
    use BelongsToRepository;

    /**
     * Table name is explicit because the conventional plural would mangle "history".
     */
    protected $table = 'document_identifier_history';

    protected $fillable = [
        'document_id',
        'previous_identifier',
        'new_identifier',
        'changed_at',
        'changed_by_user_id',
        'reason',
        'repository_id',
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

    /**
     * Record an identifier transition for the given document.
     *
     * - `$userId` defaults to `auth()->id()` when null.
     * - `repository_id` is inherited from the document if not overridden.
     * - `changed_at` defaults to now() at the DB layer (`useCurrent()`),
     *    but is set explicitly here so factories / back-fills can override it.
     */
    public static function recordChange(
        Document $document,
        ?string $previous,
        ?string $new,
        ?string $reason = null,
        ?int $userId = null,
    ): self {
        return static::create([
            'document_id' => $document->getKey(),
            'previous_identifier' => (string) $previous,
            'new_identifier' => $new,
            'changed_at' => now(),
            'changed_by_user_id' => $userId ?? auth()->id(),
            'reason' => $reason,
            'repository_id' => $document->repository_id,
        ]);
    }
}
