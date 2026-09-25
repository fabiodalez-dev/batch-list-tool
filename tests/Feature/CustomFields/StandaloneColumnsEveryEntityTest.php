<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentTypeImporter;
use App\Filament\Imports\LocationImporter;
use App\Filament\Imports\SeriesImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Accession;
use App\Models\CustomFieldDefinition;
use App\Models\DocumentType;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\TemplateGenerator;
use App\Support\ColumnLabels\ColumnLabels;
use App\Support\CustomFields\CustomFieldResolver;
use Filament\Actions\Imports\Jobs\ImportCsv;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/*
 * Client, 2026-09-25: "Is it possible to add new columns that are independent
 * (standalone)?"
 *
 * Adding a column was already hers on Documents, Batches, Boxes, Volumes and
 * (since 2026-09-24) Authorities. These tests are about the four that were left:
 * Subseries, Locations, Document Types and Notary Accessions.
 *
 * Document Types are the awkward one. That table has no repository_id column at
 * all — the list is shared across the archive — so the only repository available
 * is whichever one is active, and a value written in a queue worker would read
 * back as absent if the read were scoped the same way. That is the case the
 * anchoring exists for, and the case worth a test.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    ColumnLabels::flushMemo();
    CustomFieldResolver::flush();
});

afterEach(function (): void {
    ColumnLabels::flushMemo();
    CustomFieldResolver::flush();
});

function sce_admin(Repository $repo): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create([
        'email' => 'sce+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repo->id,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function sce_define(Repository $repo, string $entity, string $key, string $label, string $type = 'text'): CustomFieldDefinition
{
    CustomFieldResolver::flush();

    return CustomFieldDefinition::create([
        'repository_id' => $repo->id,
        'entity_type' => $entity,
        'key' => $key,
        'label' => $label,
        'type' => $type,
        'is_active' => true,
        'sort_order' => 1,
    ]);
}

it('offers standalone columns on every entity, leaving none needing a developer', function (): void {
    expect(array_keys(CustomFieldDefinition::ENTITY_TYPES))->toEqualCanonicalizing([
        'document', 'batch', 'box', 'volume', 'authority',
        'series', 'location', 'documentType', 'accession',
    ]);
});

it('spells the document-type entity key the way the rest of the system does', function (): void {
    // The trait default lowercases the class name, which would give
    // "documenttype". Every other part of the system — the template registry,
    // the entity picker, ColumnLabels — says "documentType", and two spellings
    // of one key would silently split the definitions in half: columns defined
    // under one spelling would never appear on a record read under the other.
    expect((new DocumentType)->customFieldEntityType())->toBe('documentType')
        ->and(array_key_exists('documentType', CustomFieldDefinition::ENTITY_TYPES))->toBeTrue();
});

it('stores and reads back an added column on a subseries', function (): void {
    $repo = Repository::factory()->create(['code' => 'SCE1']);
    $this->actingAs(sce_admin($repo));
    sce_define($repo, 'series', 'legacy_shelf', 'Legacy Shelf');

    $series = Series::factory()->create(['code' => 'SCE-REG', 'repository_id' => $repo->id]);
    $series->setCustomFieldData(['legacy_shelf' => 'Shelf 12']);

    expect($series->fresh()->getCustomFieldData())->toBe(['legacy_shelf' => 'Shelf 12']);
});

it('stores and reads back an added column on a location', function (): void {
    $repo = Repository::factory()->create(['code' => 'SCE2']);
    $this->actingAs(sce_admin($repo));
    sce_define($repo, 'location', 'floor', 'Floor');

    $location = Location::factory()->create(['name' => 'Room 4', 'repository_id' => $repo->id]);
    $location->setCustomFieldData(['floor' => 'Second']);

    expect($location->fresh()->getCustomFieldData())->toBe(['floor' => 'Second']);
});

it('stores and reads back an added column on a notary accession', function (): void {
    $repo = Repository::factory()->create(['code' => 'SCE3']);
    $this->actingAs(sce_admin($repo));
    sce_define($repo, 'accession', 'donor', 'Donor');

    $accession = Accession::factory()->create(['repository_id' => $repo->id]);
    $accession->setCustomFieldData(['donor' => 'Borg family']);

    expect($accession->fresh()->getCustomFieldData())->toBe(['donor' => 'Borg family']);
});

it('stores and reads back an added column on a document type', function (): void {
    $repo = Repository::factory()->create(['code' => 'SCE4']);
    $this->actingAs(sce_admin($repo));
    sce_define($repo, 'documentType', 'retention', 'Retention');

    $type = DocumentType::create(['identifier' => 'DT00900', 'name' => 'Will', 'is_active' => true]);
    $type->setCustomFieldData(['retention' => 'Permanent']);

    expect($type->fresh()->getCustomFieldData())->toBe(['retention' => 'Permanent']);
});

it('still reads a document type column once no repository is active', function (): void {
    $repo = Repository::factory()->create(['code' => 'SCE5']);
    $user = sce_admin($repo);
    $this->actingAs($user);
    sce_define($repo, 'documentType', 'retention', 'Retention');

    $type = DocumentType::create(['identifier' => 'DT00901', 'name' => 'Deed', 'is_active' => true]);
    $type->setCustomFieldData(['retention' => 'Permanent']);

    // This is the whole reason for the anchoring. document_types has no
    // repository of its own, so the definitions on offer are the ACTIVE
    // repository's — and there is no active repository inside a queue worker
    // once the import job has finished, nor when the operator has "All
    // repositories" selected in the topbar. Scoping the READ the same way would
    // make a value written a minute earlier read back as absent.
    auth()->logout();
    CustomFieldResolver::flush();
    expect(CustomFieldResolver::activeRepositoryId())->toBeNull();

    expect($type->fresh()->getCustomFieldData())->toBe(['retention' => 'Permanent']);
});

it('does not show one archive added columns on another archive record', function (): void {
    $mine = Repository::factory()->create(['code' => 'SCE6A']);
    $theirs = Repository::factory()->create(['code' => 'SCE6B']);
    sce_define($theirs, 'documentType', 'their_field', 'Their Field');

    $this->actingAs(sce_admin($mine));
    CustomFieldResolver::flush();

    // A brand-new record has no stored value to anchor to, so the active
    // repository alone decides — and it must not reach the other tenant's
    // definitions.
    $type = DocumentType::create(['identifier' => 'DT00902', 'name' => 'Register', 'is_active' => true]);

    expect($type->customFieldDefinitions()->get())->toHaveCount(0)
        ->and($type->getCustomFieldData())->toBe([]);
});

it('adds the new column to the subseries, location and document-type templates', function (): void {
    $repo = Repository::factory()->create(['code' => 'SCE7']);
    $this->actingAs(sce_admin($repo));

    sce_define($repo, 'series', 'legacy_shelf', 'Legacy Shelf');
    sce_define($repo, 'location', 'floor', 'Floor');
    sce_define($repo, 'documentType', 'retention', 'Retention');
    CustomFieldResolver::flush();

    // Until today these three templates returned their static header row and
    // skipped the custom-field lookup entirely, so a column she added could
    // never be filled in from a sheet.
    expect(TemplateGenerator::headersFor('series'))->toContain('Legacy Shelf')
        ->and(TemplateGenerator::headersFor('location'))->toContain('Floor')
        ->and(TemplateGenerator::headersFor('documentType'))->toContain('Retention');
});

it('imports a value into an added subseries column', function (): void {
    $repo = Repository::factory()->create(['code' => 'SCE8']);
    $user = sce_admin($repo);
    $this->actingAs($user);
    sce_define($repo, 'series', 'legacy_shelf', 'Legacy Shelf');
    CustomFieldResolver::flush();

    $rows = [[
        'Identifier' => 'SCE-IMP',
        'Standard title in English (Plural)' => 'Imported Registers',
        'Repository' => 'SCE8',
        'Legacy Shelf' => 'Shelf 7',
    ]];

    $map = ImportWizard::guessColumnMap(SeriesImporter::class, array_keys($rows[0]));
    expect($map['custom_field_legacy_shelf'] ?? null)->toBe('Legacy Shelf');

    $import = Import::query()->create([
        'completed_at' => null, 'file_name' => 's.csv', 'file_path' => '/tmp/s.csv',
        'importer' => SeriesImporter::class, 'processed_rows' => 0,
        'total_rows' => 1, 'successful_rows' => 0, 'user_id' => $user->id,
    ]);
    app(ImportCsv::class, [
        'import' => $import,
        'rows' => base64_encode(serialize($rows)),
        'columnMap' => $map,
        'options' => [],
    ])->handle();

    $series = Series::where('code', 'SCE-IMP')->first();

    expect($import->refresh()->getFailedRowsCount())->toBe(0)
        ->and($series)->not->toBeNull()
        ->and($series->getCustomFieldData())->toBe(['legacy_shelf' => 'Shelf 7']);
});

it('imports a value into an added location column', function (): void {
    $repo = Repository::factory()->create(['code' => 'SCE9']);
    $user = sce_admin($repo);
    $this->actingAs($user);
    sce_define($repo, 'location', 'floor', 'Floor');
    CustomFieldResolver::flush();

    $rows = [[
        'name' => 'Imported Room',
        'type' => 'room',
        'repository_code' => 'SCE9',
        'Floor' => 'Third',
    ]];

    $map = ImportWizard::guessColumnMap(LocationImporter::class, array_keys($rows[0]));
    $import = Import::query()->create([
        'completed_at' => null, 'file_name' => 'l.csv', 'file_path' => '/tmp/l.csv',
        'importer' => LocationImporter::class, 'processed_rows' => 0,
        'total_rows' => 1, 'successful_rows' => 0, 'user_id' => $user->id,
    ]);
    app(ImportCsv::class, [
        'import' => $import,
        'rows' => base64_encode(serialize($rows)),
        'columnMap' => $map,
        'options' => [],
    ])->handle();

    $location = Location::where('name', 'Imported Room')->first();

    expect($import->refresh()->getFailedRowsCount())->toBe(0)
        ->and($location)->not->toBeNull()
        ->and($location->getCustomFieldData())->toBe(['floor' => 'Third']);
});

it('imports a value into an added document-type column', function (): void {
    $repo = Repository::factory()->create(['code' => 'SCE10']);
    $user = sce_admin($repo);
    $this->actingAs($user);
    sce_define($repo, 'documentType', 'retention', 'Retention');
    CustomFieldResolver::flush();

    $rows = [[
        'Identifier' => 'DT00950',
        'Name' => 'Imported Type',
        'Retention' => 'Permanent',
    ]];

    $map = ImportWizard::guessColumnMap(DocumentTypeImporter::class, array_keys($rows[0]));
    $import = Import::query()->create([
        'completed_at' => null, 'file_name' => 'd.csv', 'file_path' => '/tmp/d.csv',
        'importer' => DocumentTypeImporter::class, 'processed_rows' => 0,
        'total_rows' => 1, 'successful_rows' => 0, 'user_id' => $user->id,
    ]);
    app(ImportCsv::class, [
        'import' => $import,
        'rows' => base64_encode(serialize($rows)),
        'columnMap' => $map,
        'options' => [],
    ])->handle();

    $type = DocumentType::where('identifier', 'DT00950')->first();

    expect($import->refresh()->getFailedRowsCount())->toBe(0)
        ->and($type)->not->toBeNull()
        ->and($type->getCustomFieldData())->toBe(['retention' => 'Permanent']);
});

it('leaves an unmapped added column alone instead of wiping it', function (): void {
    $repo = Repository::factory()->create(['code' => 'SCE11']);
    $user = sce_admin($repo);
    $this->actingAs($user);
    sce_define($repo, 'series', 'legacy_shelf', 'Legacy Shelf');
    sce_define($repo, 'series', 'condition', 'Condition');
    CustomFieldResolver::flush();

    $series = Series::factory()->create(['code' => 'SCE-KEEP', 'repository_id' => $repo->id]);
    $series->setCustomFieldData(['legacy_shelf' => 'Shelf 1', 'condition' => 'Good']);

    // A sheet that maps only one of the added columns must not silently wipe
    // the other: merge semantics, not replace.
    $rows = [['Identifier' => 'SCE-KEEP', 'Legacy Shelf' => 'Shelf 2']];
    $map = ImportWizard::guessColumnMap(SeriesImporter::class, array_keys($rows[0]));
    $import = Import::query()->create([
        'completed_at' => null, 'file_name' => 'k.csv', 'file_path' => '/tmp/k.csv',
        'importer' => SeriesImporter::class, 'processed_rows' => 0,
        'total_rows' => 1, 'successful_rows' => 0, 'user_id' => $user->id,
    ]);
    app(ImportCsv::class, [
        'import' => $import,
        'rows' => base64_encode(serialize($rows)),
        'columnMap' => $map,
        'options' => [],
    ])->handle();

    expect($series->fresh()->getCustomFieldData())
        ->toBe(['legacy_shelf' => 'Shelf 2', 'condition' => 'Good']);
});

it('refuses to rename a built-in column onto an added column name', function (): void {
    $repo = Repository::factory()->create(['code' => 'SCE12']);
    $this->actingAs(sce_admin($repo));
    sce_define($repo, 'location', 'floor', 'Floor');

    // The two halves share one header row, so the clash check has to see both.
    expect(ColumnLabels::clashesWith('location', 'code', 'Floor', $repo->id))->toBeTrue()
        ->and(ColumnLabels::clashesWith('location', 'code', 'floor  ', $repo->id))->toBeTrue();
});
