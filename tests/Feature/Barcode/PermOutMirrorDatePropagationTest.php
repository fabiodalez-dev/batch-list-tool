<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(fn () => bl_seedShieldPermissions());

/**
 * F4 — the box→document PERM_OUT mirror must keep each mirrored document
 * individually A1.2-compliant by propagating the box's disinfestation_date onto
 * the documents it marks PERM_OUT (the box guard guarantees the box has one).
 */
it('propagates the box disinfestation_date onto mirrored PERM_OUT documents', function (): void {
    actingAs(User::factory()->create()->assignRole('admin'));

    $repo = Repository::factory()->create();
    $batch = Batch::factory()->create(['repository_id' => $repo->id]);
    $box = Box::factory()->create(['batch_id' => $batch->id, 'box_type' => 'RAS', 'barcode_status' => 'IN']);
    $series = Series::factory()->create();
    $doc = Document::factory()->create([
        'current_box_id' => $box->id,
        'series_id' => $series->id,
        'barcode_status' => 'IN',
        'disinfestation_date' => null,
    ]);

    $box->disinfestation_date = '2026-01-15';
    $box->barcode_status = 'PERM_OUT';
    $box->save();

    $fresh = Document::find($doc->id);
    expect($fresh->barcode_status)->toBe('PERM_OUT');
    expect($fresh->disinfestation_date?->toDateString())->toBe('2026-01-15');
});

it('does not overwrite a documents own disinfestation_date on PERM_OUT mirror', function (): void {
    actingAs(User::factory()->create()->assignRole('admin'));

    $repo = Repository::factory()->create();
    $batch = Batch::factory()->create(['repository_id' => $repo->id]);
    $box = Box::factory()->create(['batch_id' => $batch->id, 'box_type' => 'RAS', 'barcode_status' => 'IN']);
    $series = Series::factory()->create();
    $doc = Document::factory()->create([
        'current_box_id' => $box->id,
        'series_id' => $series->id,
        'barcode_status' => 'IN',
        'disinfestation_date' => '2025-12-01',
    ]);

    $box->disinfestation_date = '2026-01-15';
    $box->barcode_status = 'PERM_OUT';
    $box->save();

    // Gap-fill only — the document's own genuine date must survive.
    expect(Document::find($doc->id)->disinfestation_date?->toDateString())->toBe('2025-12-01');
});
