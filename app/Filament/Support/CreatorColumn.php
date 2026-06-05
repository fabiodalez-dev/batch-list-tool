<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Models\Audit;

/**
 * A9 (Wave A) — shared "Inputter" column factory.
 *
 * Every core resource table should call `CreatorColumn::make()` and spread
 * the result into the columns array.  Area-phase sub-agents (Batch, Box,
 * Accession …) can do this with a single line:
 *
 *   ...CreatorColumn::make(),   // or just add the returned column directly
 *
 * Resolution strategy:
 *   All core models use the OwenIt `Auditable` trait which stores an `audits`
 *   polymorphic relation in the `audits` table.  The first audit entry with
 *   `event = 'created'` carries `user_id` / `user_type` that links back to
 *   the `users` table.  There is no dedicated `created_by` column on the
 *   tables themselves (confirmed by grepping the models).
 *
 *   The column therefore resolves via a sub-relation: `audits` (ordered by
 *   id asc, first row) → `user` → `name`.  Filament TextColumn supports
 *   dot-notation through a HasMany when we eager-load it ourselves, but the
 *   cleanest approach is a virtual attribute defined on the model via
 *   `getState()`.  We use a state closure that:
 *     1. Loads the first audit (event = 'created') via the `audits` relation.
 *        If it was already eager-loaded (the area resource should ->with(['audits.user']))
 *        this is O(1); otherwise it runs a lazy query.
 *     2. Returns the linked user's `name`, or null if no audit exists yet.
 *
 * Usage in a resource:
 *
 *   use App\Filament\Support\CreatorColumn;
 *
 *   // Inside table():
 *   Tables\Columns\TextColumn::make('batch_number'),
 *   ...
 *   CreatorColumn::make(),     // returns a single TextColumn instance
 *
 *   // Optionally eager-load to avoid N+1:
 *   $table->query(Batch::query()->with(['audits' => fn ($q) => $q->where('event', 'created')->with('user')]));
 */
final class CreatorColumn
{
    /**
     * Build the "Inputter" TextColumn.
     *
     * The column name is `inputter` (virtual — not a real DB column).
     * It is not sortable because the value comes from a related table and
     * sorting would require a sub-query join; the column is searchable
     * via the toggleable panel instead.  Default visibility is SHOWN so
     * operators see who entered a record immediately (A9 requirement).
     */
    public static function make(): TextColumn
    {
        return TextColumn::make('inputter')
            ->label('Inputter')
            ->getStateUsing(static function (object $record): ?string {
                /** @var Model&Auditable $record */

                // Use an already-loaded relation if possible; otherwise lazy-load.
                if ($record->relationLoaded('audits')) {
                    // Access via getRelation() so PHPStan sees a typed collection.
                    /** @var Collection<int,Audit> $loadedAudits */
                    $loadedAudits = $record->getRelation('audits');
                    $audit = $loadedAudits
                        ->where('event', 'created')
                        ->sortBy('id')
                        ->first();
                } else {
                    $audit = $record->audits()
                        ->where('event', 'created')
                        ->oldest('id')
                        ->first();
                }

                if ($audit === null) {
                    return null;
                }

                // Resolve the creator user — prefer the already-loaded `user`
                // relation on the audit (eager-loaded by the resource as
                // `audits.user`) to avoid an N+1 query per row.
                //
                // getRelation('user') returns mixed per Eloquent's PHPDoc; we
                // use a @var annotation to narrow the type so PHPStan accepts
                // the ->name access.  The audit model declares `@property mixed
                // $user` so we cannot use the magic property directly.
                if ($audit->relationLoaded('user')) {
                    /** @var User|null $user */
                    $user = $audit->getRelation('user');
                } else {
                    // Lazy fallback for contexts where the relation was not
                    // pre-loaded (e.g. single-record view).  One query, not N.
                    /** @var int|string|null $userId */
                    $userId = $audit->getAttribute('user_id');
                    $user = $userId !== null ? User::query()->find($userId) : null;
                }

                return $user?->name;
            })
            ->placeholder('—')
            ->toggleable()
            ->sortable(false);
    }
}
