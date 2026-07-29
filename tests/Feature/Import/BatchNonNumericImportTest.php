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
 * Charlene's feedback (2026-07-28): the archive has two catch-all batches whose
 * batch_number is NOT a number — "Unknown" (Documents with unknown origin) and
 * "NULL" (Documents never packed in boxes). They failed to import while
 * batch_number was an integer column. batch_number is now a short string, so
 * these import and are matched idempotently exactly like numeric batches.
 *
 * These rows are taken verbatim from the client's real batch import file
 * (…_081423_efbbaeef.csv):
 *   "Unknown","Documents with unknown origin","MAIN_COLLECTION","","NRA"
 *   "NULL","Documents never packed in boxes","MAIN_COLLECTION","","NRA"
 */
uses(RefreshDatabase::class);

function bnn_admin(int $repoId): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    /** @var User $u */
    $u = User::factory()->create([
        'email' => 'bnn+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repoId,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * @param array<string, mixed> $data
 */
function bnn_import(array $data, int $userId): void
{
    EntityResolver::flushMemo();
    /** @var Import $row */
    $row = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'batch.csv',
        'file_path' => '/tmp/batch.csv',
        'importer' => BatchImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);
    $map = array_combine(array_keys($data), array_keys($data));
    (new BatchImporter($row, $map, []))($data);
}

test('the client\'s "Unknown" and "NULL" catch-all batches import as real batches', function () {
    $repo = Repository::create(['code' => 'NRA', 'name' => 'National Records Archive']);
    $u = bnn_admin($repo->id);
    $this->actingAs($u);

    bnn_import([
        'batch_number' => 'Unknown',
        'description' => 'Documents with unknown origin',
        'type' => 'MAIN_COLLECTION',
        'repository_code' => 'NRA',
    ], $u->id);

    bnn_import([
        'batch_number' => 'NULL',
        'description' => 'Documents never packed in boxes',
        'type' => 'MAIN_COLLECTION',
        'repository_code' => 'NRA',
    ], $u->id);

    $batches = Batch::withoutGlobalScope(RepositoryScope::class)->get();
    expect($batches->pluck('batch_number')->sort()->values()->all())
        ->toBe(['NULL', 'Unknown']);

    $unknown = $batches->firstWhere('batch_number', 'Unknown');
    expect($unknown->description)->toBe('Documents with unknown origin')
        ->and($unknown->type)->toBe('MAIN_COLLECTION')
        ->and($unknown->isForbidden())->toBeFalse()
        ->and($unknown->isReservedMav())->toBeFalse()
        ->and($unknown->isWillsOnly())->toBeFalse();
});

test('re-importing a non-numeric batch is idempotent (matches the existing row by its string number)', function () {
    $repo = Repository::create(['code' => 'NRA', 'name' => 'National Records Archive']);
    $u = bnn_admin($repo->id);
    $this->actingAs($u);

    bnn_import(['batch_number' => 'Unknown', 'description' => 'first', 'repository_code' => 'NRA'], $u->id);
    bnn_import(['batch_number' => 'Unknown', 'description' => 'second', 'repository_code' => 'NRA'], $u->id);

    $rows = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 'Unknown')->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->description)->toBe('second'); // updated in place, not duplicated
});

test('a numeric batch still imports and keeps its reserved-number semantics alongside string batches', function () {
    $repo = Repository::create(['code' => 'NRA', 'name' => 'National Records Archive']);
    $u = bnn_admin($repo->id);
    $this->actingAs($u);

    bnn_import(['batch_number' => 'Unknown', 'repository_code' => 'NRA'], $u->id);
    bnn_import(['batch_number' => '50', 'repository_code' => 'NRA'], $u->id); // WILLS_BATCH

    $wills = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', '50')->first();
    expect($wills)->not->toBeNull()
        ->and($wills->isWillsOnly())->toBeTrue()      // (int) "50" === WILLS_BATCH
        ->and($wills->type)->toBe('NOTARY_ACCESSION'); // afterFill: numeric >= 30
});

test('a non-numeric batch is matched within its OWN repository (no cross-tenant steal)', function () {
    $repoA = Repository::create(['code' => 'AAA', 'name' => 'Repo A']);
    $repoB = Repository::create(['code' => 'BBB', 'name' => 'Repo B']);

    // Repo A already owns an "Unknown" batch.
    $batchA = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 'Unknown',
        'repository_id' => $repoA->id,
        'description' => 'A-owned',
    ]);

    // An operator whose default repository is B imports "Unknown" (no explicit
    // repository_code → falls back to B). It must create B's own row, never
    // match/steal A's.
    $userB = bnn_admin($repoB->id);
    $this->actingAs($userB);
    bnn_import(['batch_number' => 'Unknown', 'description' => 'B-owned'], $userB->id);

    $batchA->refresh();
    expect($batchA->repository_id)->toBe($repoA->id)     // untouched
        ->and($batchA->description)->toBe('A-owned');

    $all = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 'Unknown')->get();
    expect($all)->toHaveCount(2)
        ->and($all->pluck('repository_id')->sort()->values()->all())
        ->toBe(collect([$repoA->id, $repoB->id])->sort()->values()->all());

    // Re-importing "Unknown" for B again updates only B's row (idempotent).
    bnn_import(['batch_number' => 'Unknown', 'description' => 'B-updated'], $userB->id);
    expect(Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 'Unknown')->count())->toBe(2);
    $batchA->refresh();
    expect($batchA->description)->toBe('A-owned'); // A still untouched
    expect(Batch::withoutGlobalScope(RepositoryScope::class)
        ->where('batch_number', 'Unknown')->where('repository_id', $repoB->id)->value('description'))
        ->toBe('B-updated');
});

test('a forbidden numeric batch (34 / 36) fails validation on import and creates no row', function () {
    $repo = Repository::create(['code' => 'NRA', 'name' => 'National Records Archive']);
    $u = bnn_admin($repo->id);
    $this->actingAs($u);

    foreach (['34', '36'] as $forbidden) {
        try {
            bnn_import(['batch_number' => $forbidden, 'repository_code' => 'NRA'], $u->id);
        } catch (Throwable) {
            // Validation rejects the forbidden number — expected.
        }
        expect(Batch::withoutGlobalScope(RepositoryScope::class)->withTrashed()
            ->where('batch_number', $forbidden)->exists())->toBeFalse();
    }
});

test('the model treats numeric-string reserved numbers correctly (34/36 forbidden, 33 MAV, 50 wills)', function () {
    expect((new Batch(['batch_number' => '34']))->isForbidden())->toBeTrue()
        ->and((new Batch(['batch_number' => '36']))->isForbidden())->toBeTrue()
        ->and((new Batch(['batch_number' => '33']))->isForbidden())->toBeFalse()
        ->and((new Batch(['batch_number' => '33']))->isReservedMav())->toBeTrue()
        ->and((new Batch(['batch_number' => '50']))->isWillsOnly())->toBeTrue()
        // Non-numeric labels are never reserved.
        ->and((new Batch(['batch_number' => 'Unknown']))->isForbidden())->toBeFalse()
        ->and((new Batch(['batch_number' => 'NULL']))->isReservedMav())->toBeFalse();
});
