<?php

declare(strict_types=1);

use App\Filament\Actions\Boxes\MoveBoxToLocationAction;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Location;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * C1 — MoveBoxToLocationAction must reject a forged submit pointing at an
 * INACTIVE location, mirroring the form filter (is_active = true only). The
 * server-side guard is the unit under test, so we invoke the action's own
 * closure directly (bypassing the Livewire table plumbing) with a crafted
 * data payload — exactly the "forged submit" an attacker would send.
 */
beforeEach(function () {
    bl_seedShieldPermissions();
});

function moveBoxActor(): User
{
    $user = User::factory()->create(['default_repository_id' => Repository::factory()->create()->id]);
    $user->assignRole('super_admin'); // bypass tenancy; the guard under test is is_active
    actingAs($user);

    return $user;
}

/** Invoke the action's registered closure against $box with the given form data. */
function invokeMoveBox(Box $box, array $data): void
{
    $action = MoveBoxToLocationAction::make();
    $closure = (new ReflectionObject($action))->getMethod('getActionFunction')->invoke($action);

    // The action closure is declared as fn (Box $record, array $data); call it
    // POSITIONALLY (the container's named-arg mapping does not bind to a raw
    // Closure's parameters).
    $closure($box, $data);
}

it('C1: rejects moving a box to an inactive location (forged submit)', function () {
    $user = moveBoxActor();

    $batch = Batch::factory()->create(['repository_id' => $user->default_repository_id]);
    $box = Box::factory()->create(['batch_id' => $batch->id]);

    $inactive = Location::factory()->create([
        'repository_id' => $user->default_repository_id,
        'is_active' => false,
    ]);

    invokeMoveBox($box, ['to_location_id' => $inactive->id]);

    expect($box->refresh()->location_id)->toBeNull();
});

it('C1: still allows moving a box to an ACTIVE location', function () {
    $user = moveBoxActor();

    $batch = Batch::factory()->create(['repository_id' => $user->default_repository_id]);
    $box = Box::factory()->create(['batch_id' => $batch->id]);

    $active = Location::factory()->create([
        'repository_id' => $user->default_repository_id,
        'is_active' => true,
    ]);

    invokeMoveBox($box, ['to_location_id' => $active->id]);

    expect($box->refresh()->location_id)->toBe($active->id);
});
