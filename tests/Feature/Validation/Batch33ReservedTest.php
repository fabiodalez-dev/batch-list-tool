<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

it('allows creating batch 33 (reserved for old MAV boxes)', function () {
    $repo = Repository::factory()->create();

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 33,
        'type' => 'MAIN_COLLECTION',
        'repository_id' => $repo->id,
        'is_active' => true,
    ]);

    expect($batch->exists)->toBeTrue()
        ->and($batch->isForbidden())->toBeFalse()
        ->and($batch->isReservedMav())->toBeTrue();
});

it('still forbids batch 34 and 36', function () {
    expect(in_array(34, Batch::FORBIDDEN_NUMBERS, true))->toBeTrue()
        ->and(in_array(36, Batch::FORBIDDEN_NUMBERS, true))->toBeTrue()
        ->and(in_array(33, Batch::FORBIDDEN_NUMBERS, true))->toBeFalse();
});

it('batch 33 isReservedMav() returns true and isForbidden() returns false', function () {
    $batch = new Batch(['batch_number' => 33]);
    expect($batch->isReservedMav())->toBeTrue()
        ->and($batch->isForbidden())->toBeFalse();
});

it('batch 34 isForbidden() returns true and isReservedMav() returns false', function () {
    $batch = new Batch(['batch_number' => 34]);
    expect($batch->isForbidden())->toBeTrue()
        ->and($batch->isReservedMav())->toBeFalse();
});
