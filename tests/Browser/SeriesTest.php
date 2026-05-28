<?php

declare(strict_types=1);

use App\Models\Series;

beforeEach(function () {
    bl_seedShieldPermissions();
});

/*
|--------------------------------------------------------------------------
| Series — document type classification (RFQ §1 reference data)
|--------------------------------------------------------------------------
*/

it('shows a seeded series code on the list', function () {
    Series::factory()->create(['code' => 'REG', 'title' => 'Registers Private Practice']);

    bl_login(bl_actor('super_admin'))->navigate('/admin/series')->assertSee('REG');
});

it('opens a series view page', function () {
    $s = Series::factory()->create(['code' => 'RWL', 'title' => 'Registers Public Wills']);

    bl_login(bl_actor('super_admin'))->navigate("/admin/series/{$s->id}")->assertSee('RWL');
});

it('opens a series edit page', function () {
    $s = Series::factory()->create(['code' => 'O', 'title' => 'Originals']);

    // The title/code arrive as input values (not text nodes); assert the
    // form's field label is rendered to prove the edit page loaded.
    bl_login(bl_actor('super_admin'))->navigate("/admin/series/{$s->id}/edit")->assertSee('Code');
});

it('lets an admin open the series create page', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/series/create')->assertSee('Create');
});

it('forbids a viewer from creating a series', function () {
    bl_login(bl_actor('viewer'))->navigate('/admin/series/create')->assertSee('403');
});

it('shows a wills series (drives batch-50 rule, RFQ #2)', function () {
    $s = Series::factory()->create(['code' => 'RWL2', 'title' => 'Public Wills', 'is_wills_series' => true]);

    bl_login(bl_actor('super_admin'))->navigate("/admin/series/{$s->id}")->assertSee('RWL2');
});

it('lists multiple series', function () {
    Series::factory()->create(['code' => 'SER-A', 'title' => 'Alpha']);
    Series::factory()->create(['code' => 'SER-B', 'title' => 'Beta']);

    bl_login(bl_actor('super_admin'))->navigate('/admin/series')
        ->assertSee('SER-A')
        ->assertSee('SER-B');
});

it('renders the series list heading', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/series')->assertSee('Series');
});
