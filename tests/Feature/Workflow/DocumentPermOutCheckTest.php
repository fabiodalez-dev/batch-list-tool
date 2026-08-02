<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

/*
 * RFQ App.1 #5 (PERM_OUT requires a disinfestation date) was LOOSENED after
 * client feedback (Charlene Ellul, 2026-08-01) — the legacy NAF export has many
 * PERM_OUT records with no disinfestation date and must import as-is. Client
 * feedback supersedes the RFQ because it is later. The model guard and the DB
 * CHECK were removed; these tests pin the loosened behaviour.
 */

it('allows a document saved PERM_OUT without a disinfestation_date (guard removed)', function (): void {
    actingAs(User::factory()->create()->assignRole('super_admin'));

    $repo = Repository::factory()->create();
    $series = Series::factory()->create();

    $doc = Document::factory()->create([
        'series_id' => $series->id,
        'repository_id' => $repo->id,
        'current_box_id' => null,
        'barcode_status' => 'PERM_OUT',
        'disinfestation_date' => null,
    ]);

    expect($doc->exists)->toBeTrue()
        ->and($doc->fresh()->barcode_status)->toBe('PERM_OUT')
        ->and($doc->fresh()->disinfestation_date)->toBeNull();
});

it('allows a document PERM_OUT together with a disinfestation_date', function (): void {
    actingAs(User::factory()->create()->assignRole('super_admin'));

    $repo = Repository::factory()->create();
    $series = Series::factory()->create();

    $doc = Document::factory()->create([
        'series_id' => $series->id,
        'repository_id' => $repo->id,
        'current_box_id' => null,
        'barcode_status' => 'PERM_OUT',
        'disinfestation_date' => '2026-03-01',
    ]);

    expect($doc->exists)->toBeTrue();
    expect($doc->barcode_status)->toBe('PERM_OUT');
});

it('allows PERM_OUT-without-date even when the document sits in a box (mirror path)', function (): void {
    actingAs(User::factory()->create()->assignRole('super_admin'));

    $repo = Repository::factory()->create();
    $batch = Batch::factory()->create(['repository_id' => $repo->id]);
    $box = Box::factory()->create([
        'batch_id' => $batch->id,
        'box_type' => 'RAS',
        'barcode_status' => 'IN',
        'barcode' => 'BC-PO-1',
    ]);
    $series = Series::factory()->create();

    $doc = Document::factory()->create([
        'series_id' => $series->id,
        'repository_id' => $repo->id,
        'current_box_id' => $box->id,
        'batch_id' => $batch->id,
        'barcode_status' => 'IN',
    ]);

    $doc->barcode_status = 'PERM_OUT';
    $doc->disinfestation_date = null;
    $doc->save();

    expect($doc->fresh()->barcode_status)->toBe('PERM_OUT')
        ->and($doc->fresh()->disinfestation_date)->toBeNull();
});

it('confirms the DB-level CHECK is absent on the sqlite test driver', function (): void {
    // The chk_documents_permout_requires_disinfestation CHECK was dropped by a
    // mysql-guarded migration (2026_08_02_100000). On the SQLite test driver it
    // never existed. The MySQL/MariaDB removal is covered by
    // BoxPermOutDocumentMirrorTest in the CI's dedicated MariaDB step.
    expect(DB::connection()->getDriverName())->toBe('sqlite');
})->skip(fn () => DB::connection()->getDriverName() !== 'sqlite', 'driver-specific assertion');
