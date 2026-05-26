<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Box;
use App\Models\BoxMovement;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Sprinkle realistic-looking activity on top of already-imported data so the
 * dashboard shows something interesting for the demo.
 *
 * Idempotent: safe to re-run. Does NOT seed Documents / Authorities / Series
 * (those come from ImportSampleData / InitialDataSeeder).
 *
 * Run:
 *   php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->markSampleAsDisinfested();
        $this->createSampleBoxMovements();
        $this->assignSampleUsersToRepository();

        if (isset($this->command)) {
            $this->command->info('DemoDataSeeder finished — dashboard now has realistic activity.');
        }
    }

    /**
     * Mark ~10% of documents that still have NULL disinfestation_date with
     * a date set to today. Idempotent: only touches NULL rows.
     */
    protected function markSampleAsDisinfested(): void
    {
        $totalNullPending = Document::query()
            ->withoutGlobalScope(RepositoryScope::class)
            ->whereNull('disinfestation_date')
            ->count();

        if ($totalNullPending === 0) {
            return;
        }

        $targetToUpdate = (int) max(1, floor($totalNullPending * 0.10));

        // Take the OLDEST pending documents — most realistic ordering.
        $ids = Document::query()
            ->withoutGlobalScope(RepositoryScope::class)
            ->whereNull('disinfestation_date')
            ->orderBy('id')
            ->limit($targetToUpdate)
            ->pluck('id')
            ->all();

        if (empty($ids)) {
            return;
        }

        Document::query()
            ->withoutGlobalScope(RepositoryScope::class)
            ->whereIn('id', $ids)
            ->update(['disinfestation_date' => now()->toDateString()]);
    }

    /**
     * Create up to 5 sample BoxMovements so the Recent Activity widget shows
     * realistic content. Idempotent: skips creation if we already created the
     * marker count.
     */
    protected function createSampleBoxMovements(): void
    {
        $existingMarkers = BoxMovement::query()
            ->where('reason', 'like', 'demo:%')
            ->count();
        if ($existingMarkers >= 5) {
            return;
        }

        $needed = 5 - $existingMarkers;

        $docs = Document::query()
            ->withoutGlobalScope(RepositoryScope::class)
            ->whereNotNull('current_box_id')
            ->orderBy('id')
            ->limit($needed * 2)
            ->get(['id', 'current_box_id']);

        if ($docs->isEmpty()) {
            return;
        }

        // pick any other box in the same DB to use as "to" target
        $candidateBoxIds = Box::query()
            ->orderBy('id')
            ->limit(20)
            ->pluck('id')
            ->all();

        if (count($candidateBoxIds) < 2) {
            return;
        }

        $created = 0;
        foreach ($docs as $doc) {
            if ($created >= $needed) {
                break;
            }
            $toBoxId = collect($candidateBoxIds)
                ->reject(fn ($id) => $id === $doc->current_box_id)
                ->first();
            if (! $toBoxId) {
                continue;
            }

            BoxMovement::create([
                'document_id' => $doc->id,
                'from_box_id' => $doc->current_box_id,
                'to_box_id' => $toBoxId,
                'movement_date' => now()->subDays($created + 1),
                'reason' => 'demo:dashboard_seed',
                'user_id' => User::query()->orderBy('id')->value('id'),
            ]);
            $created++;
        }
    }

    /**
     * Make sure every existing user has a default_repository_id so the
     * multi-tenant scoping looks correctly populated in the dashboard.
     * Does NOT create new users.
     */
    protected function assignSampleUsersToRepository(): void
    {
        $defaultRepo = Repository::query()->where('code', 'NRA')->first()
            ?? Repository::query()->first();

        if (! $defaultRepo) {
            return;
        }

        User::query()
            ->whereNull('default_repository_id')
            ->update(['default_repository_id' => $defaultRepo->id]);

        // And ensure the pivot is populated too (idempotent: syncWithoutDetaching).
        User::query()->cursor()->each(function (User $user) use ($defaultRepo): void {
            $hasAny = DB::table('repository_user')
                ->where('user_id', $user->id)
                ->exists();
            if (! $hasAny) {
                $user->repositories()->syncWithoutDetaching([
                    $defaultRepo->id => ['is_default' => true],
                ]);
            }
        });
    }
}
