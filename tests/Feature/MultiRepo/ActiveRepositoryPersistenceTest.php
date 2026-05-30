<?php

declare(strict_types=1);

use App\Http\Middleware\ApplyUserPreferences;
use App\Models\Repository;
use App\Models\User;
use App\Support\ActiveRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wave 2 Task 10 — §4.3.3 cross-session active-repository persistence.
 *
 * Verifies that:
 *   1. A user who previously chose repo A (users.active_repository_id = A) has
 *      that choice restored into the session on a fresh request through
 *      ApplyUserPreferences, and ActiveRepository::id() returns A.
 *   2. An admin/super_admin who NEVER chose (column null) keeps id() = null →
 *      the "see ALL repositories" admin-override behaviour is NOT broken.
 *   3. A restored value that is no longer in the user's allowed set is rejected
 *      → id() returns null (fail-closed), not the revoked id.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

// ─── helpers ─────────────────────────────────────────────────────────────────

/**
 * Run ApplyUserPreferences for the given authenticated user on a fresh session
 * (no session key set yet) and return the resolved active-repository id.
 */
function arp_runMiddleware(User $user): ?int
{
    Auth::setUser($user);

    // Ensure the session key is absent — simulates a brand-new session.
    Session::forget(ActiveRepository::SESSION_KEY);

    $middleware = app(ApplyUserPreferences::class);
    $middleware->handle(
        Request::create('/admin'),
        fn ($req) => new Response,
    );

    return app(ActiveRepository::class)->id();
}

// ─── test 1: user with an explicit prior choice ───────────────────────────────

it('restores the last explicit repository choice from the persisted column into a fresh session', function () {
    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();

    $user = User::factory()->create();
    $user->assignRole('editor');
    $user->repositories()->attach([$repoA->id, $repoB->id]);

    // Simulate a prior explicit choice already written to the column.
    $user->forceFill(['active_repository_id' => $repoA->id])->saveQuietly();

    $resolved = arp_runMiddleware($user);

    // The restored id must match repo A.
    expect($resolved)->toBe($repoA->id);

    // The session must carry the restored value for subsequent requests.
    expect(Session::get(ActiveRepository::SESSION_KEY))->toBe($repoA->id);
});

// ─── test 2: admin who never chose → must see ALL ────────────────────────────

it('admin with no prior choice (column null) keeps id() null and sees all repositories', function () {
    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();
    $repoC = Repository::factory()->create();

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $admin->repositories()->attach([$repoA->id, $repoB->id]);
    // Explicitly ensure the column is null — never chose a repo.
    $admin->forceFill(['active_repository_id' => null])->saveQuietly();

    $resolved = arp_runMiddleware($admin);

    // null → "All repositories" — admin-override behaviour preserved.
    expect($resolved)->toBeNull();
});

// ─── test 3: restored value no longer allowed → fall back to null ────────────

it('ignores a persisted choice that is no longer within the user allowed set', function () {
    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();
    $repoC = Repository::factory()->create(); // user is NOT a member of C

    $user = User::factory()->create();
    $user->assignRole('editor');
    $user->repositories()->attach([$repoA->id, $repoB->id]); // allowed: A, B only

    // Column points to C — a repo no longer (or never) in the allowed set.
    $user->forceFill(['active_repository_id' => $repoC->id])->saveQuietly();

    $resolved = arp_runMiddleware($user);

    // Must be rejected, fall back to null (All) — not C.
    expect($resolved)->toBeNull();
    expect(Session::get(ActiveRepository::SESSION_KEY))->toBeNull();
});

// ─── test 4: restore is idempotent (no double-write on subsequent requests) ───

it('does not overwrite an in-session choice on subsequent requests through the middleware', function () {
    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();

    $user = User::factory()->create();
    $user->assignRole('editor');
    $user->repositories()->attach([$repoA->id, $repoB->id]);

    // Column says A, but the user has already switched to B this session.
    $user->forceFill(['active_repository_id' => $repoA->id])->saveQuietly();

    Auth::setUser($user);
    // Pre-populate the session with B (simulates a mid-session switch).
    Session::put(ActiveRepository::SESSION_KEY, $repoB->id);

    $middleware = app(ApplyUserPreferences::class);
    $middleware->handle(
        Request::create('/admin'),
        fn ($req) => new Response,
    );

    // The in-session value (B) must survive — column (A) must NOT overwrite it.
    expect(app(ActiveRepository::class)->id())->toBe($repoB->id);
});
