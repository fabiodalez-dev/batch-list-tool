<?php

declare(strict_types=1);

use App\Filament\Actions\Documents\MarkPermOutAction;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/**
 * C2 — a bulk PERM_OUT over several documents in the SAME box with DIFFERENT
 * disinfestation dates must seed the box with a DETERMINISTIC date (the latest),
 * not whichever doc happens to be iterated first.
 */
beforeEach(function () {
    bl_seedShieldPermissions();
});

function permout_actor(): User
{
    // super_admin bypasses the BelongsToRepository tenant guard, so the test
    // can stand up batch/box/documents in one repository without wiring the
    // full pivot membership — the behaviour under test is the date selection,
    // not tenancy.
    $repo = Repository::factory()->create();
    $user = User::factory()->create(['default_repository_id' => $repo->id]);
    $user->assignRole('super_admin');

    return $user;
}

function runPermOutBulk(EloquentCollection $records): void
{
    $m = new ReflectionMethod(MarkPermOutAction::class, 'perform');
    $m->setAccessible(true);
    $m->invoke(null, $records);
}

/**
 * @param '2024-01-01'|'2024-12-31'|string $order which doc id leads the collection
 */
function permout_runWithOrder(string $sort): Box
{
    $user = permout_actor();
    actingAs($user);
    $repoId = $user->default_repository_id;

    $batch = Batch::factory()->create(['repository_id' => $repoId]);
    $box = Box::factory()->create(['batch_id' => $batch->id, 'barcode_status' => 'IN']);

    $early = Document::factory()->create([
        'repository_id' => $repoId,
        'current_box_id' => $box->id,
        'disinfestation_date' => '2024-01-01',
    ]);
    $late = Document::factory()->create([
        'repository_id' => $repoId,
        'current_box_id' => $box->id,
        'disinfestation_date' => '2024-12-31',
    ]);

    // Reload each doc fresh (so disinfestation_date is the hydrated Carbon cast,
    // mirroring how the real bulk action receives records) and assemble the
    // collection in the requested ORDER so we prove order-independence.
    $earlyFresh = Document::findOrFail($early->id);
    $lateFresh = Document::findOrFail($late->id);
    $records = $sort === 'asc'
        ? new EloquentCollection([$earlyFresh, $lateFresh])
        : new EloquentCollection([$lateFresh, $earlyFresh]);

    runPermOutBulk($records);

    return $box->refresh();
}

it('C2: box inherits the MAX disinfestation date when the earliest doc leads', function () {
    $box = permout_runWithOrder('asc');

    expect($box->barcode_status)->toBe('PERM_OUT')
        ->and($box->disinfestation_date->toDateString())->toBe('2024-12-31');
});

it('C2: result is identical when the latest doc leads (deterministic, order-independent)', function () {
    $box = permout_runWithOrder('desc');

    expect($box->barcode_status)->toBe('PERM_OUT')
        ->and($box->disinfestation_date->toDateString())->toBe('2024-12-31');
});
