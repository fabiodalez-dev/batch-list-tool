<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\Location;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

/**
 * @return array{0: Repository, 1: Batch, 2: Location}
 */
function bmg_ctx(): array
{
    $repo = Repository::factory()->create();
    $batch = Batch::factory()->create(['repository_id' => $repo->id]);
    $location = Location::factory()->create(['repository_id' => $repo->id, 'is_active' => true]);

    return [$repo, $batch, $location];
}

it('rejects creating an IN_SITU box with no location (F5)', function (): void {
    actingAs(User::factory()->create()->assignRole('super_admin'));
    [$repo, $batch, $location] = bmg_ctx();

    $ras = Box::create([
        'box_type' => 'RAS',
        'box_number' => 'RAS-G1',
        'batch_id' => $batch->id,
        'barcode' => 'BC-G-RAS-1',
    ]);

    expect(fn () => Box::create([
        'box_type' => 'IN_SITU',
        'box_number' => 'IS-G1',
        'batch_id' => $batch->id,
        'parent_box_id' => $ras->id,
        'location_id' => null,
    ]))->toThrow(ValidationException::class);
});

it('rejects creating a RAS box with no barcode (F5)', function (): void {
    actingAs(User::factory()->create()->assignRole('super_admin'));
    [$repo, $batch] = bmg_ctx();

    expect(fn () => Box::create([
        'box_type' => 'RAS',
        'box_number' => 'RAS-G2',
        'batch_id' => $batch->id,
        'barcode' => null,
    ]))->toThrow(ValidationException::class);
});

it('allows a valid RAS box (barcode present) and a valid IN_SITU box (location present)', function (): void {
    actingAs(User::factory()->create()->assignRole('super_admin'));
    [$repo, $batch, $location] = bmg_ctx();

    $ras = Box::create([
        'box_type' => 'RAS',
        'box_number' => 'RAS-G3',
        'batch_id' => $batch->id,
        'barcode' => 'BC-G-RAS-3',
    ]);
    expect($ras->exists)->toBeTrue();

    $inSitu = Box::create([
        'box_type' => 'IN_SITU',
        'box_number' => 'IS-G3',
        'batch_id' => $batch->id,
        'parent_box_id' => $ras->id,
        'location_id' => $location->id,
    ]);
    expect($inSitu->exists)->toBeTrue();
});

it('does NOT retro-break an existing legacy MAV/STVC row on an unrelated re-save (F5)', function (): void {
    actingAs(User::factory()->create()->assignRole('super_admin'));
    [$repo, $batch] = bmg_ctx();

    // Seed a legacy MAV box the way the importer would: legacy flag + barcode.
    $mav = Box::factory()->create([
        'box_type' => 'MAV',
        'box_number' => 'MAV-1',
        'batch_id' => $batch->id,
        'is_legacy' => true,
        'barcode' => 'BC-MAV-1',
    ]);

    // An unrelated update (notes) must not re-trigger the structural guard.
    $mav->update(['notes' => 'audited later']);
    expect(Box::find($mav->id)->notes)->toBe('audited later');

    // A legacy STVC row with a barcode also re-saves on an unrelated change.
    $stvc = Box::factory()->create([
        'box_type' => 'STVC',
        'box_number' => 'STVC-1',
        'batch_id' => $batch->id,
        'is_legacy' => true,
        'barcode' => 'BC-STVC-1',
    ]);
    $stvc->update(['notes' => 'still here']);
    expect(Box::find($stvc->id)->notes)->toBe('still here');
});
