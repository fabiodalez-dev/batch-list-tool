<?php

declare(strict_types=1);

use App\Models\Document;
use App\Models\Series;

beforeEach(function () {
    bl_seedShieldPermissions();
});

/*
|--------------------------------------------------------------------------
| Bulk import wizard (RFQ §3.1.3) + Reports (RFQ §3.1.10)
|--------------------------------------------------------------------------
*/

it('opens the Import Wizard for an admin', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/import-wizard')->assertSee('Import Wizard');
});

it('shows the first wizard step asking what to import', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/import-wizard')->assertSee('What are you importing');
});

it('shows the row-validation preflight step in the wizard', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/import-wizard')->assertSee('Validate rows');
});

it('forbids an editor from the Import Wizard', function () {
    bl_login(bl_actor('editor'))->navigate('/admin/import-wizard')->assertSee('403');
});

it('opens the Reports landing page', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/reports')->assertSee('reports');
});

it('opens the Documents by Batch report', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/reports/documents-by-batch')->assertSee('Batch');
});

it('opens the Documents by Creator report', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/reports/documents-by-creator')->assertSee('Creator');
});

it('opens the Documents by Series report', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/reports/documents-by-series')->assertSee('Series');
});

it('opens the Pending Disinfestation report', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/reports/pending-disinfestation')->assertSee('Disinfestation');
});

it('opens the Box Movement history report', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/reports/box-movements')->assertSee('movement');
});

it('shows a document grouped under its series in the by-series report', function () {
    $admin = bl_actor('super_admin');
    $repo = $admin->repositories()->first();
    $series = Series::factory()->create(['code' => 'REPSER', 'title' => 'Reportable Series']);
    Document::factory()->create(['identifier' => 'R-REP-1', 'series_id' => $series->id, 'repository_id' => $repo->id]);

    bl_login($admin)->navigate('/admin/reports/documents-by-series')->assertSee('REPSER');
});

it('offers an export action on a report page', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/reports/documents-by-series')->assertSee('Export');
});
