<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

function bsrt_box(string $status = 'IN', ?string $disinfestation = null): Box
{
    $repo = Repository::factory()->create();
    $batch = Batch::factory()->create(['repository_id' => $repo->id]);

    return Box::factory()->create([
        'batch_id' => $batch->id,
        'box_type' => 'RAS',
        'barcode_status' => $status,
        'disinfestation_date' => $disinfestation,
        'barcode' => 'BC-RT-' . strtoupper(substr(uniqid(), -6)),
    ]);
}

it('rejects a PERM_OUT -> OUT transition (Option A — PERM_OUT is terminal)', function (): void {
    actingAs(User::factory()->create()->assignRole('super_admin'));

    $box = bsrt_box('PERM_OUT', '2026-01-01');

    expect(fn () => $box->update(['barcode_status' => 'OUT']))
        ->toThrow(ValidationException::class);

    expect(Box::find($box->id)->barcode_status)->toBe('PERM_OUT');
});

it('makes the PERM_OUT -> IN new-barcode rule un-bypassable via a round-trip', function (): void {
    actingAs(User::factory()->create()->assignRole('super_admin'));

    $box = bsrt_box('PERM_OUT', '2026-01-01');
    $archivedBarcode = $box->barcode;

    // Round-trip attempt PERM_OUT -> OUT is blocked, so the only way back to IN
    // is a direct PERM_OUT -> IN, which mandates a NEW (different) barcode.
    expect(fn () => $box->update(['barcode_status' => 'OUT']))
        ->toThrow(ValidationException::class);

    // Direct PERM_OUT -> IN reusing the archived barcode is rejected.
    $box->refresh();
    expect(fn () => $box->update(['barcode_status' => 'IN', 'barcode' => $archivedBarcode]))
        ->toThrow(ValidationException::class);

    // PERM_OUT -> IN with a fresh barcode succeeds.
    $box->refresh();
    $box->update(['barcode_status' => 'IN', 'barcode' => 'BC-FRESH-9']);
    expect(Box::find($box->id)->barcode_status)->toBe('IN');
});

it('still allows a plain OUT -> IN (disinfestation round-trip) with the same barcode', function (): void {
    actingAs(User::factory()->create()->assignRole('super_admin'));

    $box = bsrt_box('IN');
    $originalBarcode = $box->barcode;

    // IN -> OUT (sent to disinfestation), then OUT -> IN (returned). The
    // barcode stays the same — this is NOT a re-entry from PERM_OUT.
    $box->update(['barcode_status' => 'OUT']);
    $box->refresh();
    $box->update(['barcode_status' => 'IN']);

    $fresh = Box::find($box->id);
    expect($fresh->barcode_status)->toBe('IN');
    expect($fresh->barcode)->toBe($originalBarcode);
});
