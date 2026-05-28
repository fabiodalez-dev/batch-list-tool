<?php

declare(strict_types=1);

use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Series;
use OwenIt\Auditing\AuditableObserver;
use OwenIt\Auditing\Models\Audit;

beforeEach(function () {
    bl_seedShieldPermissions();
});

/*
|--------------------------------------------------------------------------
| Multi-tenant isolation (RFQ §3.5) + audit trail (RFQ §3.1.5)
|--------------------------------------------------------------------------
*/

function bl_docIn(Repository $repo, string $identifier): Document
{
    $series = Series::factory()->create();

    return Document::factory()->create([
        'identifier' => $identifier,
        'series_id' => $series->id,
        'repository_id' => $repo->id,
    ]);
}

it('shows an editor the documents of their own repository', function () {
    $repoA = Repository::factory()->create();
    $editor = bl_actor('editor', $repoA);
    bl_docIn($repoA, 'OWN-REPO-DOC');

    bl_login($editor)->navigate('/admin/documents')->assertSee('OWN-REPO-DOC');
});

it('hides another tenant documents from a non-admin editor', function () {
    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();
    $editor = bl_actor('editor', $repoA);
    bl_docIn($repoA, 'A-VISIBLE-DOC');
    bl_docIn($repoB, 'B-HIDDEN-DOC');

    bl_login($editor)->navigate('/admin/documents')
        ->assertSee('A-VISIBLE-DOC')
        ->assertDontSee('B-HIDDEN-DOC');
});

it('lets a super_admin see documents across every tenant', function () {
    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();
    $admin = bl_actor('super_admin', $repoA);
    bl_docIn($repoA, 'CROSS-A');
    bl_docIn($repoB, 'CROSS-B');

    bl_login($admin)->navigate('/admin/documents')
        ->assertSee('CROSS-A')
        ->assertSee('CROSS-B');
});

it('lets an admin see documents across every tenant', function () {
    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();
    $admin = bl_actor('admin', $repoA);
    bl_docIn($repoA, 'ADM-A');
    bl_docIn($repoB, 'ADM-B');

    bl_login($admin)->navigate('/admin/documents')
        ->assertSee('ADM-A')
        ->assertSee('ADM-B');
});

it('scopes an editor in repository B to repository B documents', function () {
    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();
    $editor = bl_actor('editor', $repoB);
    bl_docIn($repoA, 'ONLY-A');
    bl_docIn($repoB, 'ONLY-B');

    bl_login($editor)->navigate('/admin/documents')
        ->assertSee('ONLY-B')
        ->assertDontSee('ONLY-A');
});

it('lets a viewer read documents of their own repository', function () {
    $repoA = Repository::factory()->create();
    $viewer = bl_actor('viewer', $repoA);
    bl_docIn($repoA, 'VIEWER-DOC');

    bl_login($viewer)->navigate('/admin/documents')->assertSee('VIEWER-DOC');
});

it('isolates batches by tenant for a non-admin editor', function () {
    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();
    $editor = bl_actor('editor', $repoA);
    Batch::factory()->create(['batch_number' => 211, 'repository_id' => $repoA->id]);
    Batch::factory()->create(['batch_number' => 999, 'repository_id' => $repoB->id]);

    bl_login($editor)->navigate('/admin/batches')
        ->assertSee('211')
        ->assertDontSee('999');
});

it('isolates boxes by tenant for a non-admin editor', function () {
    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();
    $editor = bl_actor('editor', $repoA);
    $batchA = Batch::factory()->create(['repository_id' => $repoA->id]);
    $batchB = Batch::factory()->create(['repository_id' => $repoB->id]);
    Box::factory()->create(['box_number' => 'BOX-OWN-A', 'batch_id' => $batchA->id]);
    Box::factory()->create(['box_number' => 'BOX-FOREIGN-B', 'batch_id' => $batchB->id]);

    bl_login($editor)->navigate('/admin/boxes')
        ->assertSee('BOX-OWN-A')
        ->assertDontSee('BOX-FOREIGN-B');
});

it('opens the audit log page for an admin', function () {
    bl_login(bl_actor('admin'))->navigate('/admin/audits')->assertSee('Audit');
});

it('records an audit entry when a document is created', function () {
    // owen-it skips auditing in console context by default; enable it so the
    // factory create in this (CLI) test process produces the audit row.
    // bootAuditable() already ran (with console=false) when Document was first
    // booted, so the observer was never attached — re-attach it explicitly.
    config(['audit.console' => true]);
    Document::observe(AuditableObserver::class);

    $admin = bl_actor('super_admin');
    $doc = bl_docIn($admin->repositories()->first(), 'AUDITED-DOC');

    // Deterministic proof the create event was audited for THIS document …
    expect(
        Audit::query()
            ->where('auditable_type', Document::class)
            ->where('auditable_id', $doc->id)
            ->where('event', 'created')
            ->exists()
    )->toBeTrue();

    // … and that it surfaces on the audit log page in the browser.
    bl_login($admin)->navigate('/admin/audits')->assertSee('Document');
});

it('lets an editor read the audit log (read-only access)', function () {
    bl_login(bl_actor('editor'))->navigate('/admin/audits')->assertSee('Audit');
});

it('keeps the dashboard reachable for every authenticated role', function () {
    bl_login(bl_actor('viewer'))->navigate('/admin')->assertSee('Dashboard');
});
