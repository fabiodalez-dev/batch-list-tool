<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;

uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

/*
|--------------------------------------------------------------------------
| Compliance review round 2 — closes the reviewer's hard findings with
| deterministic, centralised (model-level) assertions.
|--------------------------------------------------------------------------
*/

/* ─── F1 — public registration disabled (internal system) ─────────── */

it('disables public self-registration (Fortify registration feature off)', function () {
    expect(Features::enabled(Features::registration()))->toBeFalse();
});

it('exposes no public /register route', function () {
    expect(app('router')->getRoutes()->getByName('register'))->toBeNull();
});

/* ─── F2 — Batch 50 = wills only, enforced at the model layer ──────── */

it('blocks a non-wills document from the wills-reserve batch 50 (RFQ App.1 #2)', function () {
    $repo = Repository::factory()->create();
    $batch50 = Batch::factory()->create(['batch_number' => Batch::WILLS_BATCH, 'repository_id' => $repo->id]);
    $plain = Series::factory()->create(['code' => 'REG', 'is_wills_series' => false]);

    expect(fn () => Document::factory()->create([
        'identifier' => 'NON-WILL-50',
        'series_id' => $plain->id,
        'repository_id' => $repo->id,
        'batch_id' => $batch50->id,
    ]))->toThrow(DomainException::class);
});

it('allows a wills-series document in batch 50', function () {
    $repo = Repository::factory()->create();
    $batch50 = Batch::factory()->create(['batch_number' => Batch::WILLS_BATCH, 'repository_id' => $repo->id]);
    $wills = Series::factory()->create(['code' => 'RWL', 'is_wills_series' => true]);

    $doc = Document::factory()->create([
        'identifier' => 'WILL-50',
        'series_id' => $wills->id,
        'repository_id' => $repo->id,
        'batch_id' => $batch50->id,
    ]);

    expect((int) $doc->batch_id)->toBe((int) $batch50->id);
});

it('leaves non-wills documents in ordinary batches untouched', function () {
    $repo = Repository::factory()->create();
    $batch = Batch::factory()->create(['batch_number' => 12, 'repository_id' => $repo->id]);
    $plain = Series::factory()->create(['code' => 'O', 'is_wills_series' => false]);

    $doc = Document::factory()->create([
        'identifier' => 'PLAIN-12',
        'series_id' => $plain->id,
        'repository_id' => $repo->id,
        'batch_id' => $batch->id,
    ]);

    expect((int) $doc->batch_id)->toBe((int) $batch->id);
});

/* ─── F4 — IN_SITU / NRA require a parent RAS box, enforced centrally ─ */

it('blocks an IN_SITU box created without a parent RAS box (RFQ App.1 #3)', function () {
    $repo = Repository::factory()->create();
    $batch = Batch::factory()->create(['repository_id' => $repo->id]);

    expect(fn () => Box::factory()->create([
        'box_type' => 'IN_SITU',
        'parent_box_id' => null,
        'batch_id' => $batch->id,
    ]))->toThrow(DomainException::class);
});

it('blocks an NRA box created without a parent RAS box (RFQ App.1 #3)', function () {
    $repo = Repository::factory()->create();
    $batch = Batch::factory()->create(['repository_id' => $repo->id]);

    expect(fn () => Box::factory()->create([
        'box_type' => 'NRA',
        'parent_box_id' => null,
        'batch_id' => $batch->id,
    ]))->toThrow(DomainException::class);
});

it('allows an IN_SITU box when a parent RAS box is provided', function () {
    $repo = Repository::factory()->create();
    $batch = Batch::factory()->create(['repository_id' => $repo->id]);
    $ras = Box::factory()->create(['box_type' => 'RAS', 'batch_id' => $batch->id]);

    $inSitu = Box::factory()->create([
        'box_type' => 'IN_SITU',
        'parent_box_id' => $ras->id,
        'batch_id' => $batch->id,
    ]);

    expect((int) $inSitu->parent_box_id)->toBe((int) $ras->id);
});

/* ─── F7 — field-permission matrix covers the core domain entities ──── */

it('declares a field-permission matrix for every core domain resource', function () {
    $config = (array) config('field_permissions', []);

    expect($config)->toHaveKeys(['document', 'authority', 'series', 'batch', 'box']);
});

/* ─── F5 — backup notification address is env-driven (no placeholder) ─ */

it('drives the backup notification address from env, not a placeholder', function () {
    $to = config('backup.notifications.mail.to');
    expect($to)->not->toBe('your@example.com');
});
