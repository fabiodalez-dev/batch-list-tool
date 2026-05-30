<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

/**
 * Build a shared Repository + Batch context for each test.
 *
 * @return array{0: Repository, 1: Batch}
 */
function w2t2_ctx(): array
{
    $repo = Repository::factory()->create();
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 10,
        'repository_id' => $repo->id,
        'type' => 'MAIN_COLLECTION',
    ]);

    return [$repo, $batch];
}

it('rejects an In-Situ box with no RAS parent and no exception flag', function () {
    [$r, $b] = w2t2_ctx();

    Box::create([
        'box_type' => 'IN_SITU',
        'box_number' => 1,
        'batch_id' => $b->id,
        'provenance_unknown' => false,
    ]);
})->throws(DomainException::class);

it('allows an In-Situ box with no RAS parent when provenance_unknown=true', function () {
    [$r, $b] = w2t2_ctx();

    $box = Box::create([
        'box_type' => 'IN_SITU',
        'box_number' => 2,
        'batch_id' => $b->id,
        'provenance_unknown' => true,
    ]);

    expect($box->exists)->toBeTrue();
});

it('allows an In-Situ box with a valid RAS parent regardless of provenance_unknown', function () {
    [$r, $b] = w2t2_ctx();

    $ras = Box::create([
        'box_type' => 'RAS',
        'box_number' => 3,
        'batch_id' => $b->id,
    ]);

    $box = Box::create([
        'box_type' => 'IN_SITU',
        'box_number' => 4,
        'batch_id' => $b->id,
        'parent_box_id' => $ras->id,
    ]);

    expect($box->exists)->toBeTrue();
});
