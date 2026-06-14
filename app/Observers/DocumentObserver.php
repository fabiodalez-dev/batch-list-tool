<?php

namespace App\Observers;

use App\Models\Document;
use App\Models\DocumentIdentifierHistory;

/**
 * DocumentObserver — captures identifier transitions on Document updates.
 *
 * Two-phase pattern:
 *  - `updating()` — Document is still dirty: capture old + new values into a
 *    static buffer keyed by document id.
 *  - `updated()` — Document is persisted: read the buffer and write the
 *    history row. This avoids the classic "isDirty returns false after save"
 *    pitfall.
 *
 * Skipped cases:
 *  - Brand-new documents (handled in `created`, not `updated`) -> no history row.
 *  - previous identifier and new identifier both null -> noise, skip.
 *  - Whitespace-only changes (trimming makes them equal) -> noise, skip.
 */
class DocumentObserver
{
    /**
     * Pending transitions, keyed by document primary key.
     *
     * @var array<int|string, array{previous: ?string, new: ?string}>
     */
    private static array $pending = [];

    public function updating(Document $document): void
    {
        if (! $document->isDirty('identifier')) {
            return;
        }

        $previous = $document->getOriginal('identifier');
        $new = $document->identifier;

        // Skip both-null transitions and pure whitespace changes.
        if ($this->shouldSkip($previous, $new)) {
            return;
        }

        self::$pending[$document->getKey()] = [
            'previous' => $previous,
            'new' => $new,
        ];
    }

    public function updated(Document $document): void
    {
        $key = $document->getKey();

        if (! array_key_exists($key, self::$pending)) {
            return;
        }

        $transition = self::$pending[$key];
        unset(self::$pending[$key]);

        DocumentIdentifierHistory::recordChange(
            document: $document,
            previous: $transition['previous'],
            new: $transition['new'],
        );
    }

    /**
     * Decide whether the transition is noise we shouldn't log.
     */
    private function shouldSkip(?string $previous, ?string $new): bool
    {
        // Both null -> nothing to record.
        if ($previous === null && $new === null) {
            return true;
        }

        // Whitespace-only difference -> ignore.
        return trim((string) $previous) === trim((string) $new);
    }
}
