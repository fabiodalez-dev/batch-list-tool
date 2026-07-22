<?php

declare(strict_types=1);

use App\Filament\Imports\BatchImporter;
use App\Models\Batch;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * Import subsystem review (2026-07-22) — unique-constraint / cross-tenant safety.
 *
 * The DB key on batches is the COMPOSITE (batch_number, repository_id) — added
 * in 2026_05_28_140000 specifically so each repository may own its own Batch 50.
 * BatchImporter::resolveRecord() must therefore match within the SAME repository
 * the row targets; a batch_number-only match would resolve another repo's row
 * and silently reassign (steal) it on save().
 */
uses(RefreshDatabase::class);

function bcr_admin(?int $repoId): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    /** @var User $u */
    $u = User::factory()->create([
        'email' => 'bcr+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repoId,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * @param array<string, mixed> $data
 */
function bcr_import(array $data, int $userId): void
{
    EntityResolver::flushMemo();
    /** @var Import $row */
    $row = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'batch.xlsx',
        'file_path' => '/tmp/batch.xlsx',
        'importer' => BatchImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);
    $map = array_combine(array_keys($data), array_keys($data));
    (new BatchImporter($row, $map, []))($data);
}

test('importing batch 50 for repository B does not steal repository A\'s batch 50', function () {
    $repoA = Repository::create(['code' => 'AAA', 'name' => 'Repo A']);
    $repoB = Repository::create(['code' => 'BBB', 'name' => 'Repo B']);

    // Repo A already owns Batch 50.
    $batchA = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 50,
        'repository_id' => $repoA->id,
        'description' => 'A-owned',
    ]);

    // An operator whose default repository is B imports Batch 50 (no explicit
    // repository_code → falls back to the user's default = B).
    $userB = bcr_admin($repoB->id);
    $this->actingAs($userB);
    bcr_import(['batch_number' => 50], $userB->id);

    $batchA->refresh();
    expect($batchA->repository_id)->toBe($repoA->id); // untouched, not stolen

    $all = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 50)->get();
    expect($all)->toHaveCount(2)
        ->and($all->pluck('repository_id')->sort()->values()->all())
        ->toBe(collect([$repoA->id, $repoB->id])->sort()->values()->all());
});

test('a user with no default repository and no repository_code cannot match (and steal) another repo\'s batch', function () {
    $repoA = Repository::create(['code' => 'AAA', 'name' => 'Repo A']);

    $batchA = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 50,
        'repository_id' => $repoA->id,
        'description' => 'A-owned',
    ]);

    // Cross-tenant super_admin: default_repository_id = null, row has no
    // repository_code → repository is undeterminable. resolveRecord() must NOT
    // fall back to a batch_number-only match across every repository.
    $user = bcr_admin(null);
    $this->actingAs($user);

    // The row can't resolve a repository, so it fails cleanly (NOT NULL
    // repository_id) instead of matching A's batch — swallow that failure.
    try {
        bcr_import(['batch_number' => 50], $user->id);
    } catch (Throwable) {
        // expected: no repository to insert into
    }

    $batchA->refresh();
    expect($batchA->repository_id)->toBe($repoA->id) // untouched, not stolen
        ->and(Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 50)->count())->toBe(1);
});

test('re-importing batch 50 with the owning repository_code updates that repo\'s row (idempotent)', function () {
    $repoA = Repository::create(['code' => 'AAA', 'name' => 'Repo A']);
    Repository::create(['code' => 'BBB', 'name' => 'Repo B']);

    $batchA = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 50,
        'repository_id' => $repoA->id,
        'description' => 'original',
    ]);

    // Operator default is B, but the row explicitly names repo A → must match
    // A's existing row and update it, NOT insert a duplicate.
    $userB = bcr_admin(Repository::where('code', 'BBB')->value('id'));
    $this->actingAs($userB);
    bcr_import(['batch_number' => 50, 'repository_code' => 'AAA', 'description' => 'updated'], $userB->id);

    $all = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 50)->get();
    expect($all)->toHaveCount(1);
    $batchA->refresh();
    expect($batchA->repository_id)->toBe($repoA->id)
        ->and($batchA->description)->toBe('updated');
});
