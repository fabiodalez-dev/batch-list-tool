<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Location;
use App\Models\Repository;

/*
|--------------------------------------------------------------------------
| Client 2026-11-05, issue 9: the disinfestation cycle, as the operator does it
|--------------------------------------------------------------------------
| Power actions, Send to disinfestation; then Finish disinfestation with the
| Radio that reveals the archive Location, through the real modals.
*/

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

it('sends a box to disinfestation and finishes it to an archive location', function (): void {
    $repo = Repository::factory()->create();
    $admin = bl_actor('super_admin', $repo);
    foreach ([['Disinfestation', 'external'], ['RAS', 'external'], ['Archive 4', 'repository'], ['Showcase 1', 'museum']] as [$name, $type]) {
        Location::withoutGlobalScopes()->create(['name' => $name, 'type' => $type, 'repository_id' => $repo->id, 'is_active' => true]);
    }
    $batch = Batch::factory()->create(['repository_id' => $repo->id]);
    $box = Box::factory()->create(['batch_id' => $batch->id, 'box_number' => '31', 'barcode_status' => 'OUT']);
    $doc = Document::factory()->create(['repository_id' => $repo->id, 'batch_id' => $batch->id, 'current_box_id' => $box->id, 'disinfestation_date' => '2020-02-02']);

    $page = bl_login($admin)->navigate('/admin/boxes')->wait(1);

    $page->click('tbody input.fi-ta-record-checkbox')->wait(1)
        ->click('Power actions')->wait(1)
        ->click('Send to disinfestation')->wait(1)
        ->click('.fi-modal-window button.fi-color-warning, .fi-modal-window button[type="submit"]')->wait(3)
        ->assertSee('1 box sent to disinfestation')
        ->assertNoJavascriptErrors();

    expect($box->fresh()->barcode_status)->toBe('OUT')
        ->and($box->fresh()->location->name)->toBe('Disinfestation')
        ->and($doc->fresh()->is_in_disinfestation)->toBeTrue();

    $page->wait(1)->click('tbody input.fi-ta-record-checkbox')->wait(1)
        ->click('Power actions')->wait(1)
        ->click('Finish disinfestation')->wait(1)
        ->click('.fi-modal-window input[type="radio"][value="archive"]')->wait(2)
        ->assertSee('Archive location');

    $page->click('[id="mountedActionSchema0.location_id"]')->wait(2)
        ->assertSee('Archive 4')
        ->assertDontSee('Showcase 1')
        ->click('.fi-select-input-option:has-text("Archive 4")')->wait(1)
        ->click('.fi-modal-window:has([id="mountedActionSchema0.location_id"]) button[type="submit"]')->wait(3)
        ->assertNoJavascriptErrors();

    $fresh = $box->fresh();
    expect($fresh->barcode_status)->toBe('PERM_OUT')
        ->and($fresh->location->name)->toBe('Archive 4')
        ->and($fresh->disinfestation_date->toDateString())->toBe(now()->toDateString())
        ->and($doc->fresh()->is_in_disinfestation)->toBeFalse()
        ->and($doc->fresh()->disinfestation_date_1->toDateString())->toBe('2020-02-02')
        ->and($doc->fresh()->disinfestation_date->toDateString())->toBe(now()->toDateString())
        ->and($doc->fresh()->barcode_status)->toBe('PERM_OUT');
});

it('shows Current Cycle on the Documents list after sending the box', function (): void {
    $repo = Repository::factory()->create();
    $admin = bl_actor('super_admin', $repo);
    $batch = Batch::factory()->create(['repository_id' => $repo->id]);
    $box = Box::factory()->create(['batch_id' => $batch->id, 'box_number' => '32']);
    Document::factory()->create(['repository_id' => $repo->id, 'batch_id' => $batch->id, 'current_box_id' => $box->id, 'is_in_disinfestation' => true, 'disinfestation_date' => '2021-03-04']);

    $page = bl_login($admin)->navigate('/admin/documents')->wait(1);
    $page->click('[aria-label="Column manager"]')->wait(1)
        ->click('input.fi-checkbox-input[id$="-disinfestation_date"]')->wait(1)
        ->click('Apply columns')->wait(2)
        ->assertSee('Current Cycle')
        ->assertDontSee('04-03-2021')
        ->assertNoJavascriptErrors();

    expect($page->script("() => document.evaluate(\"//*[contains(@class,'fi-badge')][contains(.,'Current Cycle')]\", document, null, 9).singleNodeValue !== null"))->toBeTrue();
});
