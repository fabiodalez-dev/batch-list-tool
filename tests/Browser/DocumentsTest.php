<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\Lookup\FlagType;
use App\Models\Repository;
use App\Models\Series;

beforeEach(function () {
    bl_seedShieldPermissions();
});

/*
|--------------------------------------------------------------------------
| Documents — the central archival entity (RFQ §1, §3)
|--------------------------------------------------------------------------
*/

function bl_seedDocument(Repository $repo, array $overrides = []): Document
{
    $series = Series::factory()->create();

    $identifier = $overrides['identifier'] ?? 'R-DOC-001';

    // The identifier column is hidden on the list by default (client
    // 2026-10-18); the note, shown by default, carries it so the list can
    // be checked by it.
    return Document::factory()->create(array_merge([
        'identifier' => $identifier,
        'notes' => $identifier,
        'series_id' => $series->id,
        'repository_id' => $repo->id,
    ], $overrides));
}

it('shows a seeded document on the list page', function () {
    $admin = bl_actor('super_admin');
    bl_seedDocument($admin->repositories()->first());

    bl_login($admin)->navigate('/admin/documents')->assertSee('R-DOC-001');
});

it('opens a document view page showing its identifier', function () {
    $admin = bl_actor('super_admin');
    $doc = bl_seedDocument($admin->repositories()->first());

    bl_login($admin)->navigate("/admin/documents/{$doc->id}")->assertSee('R-DOC-001');
});

it('opens a document edit page showing its identifier value', function () {
    $admin = bl_actor('super_admin');
    $doc = bl_seedDocument($admin->repositories()->first());

    bl_login($admin)->navigate("/admin/documents/{$doc->id}/edit")->assertSee('R-DOC-001');
});

it('lets an admin open the document create page', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/documents/create')->assertSee('Create');
});

it('forbids a viewer from creating a document', function () {
    $viewer = bl_actor('viewer');
    bl_login($viewer)->navigate('/admin/documents/create')->assertSee('403');
});

it('shows the document_type on the view page', function () {
    $admin = bl_actor('super_admin');
    $doc = bl_seedDocument($admin->repositories()->first(), ['document_type' => 'Register']);

    bl_login($admin)->navigate("/admin/documents/{$doc->id}")->assertSee('Register');
});

it('shows the linked series code on the document view', function () {
    $admin = bl_actor('super_admin');
    $repo = $admin->repositories()->first();
    $series = Series::factory()->create(['code' => 'REG', 'title' => 'Registers Private Practice']);
    $doc = Document::factory()->create([
        'identifier' => 'R-DOC-SER',
        'series_id' => $series->id,
        'repository_id' => $repo->id,
    ]);

    bl_login($admin)->navigate("/admin/documents/{$doc->id}")->assertSee('REG');
});

it('lists multiple documents', function () {
    $admin = bl_actor('super_admin');
    $repo = $admin->repositories()->first();
    bl_seedDocument($repo, ['identifier' => 'R-LIST-A']);
    bl_seedDocument($repo, ['identifier' => 'R-LIST-B']);

    bl_login($admin)->navigate('/admin/documents')
        ->assertSee('R-LIST-A')
        ->assertSee('R-LIST-B');
});

it('shows a wills document parked in batch 50 (RFQ rule #2)', function () {
    $admin = bl_actor('super_admin');
    $repo = $admin->repositories()->first();
    $batch50 = Batch::factory()->create(['batch_number' => 50, 'repository_id' => $repo->id]);
    $series = Series::factory()->create(['code' => 'RWL', 'is_wills_series' => true]);
    $doc = Document::factory()->create([
        'identifier' => 'WILL-001',
        'series_id' => $series->id,
        'repository_id' => $repo->id,
        'batch_id' => $batch50->id,
    ]);

    bl_login($admin)->navigate("/admin/documents/{$doc->id}")->assertSee('WILL-001');
});

it('shows a document currently held in a box', function () {
    $admin = bl_actor('super_admin');
    $repo = $admin->repositories()->first();
    $batch = Batch::factory()->create(['repository_id' => $repo->id]);
    $box = Box::factory()->create(['box_number' => 'BOX-HOLD', 'batch_id' => $batch->id]);
    $series = Series::factory()->create();
    $doc = Document::factory()->create([
        'identifier' => 'R-INBOX',
        'series_id' => $series->id,
        'repository_id' => $repo->id,
        'current_box_id' => $box->id,
        'batch_id' => $batch->id,
    ]);

    bl_login($admin)->navigate("/admin/documents/{$doc->id}")->assertSee('R-INBOX');
});

it('lets an editor open the document edit page', function () {
    $editor = bl_actor('editor');
    $doc = bl_seedDocument($editor->repositories()->first());

    bl_login($editor)->navigate("/admin/documents/{$doc->id}/edit")->assertSee('R-DOC-001');
});

it('lets a viewer view a document but not edit or create', function () {
    $viewer = bl_actor('viewer');
    $doc = bl_seedDocument($viewer->repositories()->first());

    bl_login($viewer)
        ->navigate("/admin/documents/{$doc->id}")->assertSee('R-DOC-001')
        ->navigate("/admin/documents/{$doc->id}/edit")->assertSee('403')
        ->navigate('/admin/documents/create')->assertSee('403');
});

it('shows a distinctive document_type on the view page', function () {
    $admin = bl_actor('super_admin');
    $doc = bl_seedDocument($admin->repositories()->first(), ['document_type' => 'Minutari']);

    bl_login($admin)->navigate("/admin/documents/{$doc->id}")->assertSee('Minutari');
});

it('renders the documents list heading', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/documents')->assertSee('Documents');
});

it('paints an open flag badge in its Flag Types colour, not the theme cream (client 2026-10-29)', function () {
    $admin = bl_actor('super_admin');
    $doc = bl_seedDocument($admin->repositories()->first());
    FlagType::query()->updateOrCreate(['code' => 'mould_treatment'], ['label' => 'Mould Treatment', 'colour' => 'red', 'is_active' => true, 'sort_order' => 1]);
    DocumentFlag::create(['document_id' => $doc->id, 'type' => 'mould_treatment', 'title' => 'Mould Treatment']);

    // The theme resets every badge to cream; a badge with its own palette
    // must keep it. Red's hue is about 27 in oklch.
    $background = bl_login($admin)->navigate("/admin/documents/{$doc->id}")
        ->assertSee('Mould Treatment')
        ->script(<<<'JS'
            [...document.querySelectorAll('.fi-badge')].find(b => b.textContent.includes('Mould Treatment'))
                ? getComputedStyle([...document.querySelectorAll('.fi-badge')].find(b => b.textContent.includes('Mould Treatment'))).backgroundColor
                : null
        JS);

    preg_match('/^oklch\(([\d.]+) ([\d.]+) ([\d.]+)/', (string) $background, $m);
    expect($m)->not->toBeEmpty()
        ->and((float) $m[2])->toBeGreaterThan(0.02)
        ->and((float) $m[3])->toBeGreaterThan(15.0)->toBeLessThan(40.0);
});
