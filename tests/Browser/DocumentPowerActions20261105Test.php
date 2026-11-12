<?php

declare(strict_types=1);

use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Lookup\BoxType;
use App\Models\Series;

beforeEach(function () {
    bl_seedShieldPermissions();
    foreach (['RAS', 'NRA'] as $i => $code) {
        BoxType::query()->firstOrCreate(['code' => $code], ['label' => $code, 'is_active' => true, 'sort_order' => $i]);
    }
});

/*
|--------------------------------------------------------------------------
| Client 2026-11-05, Issue 12: the Document power actions in the real browser
|--------------------------------------------------------------------------
| "NRA 5 ... can't find it": the search goes through the JS of the select, and
| the modal that must not open only shows up as not open in a browser.
*/

it('finds NRA 5 by typing it in Move to box, and moves the document there', function () {
    $admin = bl_actor('super_admin');
    $repo = $admin->repositories()->first();

    $batch = Batch::factory()->create(['batch_number' => '4', 'repository_id' => $repo->id]);
    $ras = Box::factory()->create(['box_type' => 'RAS', 'box_number' => '192', 'batch_id' => $batch->id, 'barcode_status' => 'IN']);
    foreach (range(1, 60) as $n) {
        Box::factory()->create(['box_type' => 'RAS', 'box_number' => (string) $n, 'batch_id' => $batch->id, 'barcode_status' => 'IN']);
    }
    $nra5 = Box::withoutGlobalScopes()->create([
        'box_type' => 'NRA', 'box_number' => '5', 'repository_id' => $repo->id,
        'barcode_status' => 'IN', 'is_legacy' => true, 'provenance_unknown' => true,
    ]);
    $doc = Document::factory()->create([
        'identifier' => 'R-MOVE-1', 'notes' => 'R-MOVE-1', 'series_id' => Series::factory()->create()->id,
        'repository_id' => $repo->id, 'batch_id' => $batch->id, 'current_box_id' => $ras->id,
    ]);

    $page = bl_login($admin)->navigate('/admin/documents')->assertSee('R-MOVE-1');
    $page->click('input.fi-ta-record-checkbox')->wait(1)
        ->click('Power actions')->wait(1)
        ->click('Move to box')->wait(2)
        ->assertSee('Move selected documents to a different box');

    $picker = '.fi-select-input-ctn:has([id="mountedActionSchema0.to_box_id"])';
    $page->click($picker . ' .fi-select-input-btn')->wait(1)
        ->type($picker . ' .fi-select-input-search-ctn input', 'NRA 5')->wait(2)
        ->assertSeeIn($picker, 'NRA 5')
        ->assertDontSee('Batch 4 / Box 5 — RAS');

    $page->click($picker . ' .fi-select-input-option')->wait(1)
        ->click('.fi-modal-window button[type="submit"]')->wait(3)
        ->assertSee('1 document(s) moved to NRA 5');

    $fresh = $doc->fresh();
    expect($fresh->current_box_id)->toBe($nra5->id)
        ->and($fresh->batch_id)->toBe($batch->id)
        ->and($fresh->custody_status)->toBe('in_box');
});

it('does not open Replace authority without an authority in common, and says why', function () {
    $admin = bl_actor('super_admin');
    $repo = $admin->repositories()->first();
    $series = Series::factory()->create();

    foreach (['R-A' => 'Abela', 'R-B' => 'Borg'] as $identifier => $surname) {
        $doc = Document::factory()->create(['identifier' => $identifier, 'notes' => $identifier, 'series_id' => $series->id, 'repository_id' => $repo->id]);
        $doc->authorities()->attach(Authority::create(['identifier' => 'A-' . $surname, 'surname' => $surname, 'entity_type' => 'PERSON'])->id);
    }

    $page = bl_login($admin)->navigate('/admin/documents')->assertSee('R-A')->assertSee('R-B');
    $page->click('input.fi-ta-page-checkbox')->wait(1)
        ->click('Power actions')->wait(1)
        ->click('Replace authority')->wait(2)
        ->assertSee('Replace authority is not available')
        ->assertDontSee('Replace one authority with another');
});

it('reads the Subseries rule as code and title', function () {
    $admin = bl_actor('super_admin');
    Series::factory()->create(['code' => 'MDV', 'title' => 'Medieval deeds']);

    $page = bl_login($admin)->navigate('/admin/documents');
    $page->click('[aria-label="Filter"], [title="Filter"], .fi-ta-filters-trigger-action-ctn button')->wait(1)
        ->click('Add rule')->wait(1)
        ->click('button[wire\\:click*="u0022series"]')->wait(2);

    $value = '.fi-select-input-ctn:has([id$="settings.value"])';
    $page->click($value . ' .fi-select-input-btn')->wait(1)
        ->type($value . ' .fi-select-input-search-ctn input', 'medieval')->wait(2)
        ->assertSee('MDV: Medieval deeds');
});
