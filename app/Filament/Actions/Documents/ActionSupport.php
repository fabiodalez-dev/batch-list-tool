<?php

declare(strict_types=1);

namespace App\Filament\Actions\Documents;

use App\Models\Document;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use OwenIt\Auditing\Models\Audit;

/**
 * Shared helpers for the Document power-action classes under this namespace.
 *
 * The actions all share three small responsibilities:
 *   1. Writing a custom audit row for non-column changes (pivot writes, cross-
 *      tenant transfers, …) — the {@see Auditable} trait only records column
 *      diffs on the host model, so anything else has to be logged manually.
 *   2. Building the standard success / partial-success / failure
 *      {@see Notification} payloads with the same
 *      title shape, so the operator sees a consistent UX across the 15 actions.
 *   3. Normalising the "selected records" argument across the single-record
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
