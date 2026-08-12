<?php

declare(strict_types=1);

use App\Filament\Imports\BoxImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Repository;
use App\Models\User;
use App\Support\ActiveRepository;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

/**
 * Client 2026-08-12 (Charlene): boxes with no batch (IN_SITU / NRA / MAV / STVC
 * / MUS legitimately have none) used to import "successfully" yet stay invisible
 * — tenancy was derived only from batch → repository. A box now carries an OWN
 * repository_id (stamped from the active / import repository when batch-less) so
 * the scope keeps it visible under that archive, while batched boxes are
 * unchanged.
 */
it('shows a batch-less box under its own repository and hides it under another', function (): void {
    actingAs(User::factory()->create()->assignRole('admin'));

    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();

    // A batch-less IN_SITU box (provenance_unknown so no parent RAS is required),
    // stamped with repoA.
    $box = Box::create([
        'box_type' => 'IN_SITU',
        'box_number' => 'NB1',
        'batch_id' => null,
        'provenance_unknown' => true,
        'repository_id' => $repoA->id,
    ]);

    expect($box->batch_id)->toBeNull()
        ->and($box->repository_id)->toBe($repoA->id);

    app(ActiveRepository::class)->set($repoA->id);
    expect(Box::count())->toBe(1);

    app(ActiveRepository::class)->set($repoB->id);
    expect(Box::count())->toBe(0);
});

it('stamps a batch-less box with the active repository on create (form / console path)', function (): void {
    $repoA = Repository::factory()->create();
    actingAs(User::factory()->create(['default_repository_id' => $repoA->id])->assignRole('admin'));
    app(ActiveRepository::class)->set($repoA->id);

    $box = Box::create([
        'box_type' => 'IN_SITU',
        'box_number' => 'NB2',
        'batch_id' => null,
        'provenance_unknown' => true,
    ]);

    expect($box->repository_id)->toBe($repoA->id);
});

it('leaves a batched box repository_id null and keeps batch-derived visibility (no regression)', function (): void {
    actingAs(User::factory()->create()->assignRole('admin'));

    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();
    $batchA = Batch::factory()->create(['repository_id' => $repoA->id]);

    $box = Box::create([
        'box_type' => 'RAS',
        'box_number' => 'RB1',
        'batch_id' => $batchA->id,
        'barcode' => 'BC-RB1',
    ]);

    // A batched box does NOT get its own repository_id — it resolves via batch.
    expect($box->repository_id)->toBeNull();

    app(ActiveRepository::class)->set($repoA->id);
    expect(Box::count())->toBe(1);

    app(ActiveRepository::class)->set($repoB->id);
    expect(Box::count())->toBe(0);
});

it('stamps a batch-less box IMPORT with the import user repository so it is visible', function (): void {
    $repoA = Repository::factory()->create();
    $user = User::factory()->create(['default_repository_id' => $repoA->id]);
    $user->assignRole('admin');
    // Deliberately NOT actingAs — a queued import job runs with no auth() user,
    // so the repository must come from the Import's user, not auth() (CodeRabbit).

    // batch_number is present in the template (so the column is mapped) but left
    // blank — exactly Charlene's file — so batch_id resolves to null.
    $data = ['box_type' => 'IN_SITU', 'box_number' => 'MB1', 'batch_number' => '', 'provenance_unknown' => 'yes'];
    $map = ImportWizard::guessColumnMap(BoxImporter::class, array_keys($data));

    /** @var Import $imp */
    $imp = Import::query()->create([
        'completed_at' => null, 'file_name' => 't.xlsx', 'file_path' => '/tmp/t.xlsx',
        'importer' => BoxImporter::class, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $user->id,
    ]);
    (new BoxImporter($imp, $map, []))($data);

    $box = Box::withoutGlobalScopes()->where('box_number', 'MB1')->first();
    expect($box)->not->toBeNull()
        ->and($box->batch_id)->toBeNull()
        ->and($box->repository_id)->toBe($repoA->id);

    // Visible in the tenant-scoped list under repoA once a user views it.
    actingAs($user);
    app(ActiveRepository::class)->set($repoA->id);
    expect(Box::where('box_number', 'MB1')->exists())->toBeTrue();
});

it('normalises repository_id: null for a batched box, even if one was set', function (): void {
    actingAs(User::factory()->create()->assignRole('admin'));

    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();
    $batchB = Batch::factory()->create(['repository_id' => $repoB->id]);

    // A box created WITH a batch AND an explicit (divergent) own repository_id:
    // the saving hook must force repository_id null so tenancy comes only from
    // the batch (client 2026-08-12 invariant / CodeRabbit).
    $box = Box::create([
        'box_type' => 'RAS',
        'box_number' => 'NRB1',
        'batch_id' => $batchB->id,
        'barcode' => 'BC-NRB1',
        'repository_id' => $repoA->id,
    ]);

    expect($box->fresh()->repository_id)->toBeNull();

    // Visible under the BATCH's repo (repoB), not the stale own repo (repoA).
    app(ActiveRepository::class)->set($repoB->id);
    expect(Box::count())->toBe(1);
    app(ActiveRepository::class)->set($repoA->id);
    expect(Box::count())->toBe(0);
});
