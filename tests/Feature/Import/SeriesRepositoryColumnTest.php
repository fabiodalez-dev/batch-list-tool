<?php

declare(strict_types=1);

use App\Filament\Imports\SeriesImporter;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * Client request (2026-07-27): the Series import always created GLOBAL series
 * (repository_id null) because the importer had no Repository column. An
 * optional `repository_code` column now lets an operator assign each series to
 * a specific archive; blank keeps the historical GLOBAL default. Mirrors
 * BatchImporter / LocationImporter.
 */
uses(RefreshDatabase::class);

function src_admin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['is_active' => true]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * @param array<string, mixed> $data
 */
function src_import(array $data, int $userId): void
{
    EntityResolver::flushMemo();
    /** @var Import $row */
    $row = Import::query()->create([
        'completed_at' => null,
        'file_name' => 's.xlsx',
        'file_path' => '/tmp/s.xlsx',
        'importer' => SeriesImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);
    $map = array_combine(array_keys($data), array_keys($data));
    (new SeriesImporter($row, $map, []))($data);
}

test('a Repository code on the row assigns the series to that repository', function () {
    $u = src_admin();
    $this->actingAs($u);
    $repo = Repository::factory()->create(['code' => 'NRA']);

    src_import(['code' => 'R', 'title' => 'Register Copies', 'repository_code' => 'NRA'], $u->id);

    $series = Series::where('code', 'R')->firstOrFail();
    expect($series->repository_id)->toBe($repo->id);
});

test('a blank Repository keeps the series GLOBAL (repository_id null) — historical default', function () {
    $u = src_admin();
    $this->actingAs($u);
    Repository::factory()->create(['code' => 'NRA']);

    src_import(['code' => 'REG', 'title' => 'Registers', 'repository_code' => ''], $u->id);

    $series = Series::where('code', 'REG')->firstOrFail();
    expect($series->repository_id)->toBeNull();
});

test('omitting the Repository column entirely keeps the series GLOBAL', function () {
    $u = src_admin();
    $this->actingAs($u);

    src_import(['code' => 'RWL', 'title' => 'Wills'], $u->id);

    $series = Series::where('code', 'RWL')->firstOrFail();
    expect($series->repository_id)->toBeNull();
});
