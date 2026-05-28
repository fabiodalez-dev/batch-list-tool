<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Filament\Imports\SeriesImporter;
use App\Filament\Pages\FieldPermissionMatrix;
use App\Filament\Pages\ImportWizard;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxMovement;
use App\Models\Document;
use App\Models\Repository;
use App\Models\User;
use App\Support\RoleLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Tests covering the compliance-gaps session (2026-05-28):
|   - RoleLabels helper
|   - BoxMovement own repository_id + direct scope
|   - ImportWizard row-validation preflight
|   - FieldPermissionMatrix admin page
|--------------------------------------------------------------------------
*/

/* ───────────────────────── RoleLabels (4) ───────────────────────── */

it('maps super_admin and admin to the RFQ Administrator label', function () {
    expect(RoleLabels::for('super_admin'))->toBe('Administrator')
        ->and(RoleLabels::for('admin'))->toBe('Administrator');
});

it('maps editor to the RFQ ReadingRoom label', function () {
    expect(RoleLabels::for('editor'))->toBe('ReadingRoom');
});

it('maps viewer to the RFQ General label', function () {
    expect(RoleLabels::for('viewer'))->toBe('General');
});

it('falls back to a title-cased slug for an unmapped role', function () {
    expect(RoleLabels::for('records_clerk'))->toBe('Records Clerk');
});

/* ─────────────────── BoxMovement tenancy (6) ─────────────────────── */

function bl_makeBoxInRepo(Repository $repo): Box
{
    $batch = Batch::factory()->create(['repository_id' => $repo->id]);

    return Box::factory()->create(['batch_id' => $batch->id]);
}

function bl_makeDocInRepo(Repository $repo): Document
{
    return Document::factory()->create(['repository_id' => $repo->id]);
}

it('adds a repository_id column to box_movements', function () {
    expect(Schema::hasColumn('box_movements', 'repository_id'))->toBeTrue();
});

it('keeps the explicit repository_id when a movement is created unauthenticated', function () {
    $repo = Repository::factory()->create();
    $box = bl_makeBoxInRepo($repo);
    $doc = bl_makeDocInRepo($repo);

    $movement = BoxMovement::create([
        'document_id' => $doc->id,
        'repository_id' => $repo->id,
        'to_box_id' => $box->id,
        'movement_date' => now(),
    ]);

    expect((int) $movement->repository_id)->toBe((int) $repo->id);
});

it('stamps the acting editor default repository when repository_id is omitted', function () {
    bl_seedRoles();
    $repo = Repository::factory()->create();
    $box = bl_makeBoxInRepo($repo);
    $doc = bl_makeDocInRepo($repo);

    $editor = User::factory()->create(['default_repository_id' => $repo->id]);
    $editor->assignRole('editor');
    $editor->repositories()->attach($repo->id, ['is_default' => true]);
    $this->actingAs($editor);

    $movement = BoxMovement::create([
        'document_id' => $doc->id,
        'to_box_id' => $box->id,
        'movement_date' => now(),
    ]);

    expect((int) $movement->repository_id)->toBe((int) $repo->id);
});

it('throws when a non-privileged user stamps a foreign repository_id', function () {
    bl_seedRoles();
    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();
    $box = bl_makeBoxInRepo($repoB);
    $doc = bl_makeDocInRepo($repoB);

    $editor = User::factory()->create(['default_repository_id' => $repoB->id]);
    $editor->assignRole('editor');
    $editor->repositories()->attach($repoB->id, ['is_default' => true]);
    $this->actingAs($editor);

    BoxMovement::create([
        'document_id' => $doc->id,
        'repository_id' => $repoA->id, // not one of the editor's repos
        'to_box_id' => $box->id,
        'movement_date' => now(),
    ]);
})->throws(DomainException::class);

it('hides a movement from another tenant via the direct RepositoryScope', function () {
    bl_seedRoles();
    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();

    $movementA = BoxMovement::create([
        'document_id' => bl_makeDocInRepo($repoA)->id,
        'repository_id' => $repoA->id,
        'to_box_id' => bl_makeBoxInRepo($repoA)->id,
        'movement_date' => now(),
    ]);
    $movementB = BoxMovement::create([
        'document_id' => bl_makeDocInRepo($repoB)->id,
        'repository_id' => $repoB->id,
        'to_box_id' => bl_makeBoxInRepo($repoB)->id,
        'movement_date' => now(),
    ]);

    $editor = User::factory()->create(['default_repository_id' => $repoB->id]);
    $editor->assignRole('editor');
    $editor->repositories()->attach($repoB->id, ['is_default' => true]);
    $this->actingAs($editor);

    expect(BoxMovement::query()->find($movementA->id))->toBeNull()
        ->and(BoxMovement::query()->find($movementB->id))->not->toBeNull();
});

it('lets an admin see movements across every tenant', function () {
    bl_seedRoles();
    $repoA = Repository::factory()->create();
    $repoB = Repository::factory()->create();

    $a = BoxMovement::create([
        'document_id' => bl_makeDocInRepo($repoA)->id,
        'repository_id' => $repoA->id,
        'to_box_id' => bl_makeBoxInRepo($repoA)->id,
        'movement_date' => now(),
    ]);
    $b = BoxMovement::create([
        'document_id' => bl_makeDocInRepo($repoB)->id,
        'repository_id' => $repoB->id,
        'to_box_id' => bl_makeBoxInRepo($repoB)->id,
        'movement_date' => now(),
    ]);

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    expect(BoxMovement::query()->whereIn('id', [$a->id, $b->id])->count())->toBe(2);
});

/* ─────────────────── ImportWizard preflight (8) ──────────────────── */

it('reports all rows valid for a clean series file', function () {
    $rows = [
        ['Code' => 'R', 'Title' => 'Register Copies'],
        ['Code' => 'O', 'Title' => 'Originals'],
    ];
    $r = ImportWizard::validateRows(SeriesImporter::class, $rows, ['code' => 'Code', 'title' => 'Title']);

    expect($r['total'])->toBe(2)
        ->and($r['valid'])->toBe(2)
        ->and($r['invalid'])->toBe(0)
        ->and($r['errors'])->toBe([]);
});

it('flags rows missing a required field', function () {
    $rows = [
        ['Code' => 'R', 'Title' => 'Valid'],
        ['Code' => '', 'Title' => 'No code'],
    ];
    $r = ImportWizard::validateRows(SeriesImporter::class, $rows, ['code' => 'Code', 'title' => 'Title']);

    expect($r['valid'])->toBe(1)
        ->and($r['invalid'])->toBe(1)
        ->and($r['errors'])->not->toBeEmpty();
});

it('reports 1-based spreadsheet row numbers including the header row', function () {
    $rows = [
        ['Code' => 'R', 'Title' => 'Valid'],   // spreadsheet row 2
        ['Code' => '', 'Title' => 'Bad'],       // spreadsheet row 3
    ];
    $r = ImportWizard::validateRows(SeriesImporter::class, $rows, ['code' => 'Code', 'title' => 'Title']);

    expect($r['errors'][0]['row'])->toBe(3);
});

it('uses the importer column label in the error report', function () {
    $rows = [['Code' => '', 'Title' => 'x']];
    $r = ImportWizard::validateRows(SeriesImporter::class, $rows, ['code' => 'Code', 'title' => 'Title']);

    expect($r['errors'][0]['field'])->toBe('Identifier (code)');
});

it('caps the error detail list but counts every invalid row', function () {
    $rows = [];
    for ($i = 0; $i < ImportWizard::PREFLIGHT_MAX_ERRORS + 50; $i++) {
        $rows[] = ['Code' => '', 'Title' => '']; // both required → fails
    }
    $r = ImportWizard::validateRows(SeriesImporter::class, $rows, ['code' => 'Code', 'title' => 'Title']);

    expect($r['invalid'])->toBe(ImportWizard::PREFLIGHT_MAX_ERRORS + 50)
        ->and($r['truncated'])->toBeTrue()
        ->and(count($r['errors']))->toBe(ImportWizard::PREFLIGHT_MAX_ERRORS);
});

it('ignores unmapped spreadsheet columns when validating', function () {
    $rows = [['Code' => 'R', 'Title' => 'ok', 'Junk' => 'ignored']];
    $r = ImportWizard::validateRows(SeriesImporter::class, $rows, ['code' => 'Code', 'title' => 'Title']);

    expect($r['invalid'])->toBe(0);
});

it('does not crash on an importer with relationship columns', function () {
    $rows = [
        ['Identifier' => 'R1', 'Document Type' => 'Register'],
        ['Identifier' => 'R2', 'Document Type' => 'Original'],
    ];
    $map = ['identifier' => 'Identifier', 'document_type' => 'Document Type'];
    $r = ImportWizard::validateRows(DocumentImporter::class, $rows, $map);

    expect($r['total'])->toBe(2);
});

it('returns a zeroed result for an empty row set', function () {
    $r = ImportWizard::validateRows(SeriesImporter::class, [], ['code' => 'Code']);

    expect($r['total'])->toBe(0)
        ->and($r['valid'])->toBe(0)
        ->and($r['invalid'])->toBe(0);
});

/* ─────────────── FieldPermissionMatrix page (7) ──────────────────── */

it('builds a matrix for every configured resource', function () {
    $m = (new FieldPermissionMatrix)->matrix();

    expect(array_keys($m))->toEqualCanonicalizing(['document', 'authority', 'series', 'batch', 'box']);
});

it('marks the document extra field as hidden from editor and viewer', function () {
    $extra = (new FieldPermissionMatrix)->matrix()['document']['fields']['extra'];

    expect($extra['editor']['hidden'])->toBeTrue()
        ->and($extra['viewer']['hidden'])->toBeTrue();
});

it('marks document repository_id read-only for editor and viewer', function () {
    $rid = (new FieldPermissionMatrix)->matrix()['document']['fields']['repository_id'];

    expect($rid['editor']['read'])->toBeTrue()
        ->and($rid['editor']['write'])->toBeFalse()
        ->and($rid['viewer']['write'])->toBeFalse();
});

it('always grants super_admin read+write and never hides a field', function () {
    $extra = (new FieldPermissionMatrix)->matrix()['document']['fields']['extra'];

    expect($extra['super_admin'])->toBe(['read' => true, 'write' => true, 'hidden' => false]);
});

it('exposes RFQ role labels on the page', function () {
    $page = new FieldPermissionMatrix;

    expect($page->roleLabel('editor'))->toBe('ReadingRoom')
        ->and($page->roleLabel('viewer'))->toBe('General');
});

it('allows admins but not viewers to access the matrix page', function () {
    bl_seedRoles();

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
    expect(FieldPermissionMatrix::canAccess())->toBeTrue();

    $viewer = User::factory()->create();
    $viewer->assignRole('viewer');
    $this->actingAs($viewer);
    expect(FieldPermissionMatrix::canAccess())->toBeFalse();
});

it('renders the matrix page for an admin', function () {
    bl_seedRoles();
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);

    Livewire::test(FieldPermissionMatrix::class)
        ->assertOk()
        ->assertSee('ReadingRoom')
        ->assertSee('Hidden');
});
