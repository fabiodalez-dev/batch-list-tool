<?php

declare(strict_types=1);

use App\Filament\Imports\BatchImporter;
use App\Models\Batch;
use App\Models\Repository;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * NAF Feedback-1 re-verification (comment #3): the operator imported a file,
 * deleted some of the created records, then re-imported the SAME file and it
 * "did not import at all" / "all rows fail".
 *
 * Root cause: Batch uses SoftDeletes, so a deleted batch leaves a row with the
 * same (batch_number, repository_id) behind. The unique index covers
 * soft-deleted rows, so re-inserting the same number raises a SQL duplicate-key
 * error that surfaces as an opaque failed row. The importer must resolve the
 * soft-deleted record and restore it (idempotent un-delete) instead of trying
 * to INSERT a colliding new row.
 */
uses(RefreshDatabase::class);

function rad_makeAdmin(?int $repoId = null): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    /** @var User $u */
    $u = User::factory()->create([
        'email' => 'rad-admin+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repoId,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * @param array<string, mixed> $data
 * @param array<string, mixed> $options
 */
function rad_runImporter(array $data, int $userId, array $options = []): object
{
    EntityResolver::flushMemo();
    /** @var Import $row */
    $row = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'reimport.xlsx',
        'file_path' => '/tmp/reimport.xlsx',
        'importer' => BatchImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);
    $columnMap = array_combine(array_keys($data), array_keys($data));
    $importer = new BatchImporter($row, $columnMap, $options);
    $importer($data);

    return $importer;
}

test('re-importing a soft-deleted batch restores it instead of failing on a unique collision', function () {
    $repo = Repository::create(['code' => 'RAD', 'name' => 'Reimport Repo']);
    $u = rad_makeAdmin($repo->id);
    $this->actingAs($u);

    // 1) First import — batch 777 created.
    rad_runImporter(['batch_number' => 777, 'description' => 'First'], $u->id);
    $first = Batch::query()->where('batch_number', 777)->firstOrFail();

    // 2) Operator deletes the imported record (soft delete).
    $first->delete();
    expect(Batch::query()->where('batch_number', 777)->exists())->toBeFalse();
    expect(Batch::withTrashed()->where('batch_number', 777)->count())->toBe(1);

    // 3) Re-import the SAME row — must succeed and bring the batch back, with no
    //    duplicate-key collision and no duplicate row.
    rad_runImporter(['batch_number' => 777, 'description' => 'Second'], $u->id);

    expect(Batch::withTrashed()->where('batch_number', 777)->count())->toBe(1);
    $restored = Batch::query()->where('batch_number', 777)->first();
    expect($restored)->not->toBeNull();
    expect($restored->description)->toBe('Second');
});
