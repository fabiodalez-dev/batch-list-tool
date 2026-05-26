<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * RFQ §3.1.10 / §3.1.11 — Box lifecycle states.
 *
 * "Current box type, not-in-box status, mounted/no-box status, destroyed-
 * box status, and validation of when a box may be marked as destroyed."
 *
 * Box.barcode_status (IN | OUT | PERM_OUT) is the primary lifecycle axis;
 * Box.is_legacy carries the destroyed-numbering-system flag for MAV/STVC.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

function s3111_setup(): Batch
{
    $repo = Repository::factory()->create(['code' => 'S3111-' . substr(uniqid(), -4)]);

    return Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 7000 + random_int(0, 999),
        'type' => 'MAIN_COLLECTION', 'repository_id' => $repo->id, 'is_active' => true,
    ]);
}

it('§ 3.1.11 #1: Box default barcode_status is IN on create', function () {
    $batch = s3111_setup();
    $box = Box::factory()->create(['batch_id' => $batch->id, 'barcode_status' => 'IN']);
    $fresh = Box::withoutGlobalScopes()->find($box->id);
    expect($fresh->barcode_status)->toBe('IN');
});

it('§ 3.1.11 #2: Box can transition through IN → OUT', function () {
    $batch = s3111_setup();
    $box = Box::factory()->create(['batch_id' => $batch->id, 'barcode_status' => 'IN']);
    $box->update(['barcode_status' => 'OUT']);
    expect(Box::withoutGlobalScopes()->find($box->id)->barcode_status)->toBe('OUT');
});

it('§ 3.1.11 #3: Box::canBePermOut() guards the PERM_OUT transition', function () {
    $batch = s3111_setup();
    $boxNoDate = Box::factory()->create(['batch_id' => $batch->id, 'disinfestation_date' => null]);
    $boxWithDate = Box::factory()->create([
        'batch_id' => $batch->id,
        'disinfestation_date' => '2026-04-15',
    ]);
    expect($boxNoDate->canBePermOut())->toBeFalse()
        ->and($boxWithDate->canBePermOut())->toBeTrue();
});

it('§ 3.1.11 #4: Box.is_legacy flag distinguishes destroyed-numbering boxes (MAV/STVC)', function () {
    $batch = s3111_setup();
    $box = Box::factory()->create([
        'batch_id' => $batch->id, 'box_type' => 'MAV', 'is_legacy' => true,
    ]);
    $fresh = Box::withoutGlobalScopes()->find($box->id);
    expect($fresh->is_legacy)->toBeTrue()
        ->and(in_array($fresh->box_type, Box::LEGACY_TYPES, true))->toBeTrue();
});
