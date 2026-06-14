<?php

declare(strict_types=1);

use App\Filament\Imports\BoxImporter;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * ReviewFixesTenancyTest — Multi-tenancy fixes for BoxImporter.
 *
 * Tests for:
 *  F023 — batch_number resolved without repository scoping (importer)
 *  F030 — parent_barcode links a parent RAS box without tenancy validation
 */
uses(RefreshDatabase::class);

// ── Helpers ──────────────────────────────────────────────────────────────────

function rft_repo(string $prefix = 'RFT'): Repository
{
    return Repository::factory()->create([
        'code' => $prefix . '_' . strtoupper(substr(uniqid(), -6)),
    ]);
}

function rft_sa(int $repoId): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }

    /** @var User $u */
    $u = User::factory()->create([
        'email' => 'rft-sa+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repoId,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * Drive BoxImporter on a single row.
 *
 * @param array<string, mixed> $data
 * @param array<string, string>|null $columnMap
 */
function rft_box_run(array $data, int $userId, ?array $columnMap = null): BoxImporter
{
    EntityResolver::flushMemo();

    /** @var Import $imp */
    $imp = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'test.xlsx',
        'file_path' => '/tmp/test.xlsx',
        'importer' => BoxImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);

    if ($columnMap === null) {
        $columnMap = array_combine(array_keys($data), array_keys($data));
    }

    $importer = new BoxImporter($imp, $columnMap, []);
    $importer($data);

    return $importer;
}

// ─────────────────────────────────────────────────────────────────────────────
// F023-1 — two repos with the same batch_number; user default=A → repo A's batch
// ─────────────────────────────────────────────────────────────────────────────

it('F023-1: box import with batch_number 5 links to repo A when user default is A', function (): void {
    $repoA = rft_repo('F023A');
    $repoB = rft_repo('F023B');
    $u = rft_sa($repoA->id);
    $this->actingAs($u);

    $batchA = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 5,
        'repository_id' => $repoA->id,
        'type' => 'MAIN_COLLECTION',
        'is_active' => true,
    ]);
    $batchB = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 5,
        'repository_id' => $repoB->id,
        'type' => 'MAIN_COLLECTION',
        'is_active' => true,
    ]);

    rft_box_run([
        'box_number' => 'BOX-F023-1',
        'box_type' => 'RAS',
        'batch_number' => 5,
        'barcode' => 'BC-F023-1',
    ], $u->id);

    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->where('box_number', 'BOX-F023-1')
        ->first();

    expect($box)->not->toBeNull();
    // Must link to repo A's batch, not repo B's.
    expect($box->batch_id)->toBe($batchA->id);
    expect($box->batch_id)->not->toBe($batchB->id);
    // Confirm repo derivation.
    expect((int) $box->batch->repository_id)->toBe($repoA->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// F023-2 — batch number exists ONLY in repo B; user default=A → no cross-tenant link
// ─────────────────────────────────────────────────────────────────────────────

it('F023-2: box import with batch_number that exists only in repo B (user default=A) fails — no cross-tenant box', function (): void {
    $repoA = rft_repo('F023C');
    $repoB = rft_repo('F023D');
    $u = rft_sa($repoA->id);
    $this->actingAs($u);

    // batch_number 7 exists ONLY in repo B.
    $batchB = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 7,
        'repository_id' => $repoB->id,
        'type' => 'MAIN_COLLECTION',
        'is_active' => true,
    ]);

    // The import should fail (batch not found in repo A → batch_id remains null
    // → NOT NULL constraint → row error). No box must end up linked to batchB.
    try {
        rft_box_run([
            'box_number' => 'BOX-F023-2',
            'box_type' => 'RAS',
            'batch_number' => 7,
            'barcode' => 'BC-F023-2',
        ], $u->id);
        // If no exception was thrown, check that no box linked to B was created.
        $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
            ->where('box_number', 'BOX-F023-2')
            ->first();
        // Either the box doesn't exist, or it must NOT be linked to repo B's batch.
        if ($box !== null) {
            expect($box->batch_id)->not->toBe($batchB->id);
        }
    } catch (Throwable) {
        // Any exception (ValidationException, QueryException for NOT NULL, etc.)
        // is also an acceptable outcome — row rejected.
        $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
            ->where('box_number', 'BOX-F023-2')
            ->first();
        expect($box)->toBeNull();
    }

    // Regardless of outcome: no box may be linked to batchB.
    $crossBox = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->where('batch_id', $batchB->id)
        ->first();
    expect($crossBox)->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// F030-1 — cross-tenant parent_barcode is rejected (parent RAS box in repo B)
// ─────────────────────────────────────────────────────────────────────────────

it('F030-1: import IN_SITU box into repo A\'s batch with parent_barcode belonging to repo B → row error, no cross-tenant parent', function (): void {
    $repoA = rft_repo('F030A');
    $repoB = rft_repo('F030B');
    $u = rft_sa($repoA->id);
    $this->actingAs($u);

    $batchA = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 8,
        'repository_id' => $repoA->id,
        'type' => 'MAIN_COLLECTION',
        'is_active' => true,
    ]);
    $batchB = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 9,
        'repository_id' => $repoB->id,
        'type' => 'MAIN_COLLECTION',
        'is_active' => true,
    ]);
    // Parent RAS box belongs to repo B.
    $parentRAS = Box::withoutGlobalScopes()->create([
        'batch_id' => $batchB->id,
        'box_number' => 'RAS-B-1',
        'barcode' => 'BC-F030-CROSS',
        'box_type' => 'RAS',
    ]);

    expect(fn () => rft_box_run([
        'box_number' => 'IS-F030-CROSS',
        'box_type' => 'IN_SITU',
        'batch_number' => 8,         // repo A's batch
        'parent_barcode' => 'BC-F030-CROSS', // repo B's barcode
    ], $u->id))->toThrow(ValidationException::class);

    // No IN_SITU box must have been created.
    $inSitu = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->where('box_number', 'IS-F030-CROSS')
        ->first();
    expect($inSitu)->toBeNull();
});

// ─────────────────────────────────────────────────────────────────────────────
// F030-2 — same-repo parent_barcode resolves and links correctly
// ─────────────────────────────────────────────────────────────────────────────

it('F030-2: import IN_SITU box with same-repo parent_barcode links successfully', function (): void {
    $repoA = rft_repo('F030C');
    $u = rft_sa($repoA->id);
    $this->actingAs($u);

    $batchA = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 11,
        'repository_id' => $repoA->id,
        'type' => 'MAIN_COLLECTION',
        'is_active' => true,
    ]);
    // IN_SITU boxes require a Location — create one in the same repo.
    $location = Location::factory()->create(['repository_id' => $repoA->id, 'is_active' => true]);
    // Parent RAS box in the SAME repo.
    $parentRAS = Box::withoutGlobalScopes()->create([
        'batch_id' => $batchA->id,
        'box_number' => 'RAS-A-OK',
        'barcode' => 'BC-F030-SAME',
        'box_type' => 'RAS',
    ]);

    rft_box_run([
        'box_number' => 'IS-F030-SAME',
        'box_type' => 'IN_SITU',
        'batch_number' => 11,
        'parent_barcode' => 'BC-F030-SAME',
        'location' => $location->code,
    ], $u->id);

    $inSitu = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->where('box_number', 'IS-F030-SAME')
        ->first();

    expect($inSitu)->not->toBeNull();
    expect($inSitu->parent_box_id)->toBe($parentRAS->id);
    expect($inSitu->batch_id)->toBe($batchA->id);
});

// ─────────────────────────────────────────────────────────────────────────────
// F030-3 — model-level guard: direct Box save with cross-tenant parent throws DomainException
// ─────────────────────────────────────────────────────────────────────────────

it('F030-3: direct Box::save with cross-tenant parent_box_id throws DomainException', function (): void {
    $repoA = rft_repo('F030D');
    $repoB = rft_repo('F030E');
    $u = rft_sa($repoA->id);
    $this->actingAs($u);

    $batchA = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 12,
        'repository_id' => $repoA->id,
        'type' => 'MAIN_COLLECTION',
        'is_active' => true,
    ]);
    $batchB = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 13,
        'repository_id' => $repoB->id,
        'type' => 'MAIN_COLLECTION',
        'is_active' => true,
    ]);
    $parentRAS = Box::withoutGlobalScopes()->create([
        'batch_id' => $batchB->id,
        'box_number' => 'RAS-B-GUARD',
        'barcode' => 'BC-F030-GUARD',
        'box_type' => 'RAS',
    ]);
    // IN_SITU boxes require a Location — create one for repo A.
    $locationA = Location::factory()->create(['repository_id' => $repoA->id, 'is_active' => true]);

    // Direct save: cross-tenant parent must throw DomainException.
    expect(fn () => Box::withoutGlobalScopes()->create([
        'batch_id' => $batchA->id,
        'box_number' => 'IS-F030-GUARD',
        'box_type' => 'IN_SITU',
        'parent_box_id' => $parentRAS->id,
        'location_id' => $locationA->id,
    ]))->toThrow(DomainException::class);

    // Same-tenant must pass.
    $parentSameTenant = Box::withoutGlobalScopes()->create([
        'batch_id' => $batchA->id,
        'box_number' => 'RAS-A-GUARD',
        'barcode' => 'BC-F030-SAME-GUARD',
        'box_type' => 'RAS',
    ]);

    $box = Box::withoutGlobalScopes()->create([
        'batch_id' => $batchA->id,
        'box_number' => 'IS-F030-SAME-GUARD',
        'box_type' => 'IN_SITU',
        'parent_box_id' => $parentSameTenant->id,
        'location_id' => $locationA->id,
    ]);

    expect($box->exists)->toBeTrue();
    expect($box->parent_box_id)->toBe($parentSameTenant->id);
});
