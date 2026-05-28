<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\Repository;

beforeEach(function () {
    bl_seedShieldPermissions();
});

/*
|--------------------------------------------------------------------------
| Boxes — physical containers + type/legacy rules (RFQ §2, App.1 #3/#4/#5)
|--------------------------------------------------------------------------
*/

function bl_box(Repository $repo, array $overrides = []): Box
{
    $batch = Batch::factory()->create(['repository_id' => $repo->id]);

    return Box::factory()->create(array_merge(['batch_id' => $batch->id], $overrides));
}

it('shows a seeded box number on the list', function () {
    $admin = bl_actor('super_admin');
    bl_box($admin->repositories()->first(), ['box_number' => 'BX-001']);

    bl_login($admin)->navigate('/admin/boxes')->assertSee('BX-001');
});

it('opens a box view page', function () {
    $admin = bl_actor('super_admin');
    $box = bl_box($admin->repositories()->first(), ['box_number' => 'BX-VIEW']);

    bl_login($admin)->navigate("/admin/boxes/{$box->id}")->assertSee('BX-VIEW');
});

it('opens a box edit page', function () {
    $admin = bl_actor('super_admin');
    $box = bl_box($admin->repositories()->first(), ['box_number' => 'BX-EDIT']);

    bl_login($admin)->navigate("/admin/boxes/{$box->id}/edit")->assertSee('BX-EDIT');
});

it('lets an admin open the box create page', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/boxes/create')->assertSee('Create');
});

it('forbids a viewer from creating a box', function () {
    bl_login(bl_actor('viewer'))->navigate('/admin/boxes/create')->assertSee('403');
});

it('shows a RAS box', function () {
    $admin = bl_actor('super_admin');
    bl_box($admin->repositories()->first(), ['box_number' => 'RAS-9', 'box_type' => 'RAS']);

    bl_login($admin)->navigate('/admin/boxes')->assertSee('RAS-9');
});

it('shows a legacy MAV box (RFQ rule #4)', function () {
    $admin = bl_actor('super_admin');
    bl_box($admin->repositories()->first(), ['box_number' => 'MAV-1', 'box_type' => 'MAV', 'is_legacy' => true]);

    bl_login($admin)->navigate('/admin/boxes')->assertSee('MAV-1');
});

it('shows a legacy STVC box (RFQ rule #4)', function () {
    $admin = bl_actor('super_admin');
    bl_box($admin->repositories()->first(), ['box_number' => 'STVC-1', 'box_type' => 'STVC', 'is_legacy' => true]);

    bl_login($admin)->navigate('/admin/boxes')->assertSee('STVC-1');
});

it('shows an IN_SITU box created from a parent RAS box (RFQ rule #3)', function () {
    $admin = bl_actor('super_admin');
    $repo = $admin->repositories()->first();
    $batch = Batch::factory()->create(['repository_id' => $repo->id]);
    $ras = Box::factory()->create(['box_number' => 'RAS-PARENT', 'box_type' => 'RAS', 'batch_id' => $batch->id]);
    $inSitu = Box::factory()->create([
        'box_number' => 'INSITU-1',
        'box_type' => 'IN_SITU',
        'batch_id' => $batch->id,
        'parent_box_id' => $ras->id,
    ]);

    bl_login($admin)->navigate("/admin/boxes/{$inSitu->id}")->assertSee('INSITU-1');
});

it('shows a PERM_OUT box with a disinfestation date (RFQ rule #5)', function () {
    $admin = bl_actor('super_admin');
    $box = bl_box($admin->repositories()->first(), [
        'box_number' => 'PO-1',
        'barcode_status' => 'PERM_OUT',
        'disinfestation_date' => '2026-05-01',
    ]);

    bl_login($admin)->navigate("/admin/boxes/{$box->id}")->assertSee('PO-1');
});

it('lists multiple boxes', function () {
    $admin = bl_actor('super_admin');
    $repo = $admin->repositories()->first();
    bl_box($repo, ['box_number' => 'MULTI-A']);
    bl_box($repo, ['box_number' => 'MULTI-B']);

    bl_login($admin)->navigate('/admin/boxes')->assertSee('MULTI-A')->assertSee('MULTI-B');
});

it('renders the boxes list heading', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/boxes')->assertSee('Boxes');
});
