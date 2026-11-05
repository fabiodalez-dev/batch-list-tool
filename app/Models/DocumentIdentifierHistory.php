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
    use Auditable;
    use BelongsToRepository;
    use HasFactory;

    /**
     * Table name is explicit because the conventional plural would mangle "history".
     */
    protected $table = 'document_identifier_history';

    protected $fillable = [
        'document_id',
        'previous_identifier',
        'new_identifier',
        // Feedback1 Wave C2.5 — the volume number the document held at the time
        // of this identifier transition; nullable so legacy rows stay valid and
        // a row can record an identifier change without a volume change.
        'previous_volume',
        'new_volume',
        // Client 2026-11-05: the authority the document used to be attributed to.
        'previous_authority_id',
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

    public function previousAuthority(): BelongsTo
    {
        return $this->belongsTo(Authority::class, 'previous_authority_id');
    }

    /**
     * Client 2026-11-05: "From volume 5 I want to change it to volume 4 but I
     * want to keep track of the old volume". The row keeps the document's own
     * identifier on both sides: the identifier did not change.
     */
    public static function recordPastVolume(Document $document, string $previousVolume, ?string $newVolume, ?string $reason = null): self
    {
        return static::create([
            'document_id' => $document->getKey(),
            'previous_identifier' => (string) $document->identifier,
            'new_identifier' => $document->identifier,
            'previous_volume' => $previousVolume,
            'new_volume' => $newVolume,
            'changed_at' => now(),
            'changed_by_user_id' => auth()->id(),
            'reason' => $reason,
            'repository_id' => $document->repository_id,
        ]);
    }

    /**
     * Client 2026-11-05: a document wrongly attributed to an authority is
     * changed, and "to whom it used to be attributed" is kept.
     */
    public static function recordPastAuthority(Document $document, int $authorityId, ?string $reason = null): self
    {
        return static::create([
            'document_id' => $document->getKey(),
            'previous_identifier' => (string) $document->identifier,
            'new_identifier' => $document->identifier,
            'previous_authority_id' => $authorityId,
            'changed_at' => now(),
            'changed_by_user_id' => auth()->id(),
            'reason' => $reason,
            'repository_id' => $document->repository_id,
        ]);
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
