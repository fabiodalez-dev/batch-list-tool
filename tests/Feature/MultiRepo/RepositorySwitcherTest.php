<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\User;
use App\Support\ActiveRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Task 10 (RFQ Wave 2) — session repository switcher with an
 * "All repositories" option + active-repo scoping (Submission §4.3.3).
 *
 * Principle: EXPAND NEVER RESTRICT. The current behaviour — a user sees data
 * from ALL their repositories — MUST remain available via active = null
 * ("All repositories"). Selecting a specific repo narrows the global scope to
 * that single repository (still bounded by the user's allowed set).
 *
 * `Batch` carries `repository_id` directly and uses `RepositoryScope`, so it
 * genuinely exercises the direct scope (the task's `Box` helper would route
 * through `ThroughBatchRepositoryScope`, which scopes via batch.repository_id).
 */
uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

function w2t10_uniqueBatchNumber(): int
{
    do {
        $n = random_int(1, 29);
    } while (
        Batch::withoutGlobalScope(RepositoryScope::class)
            ->where('batch_number', $n)->exists()
    );

    return $n;
}

function w2t10_batchIn(Repository $r): Batch
{
    return Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => w2t10_uniqueBatchNumber(),
        'repository_id' => $r->id,
        'type' => 'MAIN_COLLECTION',
        'is_active' => true,
    ]);
}

it('with active=All shows batches from all the user repos (current behaviour preserved)', function () {
    $u = User::factory()->create();
    $u->assignRole('editor');
    $a = Repository::factory()->create();
    $b = Repository::factory()->create();
    $u->repositories()->attach([$a->id, $b->id]);
    $this->actingAs($u);

    $ba = w2t10_batchIn($a);
    $bb = w2t10_batchIn($b);

    app(ActiveRepository::class)->set(null); // All

    $ids = Batch::query()->pluck('id');
    expect($ids)->toContain($ba->id)->toContain($bb->id);
});

it('with a specific active repo shows only that repo', function () {
    $u = User::factory()->create();
    $u->assignRole('editor');
    $a = Repository::factory()->create();
    $b = Repository::factory()->create();
    $u->repositories()->attach([$a->id, $b->id]);
    $this->actingAs($u);

    $ba = w2t10_batchIn($a);
    $bb = w2t10_batchIn($b);

    app(ActiveRepository::class)->set($a->id);

    $ids = Batch::query()->pluck('id');
    expect($ids)->toContain($ba->id)->not->toContain($bb->id);
});

it('falls back to All when the active id is outside the user allowed repos', function () {
    $u = User::factory()->create();
    $u->assignRole('editor');
    $a = Repository::factory()->create();
    $b = Repository::factory()->create();
    $foreign = Repository::factory()->create();
    $u->repositories()->attach([$a->id, $b->id]);
    $this->actingAs($u);

    $ba = w2t10_batchIn($a);
    $bb = w2t10_batchIn($b);

    // A repo the user is NOT a member of → must be rejected, fall back to All.
    app(ActiveRepository::class)->set($foreign->id);

    expect(app(ActiveRepository::class)->id())->toBeNull();

    $ids = Batch::query()->pluck('id');
    expect($ids)->toContain($ba->id)->toContain($bb->id);
});

it('persists the active repo to the user record across sessions', function () {
    $u = User::factory()->create();
    $u->assignRole('editor');
    $a = Repository::factory()->create();
    $b = Repository::factory()->create();
    $u->repositories()->attach([$a->id, $b->id]);
    $this->actingAs($u);

    app(ActiveRepository::class)->set($a->id);

    expect($u->fresh()->active_repository_id)->toBe($a->id);

    // Setting back to All clears the persisted mirror too.
    app(ActiveRepository::class)->set(null);
    expect($u->fresh()->active_repository_id)->toBeNull();
});

it('renders the topbar switcher on the dashboard for a multi-repo user', function () {
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('editor');
    $a = Repository::factory()->create(['name' => 'Repo Alpha']);
    $b = Repository::factory()->create(['name' => 'Repo Beta']);
    $u->repositories()->attach([$a->id, $b->id]);
    $this->actingAs($u);

    $this->get('/admin')
        ->assertOk()
        ->assertSee('All repositories')
        ->assertSee('Repo Alpha')
        ->assertSee('Repo Beta');
});

it('the switcher route sets the active repo and redirects back', function () {
    $u = User::factory()->create();
    $u->assignRole('editor');
    $a = Repository::factory()->create();
    $b = Repository::factory()->create();
    $u->repositories()->attach([$a->id, $b->id]);
    $this->actingAs($u);

    $response = $this->from('/admin')
        ->post(route('active-repository.update'), ['repository_id' => $a->id]);

    $response->assertRedirect('/admin');
    $response->assertSessionHas(ActiveRepository::SESSION_KEY, $a->id);

    // A foreign id posted through the route is rejected → All.
    $foreign = Repository::factory()->create();
    $this->from('/admin')
        ->post(route('active-repository.update'), ['repository_id' => $foreign->id])
        ->assertRedirect('/admin');
    expect(session(ActiveRepository::SESSION_KEY))->toBeNull();
});
