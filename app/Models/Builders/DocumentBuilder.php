<?php

namespace App\Models\Builders;

use App\Models\Document;
use Illuminate\Database\Eloquent\Builder;

/**
 * Custom Eloquent builder for the Document model.
 *
 * Why this exists:
 *  Eloquent's per-model events (`updating`/`updated`) — and therefore the
 *  DocumentObserver that records identifier changes into
 *  `document_identifier_history` — are NOT fired when callers use bulk
 *  query-level operations such as:
 *
 *      Document::query()->update(['identifier' => '...'])
 *      DB::table('documents')->update([...])
 *      Document::query()->upsert([...], ...)
 *
 *  Any such bypass silently breaks the audit trail required by RFQ §3.1.5
 *  (full chain-of-custody for every identifier transition). This guard
 *  intercepts bulk `update()` calls on the Eloquent builder and refuses
 *  them when they touch the `identifier` column.
 *
 *  The escape hatch is `Document::withoutAuditGuards(fn () => ...)`, which
 *  flips a static flag for the duration of the callback and is intended for
 *  one-off back-fill migrations where audit rows must be written by the
 *  caller (or are intentionally not needed, e.g. seeding test data).
 *
 *  Note: this guard cannot stop raw `DB::table('documents')->update([...])`
 *  calls — those bypass Eloquent entirely. A DB-level trigger would be the
 *  belt-and-suspenders solution for that; for now we rely on this guard
 *  plus the convention that all writes go through the Document model.
 */
class DocumentBuilder extends Builder
{
    /**
     * Guard: bulk updates that touch the `identifier` column bypass the
     * DocumentObserver, leaving no row in document_identifier_history.
     * RFQ §3.1.5 requires a full audit trail of identifier changes.
     *
     * If a bulk identifier change is genuinely needed (e.g., back-fill
     * migration), wrap it in Document::withoutAuditGuards(fn () => ...).
     */
    public function update(array $values)
    {
        if (array_key_exists('identifier', $values) && ! Document::shouldBypassAuditGuard()) {
            throw new \LogicException(
                'Bulk update of Document.identifier is forbidden — it bypasses '
                . 'DocumentObserver and breaks the identifier_history audit trail '
                . '(RFQ §3.1.5). Loop over models and update individually, or wrap '
                . 'in Document::withoutAuditGuards(fn () => $query->update([...])).'
            );
        }

        return parent::update($values);
    }
}
