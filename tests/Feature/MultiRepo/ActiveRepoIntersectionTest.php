<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\User;
use App\Support\ActiveRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Fix 2 (C1) — the session-backed active-repository narrowing MUST be
 * intersected with the user's allowed set, in BOTH the direct scope
 * (RepositoryScope, exercised by Batch) and the through-batch scope
 * (ThroughBatchRepositoryScope, exercised by Box).
 *
 * Two invariants:
 *   - a stale / revoked active id that is NOT in the allowed set is IGNORED:
 *     the user falls back to the full allowed set (never a forbidden repo,
 *     never empty, never widened).
 *   - an active id that IS in the allowed set narrows to exactly that repo.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

function ari_uniqueBatchNumber(): int
{
    do {
        $n = random_int(1, 29);
    } while (
        Batch::withoutGlobalScope(RepositoryScope::class)
            ->where('batch_number', $n)->exists()
    );

    return $n;
}

function ari_batchIn(Repository $r): Batch
{
    return Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => ari_uniqueBatchNumber(),
        'repository_id' => $r->id,
        'type' => 'MAIN_COLLECTION',
        'is_active' => true,
    ]);
}

function ari_boxIn(Batch $batch): Box
{
    return Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create([
        'box_type' => 'RAS',
        'box_number' => 'ARI-' . strtoupper(substr(uniqid(), -6)),
        'batch_id' => $batch->id,
        'barcode_status' => 'IN',
        'is_legacy' => false,
    ]);
}

/**
 * @return array{0:User,1:Repository,2:Repository,3:Repository}
 */
function ari_userWithAB(): array
{
    $u = User::factory()->create();
    $u->assignRole('editor');
    $a = Repository::factory()->create();
    $b = Repository::factory()->create();
    $c = Repository::factory()->create(); // NOT allowed
    $u->repositories()->attach([$a->id, $b->id]);

    return [$u, $a, $b, $c];
}

/* ─── RepositoryScope (direct, Batch) ─────────────────────────────────────── */

it('direct scope: active set to a NON-allowed repo is ignored → sees full allowed set', function () {
    [$u, $a, $b, $c] = ari_userWithAB();
    $this->actingAs($u);

    $ba = ari_batchIn($a);
    $bb = ari_batchIn($b);

    // Force a stale/forbidden active id straight into the session (bypassing
    // set()'s sanitisation) to simulate a revoked grant.
    session([ActiveRepository::SESSION_KEY => $c->id]);

    $ids = Batch::query()->whereIn('id', [$ba->id, $bb->id])->pluck('id')->all();

    expect($ids)->toContain($ba->id)->toContain($bb->id)->toHaveCount(2);
});

it('direct scope: active set to an allowed repo narrows to only that repo', function () {
    [$u, $a, $b] = ari_userWithAB();
    $this->actingAs($u);

    $ba = ari_batchIn($a);
    $bb = ari_batchIn($b);

    session([ActiveRepository::SESSION_KEY => $a->id]);

    $ids = Batch::query()->whereIn('id', [$ba->id, $bb->id])->pluck('id')->all();

    expect($ids)->toContain($ba->id)->not->toContain($bb->id)->toHaveCount(1);
});

/* ─── ThroughBatchRepositoryScope (Box) ───────────────────────────────────── */

it('through-batch scope: active set to a NON-allowed repo is ignored → sees full allowed set', function () {
    [$u, $a, $b, $c] = ari_userWithAB();
    $this->actingAs($u);

    $boxA = ari_boxIn(ari_batchIn($a));
    $boxB = ari_boxIn(ari_batchIn($b));

    session([ActiveRepository::SESSION_KEY => $c->id]);

    $ids = Box::query()->whereIn('id', [$boxA->id, $boxB->id])->pluck('id')->all();

    expect($ids)->toContain($boxA->id)->toContain($boxB->id)->toHaveCount(2);
});

it('through-batch scope: active set to an allowed repo narrows to only that repo', function () {
    [$u, $a, $b] = ari_userWithAB();
    $this->actingAs($u);

    $boxA = ari_boxIn(ari_batchIn($a));
    $boxB = ari_boxIn(ari_batchIn($b));

    session([ActiveRepository::SESSION_KEY => $a->id]);

    $ids = Box::query()->whereIn('id', [$boxA->id, $boxB->id])->pluck('id')->all();

    expect($ids)->toContain($boxA->id)->not->toContain($boxB->id)->toHaveCount(1);
});

/* ─── Fix 3 (I1): through-batch scope must honour default_repository_id ─────
 |
 | A user whose ONLY access is via users.default_repository_id (empty pivot)
 | sees Documents (RepositoryScope folds in default_repository_id) but must
 | ALSO see that repository's Boxes — ThroughBatchRepositoryScope previously
 | built its allowed set from the pivot only, hiding every box (zero).
 */
it('through-batch scope: a user with empty pivot but default_repository_id sees that repo boxes', function () {
    $a = Repository::factory()->create();

    // Seed the fixture BEFORE acting-as so the BelongsToRepository creating
    // hook (CLI/unauthenticated context) trusts the explicit repository_id.
    $boxA = ari_boxIn(ari_batchIn($a));

    $u = User::factory()->create(['default_repository_id' => $a->id]);
    $u->assignRole('editor');
    // Intentionally NO pivot attach — access is solely via default_repository_id.
    $this->actingAs($u);

    $ids = Box::query()->whereIn('id', [$boxA->id])->pluck('id')->all();

    expect($ids)->toContain($boxA->id)->toHaveCount(1);
});
