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
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

it('rejects a document saved PERM_OUT without a disinfestation_date (model guard)', function (): void {
    actingAs(User::factory()->create()->assignRole('super_admin'));

    $repo = Repository::factory()->create();
    $series = Series::factory()->create();

    expect(fn () => Document::factory()->create([
        'series_id' => $series->id,
        'repository_id' => $repo->id,
        'current_box_id' => null,
        'barcode_status' => 'PERM_OUT',
        'disinfestation_date' => null,
    ]))->toThrow(ValidationException::class);
});

it('allows a document PERM_OUT once it has a disinfestation_date', function (): void {
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

it('still rejects PERM_OUT-without-date even when the document sits in a box', function (): void {
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

    expect(fn () => $doc->save())->toThrow(ValidationException::class);
});

it('documents the DB-level CHECK is mysql-only (skipped on sqlite test driver)', function (): void {
    // F2: the chk_documents_permout_requires_disinfestation CHECK is added by
    // a mysql-guarded migration as a second line of defence on MariaDB. On the
    // SQLite test driver it is intentionally absent — the model guard above is
    // the cross-driver enforcement. We just assert the driver assumption holds
    // so the intent is documented and the test self-explains.
    expect(DB::connection()->getDriverName())->toBe('sqlite');
})->skip(fn () => DB::connection()->getDriverName() !== 'sqlite', 'CHECK lives in DB on mysql/mariadb');
