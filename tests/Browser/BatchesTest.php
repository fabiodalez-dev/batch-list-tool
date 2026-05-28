<?php

declare(strict_types=1);

use App\Models\Batch;

beforeEach(function () {
    bl_seedShieldPermissions();
});

/*
|--------------------------------------------------------------------------
| Batches — numbered groupings + allocation rules (RFQ §2, App.1 #1/#5)
|--------------------------------------------------------------------------
*/

it('shows a seeded batch number on the list', function () {
    $admin = bl_actor('super_admin');
    Batch::factory()->create(['batch_number' => 12, 'repository_id' => $admin->repositories()->first()->id]);

    bl_login($admin)->navigate('/admin/batches')->assertSee('12');
});

it('opens a batch view page', function () {
    $admin = bl_actor('super_admin');
    $batch = Batch::factory()->create(['batch_number' => 18, 'repository_id' => $admin->repositories()->first()->id]);

    bl_login($admin)->navigate("/admin/batches/{$batch->id}")->assertSee('18');
});

it('opens a batch edit page', function () {
    $admin = bl_actor('super_admin');
    $batch = Batch::factory()->create(['batch_number' => 22, 'repository_id' => $admin->repositories()->first()->id]);

    bl_login($admin)->navigate("/admin/batches/{$batch->id}/edit")->assertSee('22');
});

it('lets an admin open the batch create page', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/batches/create')->assertSee('Create');
});

it('forbids a viewer from creating a batch', function () {
    bl_login(bl_actor('viewer'))->navigate('/admin/batches/create')->assertSee('403');
});

it('shows a Main Collection batch (1-29, RFQ rule #5)', function () {
    $admin = bl_actor('super_admin');
    Batch::factory()->create(['batch_number' => 7, 'type' => 'MAIN_COLLECTION', 'repository_id' => $admin->repositories()->first()->id]);

    bl_login($admin)->navigate('/admin/batches')->assertSee('7');
});

it('shows a Notary Accession batch (30+, RFQ rule #5)', function () {
    $admin = bl_actor('super_admin');
    Batch::factory()->create(['batch_number' => 35, 'type' => 'NOTARY_ACCESSION', 'repository_id' => $admin->repositories()->first()->id]);

    bl_login($admin)->navigate('/admin/batches')->assertSee('35');
});

it('shows the wills reserve batch 50 (RFQ rule #2)', function () {
    $admin = bl_actor('super_admin');
    Batch::factory()->create(['batch_number' => 50, 'repository_id' => $admin->repositories()->first()->id]);

    bl_login($admin)->navigate('/admin/batches')->assertSee('50');
});

it('lists multiple batches', function () {
    $admin = bl_actor('super_admin');
    $repo = $admin->repositories()->first();
    Batch::factory()->create(['batch_number' => 101, 'repository_id' => $repo->id]);
    Batch::factory()->create(['batch_number' => 102, 'repository_id' => $repo->id]);

    bl_login($admin)->navigate('/admin/batches')->assertSee('101')->assertSee('102');
});

it('renders the batches list heading', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/batches')->assertSee('Batches');
});
