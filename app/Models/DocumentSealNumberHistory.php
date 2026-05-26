<?php

namespace App\Models;

use App\Models\Concerns\BelongsToRepository;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * DocumentSealNumberHistory — append-only log of seal number changes for a Document.
 *
 * Each row represents a transition: `previous_seal_number` -> `new_seal_number`,
 * captured at `changed_at` by `changed_by_user_id`, with an optional `reason`.
 * The `repository_id` is mirrored from the parent document for tenant scoping.
 *
 * Shape and semantics mirror {@see DocumentIdentifierHistory}; keep both in
 * sync when the audit substrate evolves.
 */
class DocumentSealNumberHistory extends Model implements AuditableContract
{
    use Auditable;
    use BelongsToRepository;
    use HasFactory;

    /**
     * Table name is explicit because the conventional plural would mangle "history".
     */
    protected $table = 'document_seal_number_history';

    protected $fillable = [
        'document_id',
        'previous_seal_number',
        'new_seal_number',
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
     * Record a seal number transition for the given document.
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
            'previous_seal_number' => (string) $previous,
            'new_seal_number' => $new,
            'changed_at' => now(),
            'changed_by_user_id' => $userId ?? auth()->id(),
            'reason' => $reason,
            'repository_id' => $document->repository_id,
        ]);
    }
}
