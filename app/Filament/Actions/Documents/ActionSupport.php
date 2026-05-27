<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Models\Document;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Models\Audit;

/**
 * Shared helpers for the Document power-action classes under this namespace.
 *
 * The actions all share four small responsibilities:
 *   1. Writing a custom audit row for non-column changes (pivot writes, cross-
 *      tenant transfers, …) — the {@see Auditable} trait only records column
 *      diffs on the host model, so anything else has to be logged manually.
 *   2. Per-row atomic execution of the bulk loop via
 *      {@see self::performBulk()} so a failure on row N rolls back row N's
 *      writes (which may span the document + pivot + audit), without
 *      affecting earlier successful rows.
 *   3. Building the standard success / partial-success / failure
 *      {@see Notification} payloads with the same
 *      title shape, so the operator sees a consistent UX across the 15 actions.
 *   4. Normalising the "selected records" argument across the single-record
 *      and bulk variants.
 *
 * Keeping this in one small static helper avoids a base class hierarchy
 * (Filament actions are deliberately stateless / closure-based, sub-classing
 * Action would fight that grain) while still removing the obvious DRY pain.
 */
final class ActionSupport
{
    /**
     * Write a manual audit row for a non-column change.
     *
     * Used for pivot writes (authority attach / detach / replace) and for the
     * cross-tenant transfer (where the change is a column write but we want
     * a more descriptive `event` than the default "updated").
     *
     * @param array<string, mixed> $newValues
     * @param array<string, mixed> $oldValues
     */
    public static function logPivotChange(
        Document $document,
        string $event,
        array $newValues,
        array $oldValues = [],
        string $tags = 'pivot',
    ): void {
        Audit::create([
            'user_type' => auth()->user() ? auth()->user()::class : null,
            'user_id' => auth()->id(),
            'event' => $event,
            'auditable_type' => Document::class,
            'auditable_id' => $document->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'url' => self::safeRequestUrl(),
            'ip_address' => self::safeRequestIp(),
            'user_agent' => self::safeRequestUserAgent(),
            'tags' => $tags,
        ]);
    }

    /**
     * Coerce whatever the action closure received into an
     * {@see EloquentCollection} of Documents — the actions internally always
     * operate on a Collection, even the single-record variants, so the
     * downstream logic can stay uniform.
     */
    public static function asCollection(Document|EloquentCollection|Collection $records): EloquentCollection
    {
        if ($records instanceof Document) {
            /** @var EloquentCollection<int, Document> $coll */
            $coll = new EloquentCollection([$records]);

            return $coll;
        }

        if ($records instanceof EloquentCollection) {
            /** @var EloquentCollection<int, Document> $records */
            return $records;
        }

        /** @var EloquentCollection<int, Document> $coll */
        $coll = new EloquentCollection($records->all());

        return $coll;
    }

    /**
     * Execute a per-row callback inside its own database transaction so that:
     *   - row N's failure does NOT undo row N-1's successful writes;
     *   - row N's own multi-step writes (document save + pivot insert +
     *     audit row + box movement) DO roll back atomically.
     *
     * Pattern recap of why the previous code was unsafe (review C-2):
     * `DB::transaction(fn () => foreach { try { ... } catch { $errors[] } })`
     * swallowed every per-row exception inside the closure, so the closure
     * returned normally and Laravel committed the OUTER transaction —
     * including all the rows that succeeded before the failure. That gave
     * "best-effort partial commit" semantics while the docstring / tests
     * claimed all-or-nothing rollback. This helper makes the partial-commit
     * semantics explicit AND restores per-row atomicity at the same time.
     *
     * The callback receives the {@see Document} (and may throw any
     * \Throwable to signal a per-row failure; the message is captured into
     * the result for the partial-success notification).
     *
     * @param EloquentCollection<int, Document> $records
     * @param callable(Document): void $perRow
     * @return array{ok:int, errors:array<int,string>, skipped:int}
     */
    public static function performBulk(
        EloquentCollection $records,
        callable $perRow,
    ): array {
        $ok = 0;
        $errors = [];

        foreach ($records as $doc) {
            /** @var Document $doc */
            try {
                DB::transaction(static function () use ($perRow, $doc): void {
                    $perRow($doc);
                });
                $ok++;
            } catch (\Throwable $e) {
                $errors[] = "#{$doc->identifier}: {$e->getMessage()}";
            }
        }

        return ['ok' => $ok, 'errors' => $errors, 'skipped' => 0];
    }

    /**
     * Render the standard 3-state notification (full success / partial /
     * failure) given the {@see self::performBulk()} result tuple.
     *
     * `successVerb` is appended after the count, e.g. "moved to Box BX-42".
     * `failedTitle` is the title used when nothing succeeded (so operators
     * can tell "Move failed" apart from "Reclassification failed").
     *
     * Title is always plain text — Filament's `Notification::title()` is HTML
     * escaped by default unless someone explicitly calls `->htmlable()`.
     * We deliberately do NOT, so the title is XSS-safe even when concatenated
     * with DB-controlled values (box_number, identifier, …).
     *
     * @param array{ok:int, errors:array<int,string>, skipped?:int} $result
     */
    public static function notifyBulkResult(
        array $result,
        string $successVerb,
        string $failedTitle = 'Action failed',
    ): void {
        $ok = (int) ($result['ok'] ?? 0);
        $errors = $result['errors'] ?? [];

        if ($errors === [] && $ok > 0) {
            Notification::make()
                ->title("{$ok} document(s) {$successVerb}")
                ->success()
                ->send();

            return;
        }

        if ($ok > 0 && $errors !== []) {
            Notification::make()
                ->title("Partial: {$ok} {$successVerb}, " . count($errors) . ' failed')
                ->body(implode("\n", array_slice($errors, 0, 5)))
                ->warning()
                ->send();

            return;
        }

        $failed = count($errors);
        $title = $failed > 0 ? "{$failedTitle} ({$failed} failed)" : $failedTitle;

        Notification::make()
            ->title($title)
            ->body($errors === [] ? 'No documents were processed.' : implode("\n", array_slice($errors, 0, 5)))
            ->danger()
            ->send();
    }

    /**
     * Safe wrapper around request()->fullUrl() so unit tests / console runs
     * (where there is no incoming HTTP request) don't blow up.
     */
    public static function safeRequestUrl(): ?string
    {
        try {
            return request()->fullUrl();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function safeRequestIp(): ?string
    {
        try {
            return request()->ip();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function safeRequestUserAgent(): ?string
    {
        try {
            return (string) (request()->userAgent() ?? '');
        } catch (\Throwable) {
            return null;
        }
    }
}
