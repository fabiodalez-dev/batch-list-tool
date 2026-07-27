<?php

declare(strict_types=1);

use App\Filament\Imports\AuthorityImporter;
use App\Filament\Imports\BoxImporter;
use App\Filament\Imports\LocationImporter;
use App\Filament\Imports\SeriesImporter;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Production incident (2026-07-27): the client's Series import failed every row
 * with an opaque "generic_validation" error. Root cause: all 31 Series rows were
 * soft-deleted, and SeriesImporter::resolveRecord() matched with the default
 * (soft-delete-aware) query, so it never found them and tried to INSERT — which
 * collided with the GLOBAL `series_code_unique` index (it counts trashed rows),
 * raising SQLSTATE[23000] 1062 on every row. The streaming importer masked that
 * SQL error behind the generic message.
 *
 * The fix: resolveRecord() must match withTrashed() on the unique natural key
 * and RESTORE a soft-deleted match (idempotent un-delete) instead of colliding.
 * Authority (identifier) and Location (repository_id, code / name) shared the
 * same latent bug. This pins all three.
 */
uses(RefreshDatabase::class);

function rrs_admin(?int $repoId = null): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    /** @var User $u */
    $u = User::factory()->create([
        'email' => 'rrs+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repoId,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * @param class-string<Importer> $importer
 * @param array<string, mixed> $data
 */
function rrs_import(string $importer, array $data, int $userId): void
{
    /** @var Import $row */
    $row = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'reimport.xlsx',
        'file_path' => '/tmp/reimport.xlsx',
        'importer' => $importer,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);
    $map = array_combine(array_keys($data), array_keys($data));
    (new $importer($row, $map, []))($data);
}

test('Series: re-importing a soft-deleted code restores it instead of colliding on series_code_unique', function () {
    $u = rrs_admin();
    $this->actingAs($u);

    $series = Series::create(['code' => 'RWL', 'title' => 'Old title', 'is_active' => true]);
    $series->delete();
    expect(Series::whereKey($series->id)->exists())->toBeFalse()           // hidden by soft-delete scope
        ->and(Series::withTrashed()->whereKey($series->id)->exists())->toBeTrue();

    // Re-import the same code — must NOT throw a unique violation.
    rrs_import(SeriesImporter::class, ['code' => 'RWL', 'title' => 'New title'], $u->id);

    $fresh = Series::withTrashed()->where('code', 'RWL')->get();
    expect($fresh)->toHaveCount(1);                                        // restored, not duplicated
    expect($fresh->first()->trashed())->toBeFalse()                        // un-deleted
        ->and($fresh->first()->title)->toBe('New title')                   // updated
        ->and($fresh->first()->id)->toBe($series->id);                     // same row
});

test('Authority: re-importing a soft-deleted identifier restores it instead of colliding', function () {
    $u = rrs_admin();
    $this->actingAs($u);

    $authority = Authority::create(['identifier' => 'R642', 'surname' => 'Caruana', 'entity_type' => 'Notary']);
    $authority->delete();

    rrs_import(AuthorityImporter::class, ['identifier' => 'R642', 'surname' => 'Caruana'], $u->id);

    $fresh = Authority::withTrashed()->where('identifier', 'R642')->get();
    expect($fresh)->toHaveCount(1)
        ->and($fresh->first()->trashed())->toBeFalse()
        ->and($fresh->first()->id)->toBe($authority->id);
});

test('Location: re-importing a soft-deleted location restores it instead of colliding', function () {
    $u = rrs_admin();
    $this->actingAs($u);

    $location = Location::create([
        'name' => 'Deposit A',
        'code' => 'DEP-A',
        'type' => 'room',
        'is_active' => true,
        'repository_id' => null,
        'parent_id' => null,
    ]);
    $location->delete();

    rrs_import(LocationImporter::class, ['name' => 'Deposit A', 'code' => 'DEP-A'], $u->id);

    $fresh = Location::withTrashed()->where('name', 'Deposit A')->get();
    expect($fresh)->toHaveCount(1)
        ->and($fresh->first()->trashed())->toBeFalse()
        ->and($fresh->first()->id)->toBe($location->id);
});

test('Box: re-importing a soft-deleted box (by barcode) restores it instead of colliding', function () {
    $repo = Repository::factory()->create(['code' => 'BXR']);
    $u = rrs_admin($repo->id);
    $this->actingAs($u);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 7,
        'repository_id' => $repo->id,
        'type' => 'MAIN_COLLECTION',
        'is_active' => true,
    ]);
    $box = Box::factory()->create([
        'batch_id' => $batch->id,
        'barcode' => 'BC-RRS-1',
        'box_number' => 'BOX-RRS-1',
        'box_type' => 'RAS',
    ]);
    $box->delete();

    EntityResolver::flushMemo();
    rrs_import(BoxImporter::class, [
        'box_number' => 'BOX-RRS-1',
        'box_type' => 'RAS',
        'batch_number' => 7,
        'barcode' => 'BC-RRS-1',
    ], $u->id);

    $fresh = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->withTrashed()->where('barcode', 'BC-RRS-1')->get();
    expect($fresh)->toHaveCount(1)
        ->and($fresh->first()->trashed())->toBeFalse()
        ->and($fresh->first()->id)->toBe($box->id);
});

test('deferred un-delete: a soft-deleted row that FAILS validation on re-import stays trashed', function () {
    // Pins the CodeRabbit finding: resolveRecord() runs BEFORE validateData(),
    // so the un-delete must NOT be persisted until saveRecord(). A row that
    // matches a trashed record but then fails validation must leave it deleted.
    $u = rrs_admin();
    $this->actingAs($u);

    $series = Series::create(['code' => 'RWL', 'title' => 'Old title', 'is_active' => true]);
    $series->delete();

    // title exceeds max:255 → validateData() throws AFTER resolveRecord() has
    // set deleted_at=null in memory. saveRecord() never runs.
    $tooLong = str_repeat('x', 300);

    try {
        rrs_import(SeriesImporter::class, ['code' => 'RWL', 'title' => $tooLong], $u->id);
        $this->fail('expected a ValidationException');
    } catch (ValidationException) {
        // expected
    }

    // The DB row must still be soft-deleted — no half-applied restore.
    $row = Series::withTrashed()->where('code', 'RWL')->first();
    expect($row)->not->toBeNull()
        ->and($row->trashed())->toBeTrue()
        ->and($row->title)->toBe('Old title');
});
