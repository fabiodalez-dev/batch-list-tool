<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\User;
use App\Support\ActiveRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;

use function Pest\Laravel\actingAs;

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

// The ActiveRepository scope reads the session; tests in this file set it via
// the resolver / the POST route. Flush it after EACH test so a value left in
// the shared in-process session never bleeds into the next test (review C6:
// the added blade-render test would otherwise leave a primed session that
// flipped the later route test's foreign-id assertion).
afterEach(function () {
    Session::flush();
});

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

it('defaults to All (null) when nothing is explicitly selected', function () {
    $repo = Repository::factory()->create();
    $user = User::factory()->create(['default_repository_id' => $repo->id]);
    $user->assignRole('editor');
    actingAs($user);

    expect(app(ActiveRepository::class)->id())->toBeNull();
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

it('C6: the switcher lists a repository reachable only via default_repository_id', function () {
    // Pivot grants repo A; the default grants repo B but B is NOT in the pivot.
    $repoA = Repository::factory()->create(['name' => 'Alpha Repo', 'is_active' => true]);
    $repoB = Repository::factory()->create(['name' => 'Beta Default Only', 'is_active' => true]);

    $user = User::factory()->create(['default_repository_id' => $repoB->id]);
    $user->assignRole('editor');
    $user->repositories()->syncWithoutDetaching([$repoA->id]); // only A in the pivot

    $this->actingAs($user);

    // Allowed-set source of truth must include BOTH (pivot ∪ default).
    expect(ActiveRepository::allowedRepositoryIdsFor($user))
        ->toContain($repoA->id)
        ->toContain($repoB->id);

    // The rendered switcher must surface the default-only repo (B), which the
    // old pivot-only query omitted.
    $html = view('filament.topbar.repository-switcher')->render();

    expect($html)->toContain('Beta Default Only')
        ->and($html)->toContain('Alpha Repo');
});
