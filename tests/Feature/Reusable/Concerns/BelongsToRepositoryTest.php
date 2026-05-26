<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Reusable: BelongsToRepository trait + RepositoryScope global scope.
 *
 * These tests pin the multi-tenant scoping behaviour required by RFQ §3.5.1.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function btr_makeUser(string $role, ?int $repoId = null): User
{
    $u = User::factory()->create([
        'email' => $role . '-' . uniqid() . '@btr.test',
        'is_active' => true,
        'default_repository_id' => $repoId,
    ]);
    $u->assignRole($role);
    if ($repoId !== null && in_array($role, ['editor', 'viewer'], true)) {
        $u->repositories()->attach($repoId, ['is_default' => true]);
    }

    return $u;
}

function btr_makeBatch(int $repoId, int $number, array $attrs = []): Batch
{
    return Batch::withoutGlobalScope(RepositoryScope::class)->create(array_merge([
        'batch_number' => $number,
        'type' => 'MAIN_COLLECTION',
        'repository_id' => $repoId,
        'is_active' => true,
    ], $attrs));
}

function btr_uniqueBatchNumber(): int
{
    do {
        $n = random_int(1000, 9000);
    } while (in_array($n, [33, 34, 36], true)
        || Batch::withoutGlobalScope(RepositoryScope::class)
            ->where('batch_number', $n)->exists());

    return $n;
}

it('BelongsToRepository: admin sees batches from every repository (scope bypassed)', function () {
    $repoA = Repository::factory()->create(['code' => 'BTR-A-' . substr(uniqid(), -4)]);
    $repoB = Repository::factory()->create(['code' => 'BTR-B-' . substr(uniqid(), -4)]);

    $bA = btr_makeBatch($repoA->id, btr_uniqueBatchNumber());
    $bB = btr_makeBatch($repoB->id, btr_uniqueBatchNumber());

    $admin = btr_makeUser('admin');
    $this->actingAs($admin);

    $ids = Batch::query()->whereIn('id', [$bA->id, $bB->id])->pluck('id')->all();
    expect($ids)->toContain($bA->id)->and($ids)->toContain($bB->id);
});

it('BelongsToRepository: super_admin sees batches from every repository (scope bypassed)', function () {
    $repoA = Repository::factory()->create(['code' => 'BTR-SA-A-' . substr(uniqid(), -4)]);
    $repoB = Repository::factory()->create(['code' => 'BTR-SA-B-' . substr(uniqid(), -4)]);

    $bA = btr_makeBatch($repoA->id, btr_uniqueBatchNumber());
    $bB = btr_makeBatch($repoB->id, btr_uniqueBatchNumber());

    $sa = btr_makeUser('super_admin');
    $this->actingAs($sa);

    $ids = Batch::query()->whereIn('id', [$bA->id, $bB->id])->pluck('id')->all();
    expect($ids)->toContain($bA->id)->and($ids)->toContain($bB->id);
});

it('BelongsToRepository: editor only sees their own repository batches', function () {
    $repoA = Repository::factory()->create(['code' => 'BTR-E-A-' . substr(uniqid(), -4)]);
    $repoB = Repository::factory()->create(['code' => 'BTR-E-B-' . substr(uniqid(), -4)]);

    $bA = btr_makeBatch($repoA->id, btr_uniqueBatchNumber());
    $bB = btr_makeBatch($repoB->id, btr_uniqueBatchNumber());

    $editor = btr_makeUser('editor', $repoA->id);
    $this->actingAs($editor);

    $ids = Batch::query()->whereIn('id', [$bA->id, $bB->id])->pluck('id')->all();
    expect($ids)->toContain($bA->id)->and($ids)->not->toContain($bB->id);
});

it('BelongsToRepository: viewer only sees their own repository batches', function () {
    $repoA = Repository::factory()->create(['code' => 'BTR-V-A-' . substr(uniqid(), -4)]);
    $repoB = Repository::factory()->create(['code' => 'BTR-V-B-' . substr(uniqid(), -4)]);

    $bA = btr_makeBatch($repoA->id, btr_uniqueBatchNumber());
    $bB = btr_makeBatch($repoB->id, btr_uniqueBatchNumber());

    $viewer = btr_makeUser('viewer', $repoA->id);
    $this->actingAs($viewer);

    $ids = Batch::query()->whereIn('id', [$bA->id, $bB->id])->pluck('id')->all();
    expect($ids)->toContain($bA->id)->and($ids)->not->toContain($bB->id);
});

it('BelongsToRepository: unauthenticated (CLI/queue) bypasses scope entirely', function () {
    $repoA = Repository::factory()->create(['code' => 'BTR-CLI-A-' . substr(uniqid(), -4)]);
    $repoB = Repository::factory()->create(['code' => 'BTR-CLI-B-' . substr(uniqid(), -4)]);

    $bA = btr_makeBatch($repoA->id, btr_uniqueBatchNumber());
    $bB = btr_makeBatch($repoB->id, btr_uniqueBatchNumber());

    // No actingAs — CLI/queue context
    $ids = Batch::query()->whereIn('id', [$bA->id, $bB->id])->pluck('id')->all();
    expect($ids)->toContain($bA->id)->and($ids)->toContain($bB->id);
});

it('BelongsToRepository: editor with no assigned repo sees nothing (whereRaw 1=0)', function () {
    $repoA = Repository::factory()->create(['code' => 'BTR-NONE-' . substr(uniqid(), -4)]);
    btr_makeBatch($repoA->id, btr_uniqueBatchNumber());

    $editor = btr_makeUser('editor'); // no repo assigned
    $this->actingAs($editor);

    $count = Batch::query()->count();
    expect($count)->toBe(0);
});

it('BelongsToRepository: editor creating Batch in foreign repo throws DomainException', function () {
    $repoA = Repository::factory()->create(['code' => 'BTR-DOM-A-' . substr(uniqid(), -4)]);
    $repoB = Repository::factory()->create(['code' => 'BTR-DOM-B-' . substr(uniqid(), -4)]);

    $editor = btr_makeUser('editor', $repoA->id);
    $this->actingAs($editor);

    expect(fn () => Batch::create([
        'batch_number' => btr_uniqueBatchNumber(),
        'type' => 'MAIN_COLLECTION',
        'repository_id' => $repoB->id, // foreign tenant
        'is_active' => true,
    ]))->toThrow(DomainException::class);
});

it('BelongsToRepository: editor creating Batch in own repo succeeds', function () {
    $repoA = Repository::factory()->create(['code' => 'BTR-OK-' . substr(uniqid(), -4)]);
    $editor = btr_makeUser('editor', $repoA->id);
    $this->actingAs($editor);

    $n = btr_uniqueBatchNumber();
    $b = Batch::create([
        'batch_number' => $n,
        'type' => 'MAIN_COLLECTION',
        'repository_id' => $repoA->id,
        'is_active' => true,
    ]);

    expect($b->id)->not->toBeNull()
        ->and($b->repository_id)->toBe($repoA->id);
});

it('BelongsToRepository: missing repository_id defaults to user default_repository_id', function () {
    $repoA = Repository::factory()->create(['code' => 'BTR-DEF-' . substr(uniqid(), -4)]);
    $editor = btr_makeUser('editor', $repoA->id);
    $this->actingAs($editor);

    $n = btr_uniqueBatchNumber();
    $b = Batch::create([
        'batch_number' => $n,
        'type' => 'MAIN_COLLECTION',
        'is_active' => true,
        // repository_id omitted
    ]);

    expect($b->repository_id)->toBe($repoA->id);
});

it('BelongsToRepository: withoutGlobalScope(RepositoryScope) bypasses tenant filter', function () {
    $repoA = Repository::factory()->create(['code' => 'BTR-WO-A-' . substr(uniqid(), -4)]);
    $repoB = Repository::factory()->create(['code' => 'BTR-WO-B-' . substr(uniqid(), -4)]);

    btr_makeBatch($repoA->id, btr_uniqueBatchNumber());
    btr_makeBatch($repoB->id, btr_uniqueBatchNumber());

    $viewer = btr_makeUser('viewer', $repoA->id);
    $this->actingAs($viewer);

    $scoped = Batch::query()->count();
    $unscoped = Batch::withoutGlobalScope(RepositoryScope::class)->count();

    expect($unscoped)->toBeGreaterThan($scoped);
});
