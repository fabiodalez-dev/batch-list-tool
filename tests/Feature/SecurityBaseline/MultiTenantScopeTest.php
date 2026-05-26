<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxMovement;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Scopes\ThroughBoxBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * Security Baseline §7 / RFQ §3.5.1 — multi-tenant repository scoping
 *
 * Document (and any model using BelongsToRepository) MUST be filtered by the
 * repositories the authenticated user has been assigned to, EXCEPT when the
 * user has the `super_admin` or `admin` role (cross-repo oversight).
 *
 * Convention: RefreshDatabase on the SQLite in-memory test connection. The
 * Spatie roles/permissions are seeded by bl_seedShieldPermissions() in
 * tests/Pest.php — see the top-level beforeEach() below.
 */
uses(RefreshDatabase::class);

/**
 * Helper: create a Document with an explicit `repository_id` for assertions.
 *
 * Uses `forceCreate` (bypasses mass-assignment) because `repository_id` is
 * intentionally `$guarded` on Document — the production code-path is the
 * `BelongsToRepository::bootBelongsToRepository()` creating-hook, but in the
 * test setUp we need full control over which repo each fixture lives in.
 *
 * We also wrap in `withoutGlobalScope(RepositoryScope::class)` so the helper
 * works even when called inside an `actingAs(...)` block (the global scope
 * would otherwise filter the subsequent `find()` for fixture creation).
 */
function createDocumentInRepository(int $repositoryId, int $seriesId, string $identifier): Document
{
    return Document::withoutGlobalScope(RepositoryScope::class)->forceCreate([
        'identifier' => $identifier,
        'document_type' => 'TEST',
        'series_id' => $seriesId,
        'repository_id' => $repositoryId,
    ]);
}

function attachUserToRepository(User $user, Repository $repository, bool $isDefault = false): void
{
    $user->repositories()->attach($repository->id, ['is_default' => $isDefault]);
    if ($isDefault) {
        $user->default_repository_id = $repository->id;
        $user->save();
    }
}

beforeEach(function () {
    // Seed the Shield permission/role matrix mirroring InitialDataSeeder.
    // RepositoryScope checks roles by name; the fixtures below also rely on
    // `editor`/`admin`/`super_admin` existing.
    bl_seedShieldPermissions();

    // Two isolated test repositories. Permission/auditing make these heavy, so
    // we set is_active=true to mimic production rows.
    $this->repoA = Repository::factory()->create(['code' => 'TST_A_' . substr(uniqid(), -6)]);
    $this->repoB = Repository::factory()->create(['code' => 'TST_B_' . substr(uniqid(), -6)]);

    $this->series = Series::query()->first()
        ?? Series::create(['code' => 'TEST', 'title' => 'Test series', 'is_active' => true]);

    // 2 docs in repo A, 3 docs in repo B — assertions key on these counts.
    $this->docA1 = createDocumentInRepository($this->repoA->id, $this->series->id, 'TST-A-1');
    $this->docA2 = createDocumentInRepository($this->repoA->id, $this->series->id, 'TST-A-2');
    $this->docB1 = createDocumentInRepository($this->repoB->id, $this->series->id, 'TST-B-1');
    $this->docB2 = createDocumentInRepository($this->repoB->id, $this->series->id, 'TST-B-2');
    $this->docB3 = createDocumentInRepository($this->repoB->id, $this->series->id, 'TST-B-3');
});

test('editor scoped to repo A sees only repo A documents', function () {
    $editor = User::factory()->create(['email' => 'editor.a+' . uniqid() . '@test.local']);
    $editor->assignRole('editor');
    attachUserToRepository($editor, $this->repoA, isDefault: true);

    $this->actingAs($editor);

    $docs = Document::query()->whereIn('id', [
        $this->docA1->id, $this->docA2->id,
        $this->docB1->id, $this->docB2->id, $this->docB3->id,
    ])->get();

    // Exactly 2 visible: both repo A docs
    expect($docs)->toHaveCount(2);
    expect($docs->pluck('repository_id')->unique()->values()->all())
        ->toBe([$this->repoA->id]);
});

test('super_admin sees documents across all repositories', function () {
    $superAdmin = User::factory()->create(['email' => 'super+' . uniqid() . '@test.local']);
    $superAdmin->assignRole('super_admin');
    // Intentionally NOT attached to repoA or repoB — global view must still work.

    $this->actingAs($superAdmin);

    $visibleIds = Document::query()->whereIn('id', [
        $this->docA1->id, $this->docA2->id,
        $this->docB1->id, $this->docB2->id, $this->docB3->id,
    ])->pluck('id')->sort()->values()->all();

    $expectedIds = collect([
        $this->docA1->id, $this->docA2->id,
        $this->docB1->id, $this->docB2->id, $this->docB3->id,
    ])->sort()->values()->all();

    expect($visibleIds)->toBe($expectedIds);
});

test('admin role sees documents across all repositories (RFQ §3.5.1 oversight)', function () {
    $admin = User::factory()->create(['email' => 'admin+' . uniqid() . '@test.local']);
    $admin->assignRole('admin');
    // Attached to repoA only — yet `admin` MUST still see repoB docs.
    attachUserToRepository($admin, $this->repoA, isDefault: true);

    $this->actingAs($admin);

    $docs = Document::query()->whereIn('id', [
        $this->docA1->id, $this->docA2->id,
        $this->docB1->id, $this->docB2->id, $this->docB3->id,
    ])->get();

    expect($docs)->toHaveCount(5);
    expect($docs->pluck('repository_id')->unique()->sort()->values()->all())
        ->toBe(collect([$this->repoA->id, $this->repoB->id])->sort()->values()->all());
});

test('editor in repo A cannot read repo B documents even via explicit find', function () {
    $editor = User::factory()->create(['email' => 'editor.b+' . uniqid() . '@test.local']);
    $editor->assignRole('editor');
    attachUserToRepository($editor, $this->repoA, isDefault: true);

    $this->actingAs($editor);

    // Explicit find for a repo B document — global scope MUST filter it out.
    $shouldBeNull = Document::query()->find($this->docB1->id);
    expect($shouldBeNull)->toBeNull();

    // Bonus: a repo A document IS still findable
    $shouldBeFound = Document::query()->find($this->docA1->id);
    expect($shouldBeFound)->not->toBeNull();
    expect($shouldBeFound->id)->toBe($this->docA1->id);
});

/*
|--------------------------------------------------------------------------
| Mass-assignment / write-side protection (Issue 1)
|--------------------------------------------------------------------------
|
| `repository_id` is `$guarded` on Document / Batch / Accession; the
| BelongsToRepository creating-hook is the only path that may set it, and it
| validates the value against the user's repository pivot.
*/

test('it rejects creating a Document with a repository_id outside the user pivot', function () {
    $editor = User::factory()->create(['email' => 'mass.assign+' . uniqid() . '@test.local']);
    $editor->assignRole('editor');
    attachUserToRepository($editor, $this->repoA, isDefault: true);

    $this->actingAs($editor);

    // Try to forge a Document in repo B. Even if a malicious form submission
    // somehow smuggled `repository_id` past Filament, the creating hook MUST
    // throw a DomainException before the INSERT.
    expect(fn () => Document::query()->create([
        'identifier' => 'MASS-ASSIGN-' . uniqid(),
        'document_type' => 'TEST',
        'series_id' => $this->series->id,
        'repository_id' => $this->repoB->id, // foreign tenant!
    ]))->toThrow(DomainException::class, 'Multi-tenant violation');
});

test('it forces repository_id to user default on create when omitted', function () {
    $editor = User::factory()->create(['email' => 'default.repo+' . uniqid() . '@test.local']);
    $editor->assignRole('editor');
    attachUserToRepository($editor, $this->repoA, isDefault: true);

    $this->actingAs($editor);

    $identifier = 'DEFAULT-' . uniqid();
    $doc = Document::query()->create([
        'identifier' => $identifier,
        'document_type' => 'TEST',
        'series_id' => $this->series->id,
        // no repository_id provided
    ]);

    expect($doc->repository_id)->toBe($this->repoA->id);

    // And it must be persisted with that value (confirm via fresh read,
    // bypassing the scope so a wrong write would still be visible).
    $reloaded = Document::withoutGlobalScope(RepositoryScope::class)->find($doc->id);
    expect($reloaded->repository_id)->toBe($this->repoA->id);
});

test('admin can write to any repository on create', function () {
    $admin = User::factory()->create(['email' => 'admin.write+' . uniqid() . '@test.local']);
    $admin->assignRole('admin');
    // Attached to repoA only, but admin role bypasses the tenant check.
    attachUserToRepository($admin, $this->repoA, isDefault: true);

    $this->actingAs($admin);

    // Admin creating a Document in repo B — must succeed.
    $doc = Document::query()->create([
        'identifier' => 'ADMIN-CROSS-' . uniqid(),
        'document_type' => 'TEST',
        'series_id' => $this->series->id,
        'repository_id' => $this->repoB->id,
    ]);

    expect($doc)->not->toBeNull();
    expect($doc->repository_id)->toBe($this->repoB->id);
});

/*
|--------------------------------------------------------------------------
| Box and BoxMovement scoping (Issue 2)
|--------------------------------------------------------------------------
|
| Neither table carries `repository_id`. Tenancy is derived through
| `boxes.batch_id → batches.repository_id`.
*/

/**
 * Helper: create a Batch in a given repository, bypassing every scope and the
 * mass-assignment guard (because `repository_id` is `$guarded` on Batch).
 */
function createBatchInRepository(int $repositoryId, int $batchNumber): Batch
{
    return Batch::withoutGlobalScope(RepositoryScope::class)->forceCreate([
        'batch_number' => $batchNumber,
        'type' => 'MAIN_COLLECTION',
        'repository_id' => $repositoryId,
        'is_active' => true,
    ]);
}

/**
 * Helper: create a Box belonging to a given batch, bypassing every scope.
 */
function createBoxInBatch(int $batchId, string $boxNumber): Box
{
    return Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create([
        'box_type' => 'RAS',
        'box_number' => $boxNumber,
        'batch_id' => $batchId,
        'barcode_status' => 'IN',
        'is_legacy' => false,
    ]);
}

test('non-admin user cannot read a Box from another tenant', function () {
    // batch_number must avoid 33/34/36 (CHECK constraint) and be unique.
    $batchA = createBatchInRepository($this->repoA->id, 101);
    $batchB = createBatchInRepository($this->repoB->id, 102);

    $boxA = createBoxInBatch($batchA->id, 'BOX-A-' . uniqid());
    $boxB = createBoxInBatch($batchB->id, 'BOX-B-' . uniqid());

    $editor = User::factory()->create(['email' => 'box.scope+' . uniqid() . '@test.local']);
    $editor->assignRole('editor');
    // User only has access to repo B
    attachUserToRepository($editor, $this->repoB, isDefault: true);

    $this->actingAs($editor);

    // Explicit find on the repo A box → scope MUST filter it out
    expect(Box::query()->find($boxA->id))->toBeNull();

    // Repo B box IS still visible
    $foundB = Box::query()->find($boxB->id);
    expect($foundB)->not->toBeNull();
    expect($foundB->id)->toBe($boxB->id);

    // Aggregated count of these two boxes restricted to repo B
    $visibleCount = Box::query()->whereIn('id', [$boxA->id, $boxB->id])->count();
    expect($visibleCount)->toBe(1);
});

test('non-admin user cannot read a BoxMovement from another tenant', function () {
    $batchA = createBatchInRepository($this->repoA->id, 201);
    $batchB = createBatchInRepository($this->repoB->id, 202);

    $boxA = createBoxInBatch($batchA->id, 'BOX-A-' . uniqid());
    $boxB = createBoxInBatch($batchB->id, 'BOX-B-' . uniqid());

    // Create movements as super-user (no scope active because unauthenticated)
    $movementA = BoxMovement::withoutGlobalScope(ThroughBoxBatchRepositoryScope::class)->create([
        'document_id' => $this->docA1->id,
        'from_box_id' => null,
        'to_box_id' => $boxA->id,
        'movement_date' => now(),
        'reason' => 'test-fixture',
    ]);
    $movementB = BoxMovement::withoutGlobalScope(ThroughBoxBatchRepositoryScope::class)->create([
        'document_id' => $this->docB1->id,
        'from_box_id' => null,
        'to_box_id' => $boxB->id,
        'movement_date' => now(),
        'reason' => 'test-fixture',
    ]);

    $editor = User::factory()->create(['email' => 'movement.scope+' . uniqid() . '@test.local']);
    $editor->assignRole('editor');
    attachUserToRepository($editor, $this->repoB, isDefault: true);

    $this->actingAs($editor);

    // BoxMovement for repo A is invisible
    expect(BoxMovement::query()->find($movementA->id))->toBeNull();

    // BoxMovement for repo B is visible
    $foundB = BoxMovement::query()->find($movementB->id);
    expect($foundB)->not->toBeNull();
    expect($foundB->id)->toBe($movementB->id);

    $visibleCount = BoxMovement::query()
        ->whereIn('id', [$movementA->id, $movementB->id])
        ->count();
    expect($visibleCount)->toBe(1);
});
