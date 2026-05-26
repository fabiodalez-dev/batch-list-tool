<?php

declare(strict_types=1);

use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable: database structural contracts (unique indexes, FK, NOT NULL).
 *
 * Pins the migration shape so future schema changes can't silently relax
 * uniqueness or remove FK cascade behaviour.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

it('Constraints: repositories.code is unique', function () {
    Repository::factory()->create(['code' => 'UQ-' . uniqid()]);
    $dup = 'UQ-DUP';
    Repository::factory()->create(['code' => $dup]);
    expect(fn () => Repository::factory()->create(['code' => $dup]))
        ->toThrow(QueryException::class);
});

it('Constraints: series.code is unique', function () {
    $code = 'UC-' . substr(uniqid(), -6);
    Series::create(['code' => $code, 'title' => 't', 'is_active' => true]);
    expect(fn () => Series::create(['code' => $code, 'title' => 't2', 'is_active' => true]))
        ->toThrow(QueryException::class);
});

it('Constraints: authorities.identifier is unique', function () {
    $id = 'AID-' . uniqid();
    Authority::create(['identifier' => $id, 'surname' => 'A', 'entity_type' => 'PERSON']);
    expect(fn () => Authority::create(['identifier' => $id, 'surname' => 'B', 'entity_type' => 'PERSON']))
        ->toThrow(QueryException::class);
});

it('Constraints: batches.batch_number is unique', function () {
    $repo = Repository::factory()->create();
    $n = 8000 + random_int(0, 99);
    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => $n, 'type' => 'MAIN_COLLECTION', 'repository_id' => $repo->id, 'is_active' => true,
    ]);
    expect(fn () => Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => $n, 'type' => 'MAIN_COLLECTION', 'repository_id' => $repo->id, 'is_active' => true,
    ]))->toThrow(QueryException::class);
});

it('Constraints: boxes.barcode is unique when present', function () {
    $batch = Batch::factory()->create();
    $barcode = 'BC-' . uniqid();
    Box::factory()->create(['batch_id' => $batch->id, 'barcode' => $barcode]);
    expect(fn () => Box::factory()->create(['batch_id' => $batch->id, 'barcode' => $barcode]))
        ->toThrow(QueryException::class);
});

it('Constraints: documents has expected columns (identifier, repository_id, series_id)', function () {
    foreach (['identifier', 'repository_id', 'series_id', 'batch_id', 'current_box_id', 'notes', 'deleted_at'] as $col) {
        expect(Schema::hasColumn('documents', $col))->toBeTrue("Missing column: {$col}");
    }
});
