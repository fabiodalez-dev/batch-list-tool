<?php

declare(strict_types=1);

use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\DocumentIdentifierHistory;
use App\Models\Series;

beforeEach(function () {
    bl_seedShieldPermissions();
});

/*
|--------------------------------------------------------------------------
| Document New / Edit / View (client 2026-11-05, issue 3)
|--------------------------------------------------------------------------
| The feature tests go through Livewire; here the pages render in a real
| browser: same sections, same order, no JavaScript errors.
*/

const DOCUMENT_SECTIONS = ['Identification', 'Alternate Identification', 'Logistics', 'Disinfestation', 'Supporting Documents'];

function bl_sectionOrder($page): array
{
    $headings = $page->script("Array.from(document.querySelectorAll('.fi-section-header-heading')).map((h) => h.textContent.trim())");

    return array_values(array_intersect($headings, DOCUMENT_SECTIONS));
}

/**
 * Any form page with a searchable select, such as Create Box, reports Chromium's
 * "ResizeObserver loop" notice as an error event. It is not a script error, so
 * it alone is let through on the form pages.
 */
function bl_scriptErrors($page): array
{
    $errors = $page->script('window.__pestBrowser.jsErrors.map((e) => e.message)');

    return array_values(array_filter($errors, fn (string $message): bool => ! str_contains($message, 'ResizeObserver loop')));
}

function bl_documentForLayout(): array
{
    $admin = bl_actor('super_admin');
    $repo = $admin->repositories()->first();
    $batch = Batch::factory()->create(['repository_id' => $repo->id, 'batch_number' => 4]);
    $box = Box::factory()->create(['batch_id' => $batch->id, 'box_number' => 192, 'box_type' => 'RAS']);
    $document = Document::factory()->create([
        'repository_id' => $repo->id,
        'series_id' => Series::factory()->create()->id,
        // The factory's random type is not one of the active types on the form.
        'document_type' => null,
        'current_box_id' => $box->id,
        'volume_number' => '4',
        'dates' => 'Jun 1997 - Nov 1998',
        'object_reference_number' => 'OBJ-1; OBJ-2',
    ]);
    $authority = Authority::query()->create(['identifier' => 'NAM-1', 'alternative_identifier' => 'R16', 'surname' => 'Dos', 'given_names' => 'Giovanni', 'entity_type' => 'PERSON']);
    $document->authorities()->attach($authority->id, ['is_primary' => true]);
    DocumentIdentifierHistory::recordPastVolume($document, '5', '4');

    return [$admin, $document, $box];
}

it('shows the same sections in the same order on New, Edit and View, without JavaScript errors', function () {
    [$admin, $document] = bl_documentForLayout();

    $new = bl_login($admin)->navigate('/admin/documents/create')->assertSee('Identification');
    expect(bl_sectionOrder($new))->toBe(DOCUMENT_SECTIONS)
        ->and(bl_scriptErrors($new))->toBe([]);

    $edit = $new->navigate("/admin/documents/{$document->id}/edit")->assertSee('Identification');
    expect(bl_sectionOrder($edit))->toBe(DOCUMENT_SECTIONS)
        ->and(bl_scriptErrors($edit))->toBe([]);
    $edit->assertSee('Not disinfested');

    $view = $edit->navigate("/admin/documents/{$document->id}")->assertSee('Identification');
    expect(bl_sectionOrder($view))->toBe(DOCUMENT_SECTIONS);
    $view->assertSee('Not disinfested')
        ->assertSee('R16: Dos, Giovanni')
        ->assertSee('Batch 4 / Box 192')
        ->assertNoJavascriptErrors();
});

it('opens the Box view from the current box on the document view', function () {
    [$admin, $document, $box] = bl_documentForLayout();

    $page = bl_login($admin)->navigate("/admin/documents/{$document->id}")->click('Batch 4 / Box 192')->wait(2);

    expect($page->url())->toContain("/admin/boxes/{$box->id}");
    $page->assertNoJavascriptErrors();
});

it('takes several values as tags and keeps the old volume when the volume is changed', function () {
    [$admin, $document] = bl_documentForLayout();

    $page = bl_login($admin)->navigate("/admin/documents/{$document->id}/edit")->assertSee('OBJ-2');
    $page->type('[id="form.citation_reference"]', 'CIT-1')->keys('[id="form.citation_reference"]', ';')->wait(1)
        ->type('[id="form.citation_reference"]', 'CIT-2')->keys('[id="form.citation_reference"]', 'Enter')->wait(1)
        ->fill('[id="form.volume_number"]', '3')
        ->click('Save changes')->wait(3)
        ->assertSee('Saved');

    $document->refresh();
    expect($document->citation_reference)->toBe('CIT-1; CIT-2')
        ->and($document->object_reference_number)->toBe('OBJ-1; OBJ-2')
        ->and($document->volume_number)->toBe('3')
        ->and($document->dates)->toBe('Jun 1997 - Nov 1998')
        ->and($document->pastVolumes()->where('previous_volume', '4')->exists())->toBeTrue()
        ->and(bl_scriptErrors($page))->toBe([]);
});

it('asks which date option on a new document and shows only its fields', function () {
    [$admin] = bl_documentForLayout();

    $page = bl_login($admin)->navigate('/admin/documents/create')->assertSee('Free text')->assertSee('Year start / Year end');
    expect($page->script("!! document.querySelector('[id$=\"dates_year_start\"]')"))->toBeFalse();

    $page->click('Year start / Year end')->wait(2);
    expect($page->script("!! document.querySelector('[id$=\"dates_year_start\"]')"))->toBeTrue();
    expect($page->script("!! document.querySelector('[id$=\"dates_start\"]')"))->toBeFalse()
        ->and(bl_scriptErrors($page))->toBe([]);
});
