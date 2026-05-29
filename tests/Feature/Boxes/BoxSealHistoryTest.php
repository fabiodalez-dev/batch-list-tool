<?php

use App\Models\Batch;
use App\Models\Box;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();

    $this->repo = Repository::factory()->create();
    // Batch 50 is the wills reserve — the seal-number chain-of-custody matters
    // most here (RFQ Contract App.2-i), so we anchor the fixture on it.
    $this->batch = Batch::create([
        'batch_number' => 50,
        'repository_id' => $this->repo->id,
        'type' => 'NOTARY_ACCESSION',
    ]);
});

it('records seal history when a box seal number is first set', function () {
    $box = Box::create([
        'box_type' => 'RAS',
        'box_number' => 1,
        'batch_id' => $this->batch->id,
        'seal_number' => 'S-100',
    ]);

    expect($box->sealNumberHistory()->count())->toBe(1)
        ->and($box->sealNumberHistory()->first()->new_value)->toBe('S-100')
        ->and($box->sealNumberHistory()->first()->old_value)->toBeNull();
});

it('records a new history row when the seal number changes', function () {
    $box = Box::create([
        'box_type' => 'RAS',
        'box_number' => 2,
        'batch_id' => $this->batch->id,
        'seal_number' => 'S-1',
    ]);

    $box->update(['seal_number' => 'S-2']);

    $hist = $box->sealNumberHistory()->orderBy('id')->get();

    expect($hist)->toHaveCount(2)
        ->and($hist->last()->old_value)->toBe('S-1')
        ->and($hist->last()->new_value)->toBe('S-2');
});

it('does NOT record a history row when the seal number is unchanged', function () {
    $box = Box::create([
        'box_type' => 'RAS',
        'box_number' => 3,
        'batch_id' => $this->batch->id,
        'seal_number' => 'S-9',
    ]);

    $box->update(['box_number' => 4]); // unrelated change

    expect($box->sealNumberHistory()->count())->toBe(1);
});

it('does NOT record history when a box is created without a seal number', function () {
    $box = Box::create([
        'box_type' => 'RAS',
        'box_number' => 5,
        'batch_id' => $this->batch->id,
    ]);

    expect($box->sealNumberHistory()->count())->toBe(0);
});
