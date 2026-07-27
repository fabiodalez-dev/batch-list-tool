<?php

declare(strict_types=1);

use App\Filament\Imports\AuthorityImporter;
use App\Filament\Imports\LocationImporter;
use App\Filament\Imports\SeriesImporter;
use App\Models\Authority;
use App\Models\Location;
use App\Models\Series;
use App\Models\User;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
