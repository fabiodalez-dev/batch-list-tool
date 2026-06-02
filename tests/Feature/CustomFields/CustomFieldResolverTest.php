<?php

declare(strict_types=1);

use App\Models\CustomFieldDefinition;
use App\Models\Repository;
use App\Models\User;
use App\Support\ActiveRepository;
use App\Support\CustomFields\CustomFieldResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;

/**
 * Unit tests for CustomFieldResolver (spec §1):
 *
 *   - activeRepositoryId() prefers ActiveRepository over user default
 *   - activeRepositoryId() falls back to default_repository_id
 *   - activeRepositoryId() returns null when unauthenticated
 *   - definitionsFor() returns ordered active defs for the resolved repo
 *   - definitionsFor() isolates by repository (repo B absent when active = A)
 *   - definitionsFor() excludes inactive definitions
 *   - definitionsFor() excludes definitions for a different entity type
 *   - definitionsFor() memoises (second call does not re-query)
 *   - flush() clears the memo
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    CustomFieldResolver::flush();
});

afterEach(function (): void {
    CustomFieldResolver::flush();
});

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------

/**
 * Create a user with default_repository_id pointing to $repo.
 */
function cfr_user(Repository $repo): User
{
    bl_seedShieldPermissions();

    $user = User::factory()->create([
        'email' => 'cfr-' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repo->id,
    ]);
    $user->assignRole('super_admin');
    $user->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);
    $user->refresh();

    return $user;
}

/**
 * Seed a CustomFieldDefinition.
 *
 * @param array<string, mixed> $overrides
 */
function cfr_def(int $repoId, string $entityType, array $overrides = []): CustomFieldDefinition
{
    static $n = 0;
    $n++;

    return CustomFieldDefinition::create(array_merge([
        'repository_id' => $repoId,
        'entity_type' => $entityType,
        'key' => 'cfr_key_' . $n . '_' . substr(uniqid(), -4),
        'label' => 'CFR Field ' . $n,
        'type' => 'text',
        'is_active' => true,
        'sort_order' => $n,
    ], $overrides));
}

// ---------------------------------------------------------------------------
// activeRepositoryId() — resolution order
// ---------------------------------------------------------------------------

test('[Resolver] activeRepositoryId returns null when no user is authenticated', function (): void {
    // Make sure no user is authenticated.
    auth()->forgetGuards();

    $id = CustomFieldResolver::activeRepositoryId();

    expect($id)->toBeNull();
});

test('[Resolver] activeRepositoryId falls back to default_repository_id when ActiveRepository returns null', function (): void {
    $repo = Repository::factory()->create();
    $user = cfr_user($repo);

    $this->actingAs($user);

    // Session has no explicit ActiveRepository selection → id() returns null.
    Session::forget(ActiveRepository::SESSION_KEY);

    $id = CustomFieldResolver::activeRepositoryId();

    expect($id)->toBe((int) $repo->id);
});

test('[Resolver] activeRepositoryId prefers ActiveRepository over user default', function (): void {
    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();

    $user = cfr_user($repoA);  // default = repo A
    $user->repositories()->syncWithoutDetaching([$repoB->id]);

    $this->actingAs($user);

    // Explicitly select repo B via the ActiveRepository session key.
    // We bypass the sanitise logic by writing the session directly — we want
    // to test that the resolver READS from ActiveRepository, not that
    // ActiveRepository's sanitise passes. Use an admin/super_admin user so
    // any repo is accessible.
    Session::put(ActiveRepository::SESSION_KEY, $repoB->id);

    $id = CustomFieldResolver::activeRepositoryId();

    expect($id)->toBe((int) $repoB->id);
});

// ---------------------------------------------------------------------------
// definitionsFor() — correctness
// ---------------------------------------------------------------------------

test('[Resolver] definitionsFor returns active defs ordered by sort_order', function (): void {
    $repo = Repository::factory()->create();
    $user = cfr_user($repo);
    $this->actingAs($user);

    Session::forget(ActiveRepository::SESSION_KEY);  // use default_repository_id

    // Create two definitions with explicit sort_order.
    $def1 = cfr_def($repo->id, 'document', ['sort_order' => 10, 'label' => 'First']);
    $def2 = cfr_def($repo->id, 'document', ['sort_order' => 5,  'label' => 'Second']);

    $defs = CustomFieldResolver::definitionsFor('document');

    // sort_order 5 must come before 10.
    expect($defs->pluck('id')->all())->toBe([$def2->id, $def1->id]);
});

test('[Resolver] definitionsFor excludes inactive definitions', function (): void {
    $repo = Repository::factory()->create();
    $user = cfr_user($repo);
    $this->actingAs($user);

    Session::forget(ActiveRepository::SESSION_KEY);

    $active = cfr_def($repo->id, 'document', ['is_active' => true,  'label' => 'Active']);
    cfr_def($repo->id, 'document', ['is_active' => false, 'label' => 'Inactive']);

    $defs = CustomFieldResolver::definitionsFor('document');

    expect($defs->pluck('id')->all())->toContain($active->id);
    expect($defs->count())->toBe(1);
});

test('[Resolver] definitionsFor excludes definitions for a different entity type', function (): void {
    $repo = Repository::factory()->create();
    $user = cfr_user($repo);
    $this->actingAs($user);

    Session::forget(ActiveRepository::SESSION_KEY);

    $docDef = cfr_def($repo->id, 'document');
    cfr_def($repo->id, 'batch');   // different entity type

    $defs = CustomFieldResolver::definitionsFor('document');

    expect($defs->pluck('id')->all())->toBe([$docDef->id]);
});

test('[Resolver] definitionsFor isolates by repository — repo B absent when active is repo A', function (): void {
    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();

    $user = cfr_user($repoA);
    $this->actingAs($user);

    Session::forget(ActiveRepository::SESSION_KEY);  // fallback to repoA default

    $defA = cfr_def($repoA->id, 'document', ['label' => 'A Field']);
    cfr_def($repoB->id, 'document', ['label' => 'B Field']);  // must not appear

    $defs = CustomFieldResolver::definitionsFor('document');

    expect($defs->pluck('id')->all())->toBe([$defA->id]);
});

test('[Resolver] definitionsFor returns empty collection when resolved repo is null', function (): void {
    // No authenticated user — resolves to null.
    auth()->forgetGuards();

    $defs = CustomFieldResolver::definitionsFor('document');

    expect($defs->isEmpty())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Memo / flush
// ---------------------------------------------------------------------------

test('[Resolver] definitionsFor memoises results within the same request', function (): void {
    $repo = Repository::factory()->create();
    $user = cfr_user($repo);
    $this->actingAs($user);

    Session::forget(ActiveRepository::SESSION_KEY);

    cfr_def($repo->id, 'document', ['label' => 'Memo Field']);

    // First call — hits DB.
    $first = CustomFieldResolver::definitionsFor('document');

    // Add a new definition to the DB (should NOT appear in the memoised result).
    cfr_def($repo->id, 'document', ['label' => 'New After Memo']);

    // Second call — must return the cached (first) result.
    $second = CustomFieldResolver::definitionsFor('document');

    expect($second->count())->toBe($first->count());
    expect($second->pluck('id')->all())->toBe($first->pluck('id')->all());
});

test('[Resolver] flush clears the memo so next call re-queries', function (): void {
    $repo = Repository::factory()->create();
    $user = cfr_user($repo);
    $this->actingAs($user);

    Session::forget(ActiveRepository::SESSION_KEY);

    cfr_def($repo->id, 'document', ['label' => 'Before Flush']);

    // Populate the memo.
    $first = CustomFieldResolver::definitionsFor('document');
    expect($first->count())->toBe(1);

    // Add a new definition, then flush.
    cfr_def($repo->id, 'document', ['label' => 'After Flush']);
    CustomFieldResolver::flush();

    // Re-query must now return 2 definitions.
    $second = CustomFieldResolver::definitionsFor('document');
    expect($second->count())->toBe(2);
});
