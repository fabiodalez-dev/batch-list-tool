<?php

declare(strict_types=1);

use App\Filament\Pages\ImportWizard;
use App\Filament\Resources\AccessionResource\Pages\ListAccessions;
use App\Filament\Resources\AuthorityResource\Pages\ListAuthorities;
use App\Filament\Resources\BatchResource\Pages\ListBatches;
use App\Filament\Resources\BoxResource\Pages\ListBoxes;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\DocumentTypeResource\Pages\ListDocumentTypes;
use App\Filament\Resources\LocationResource\Pages\ListLocations;
use App\Filament\Resources\SeriesResource\Pages\ListSeries;
use App\Filament\Resources\VolumeResource\Pages\ListVolumes;
use App\Models\Accession;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\ColumnLabelOverride;
use App\Models\CustomFieldDefinition;
use App\Models\CustomFieldValue;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Models\Volume;
use App\Support\BulkImport\EntityResolver;
use App\Support\ColumnLabels\ColumnLabels;
use App\Support\CustomFields\CustomFieldResolver;
use App\Support\Export\EntityExport;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Export, delete, re-import, export again: the two files must be identical.
 *
 * This is the whole point of exporting in the template's shape. Every column
 * of the first file has to travel through the real importer and come back as
 * the same value, or the second file differs. Deleting the records first means
 * nothing survives by accident: everything in the second file was rebuilt from
 * the first one.
 *
 * The export is taken from the list's own Export CSV button, and the file is
 * read back with the import wizard's own reader (readCsvForImport), the single
 * place sheets are read — so the formula-guard quote the export adds is undone
 * exactly where a real re-import would undo it.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $this->repo = Repository::factory()->create(['code' => 'ERT']);
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $this->repo->id]);
    $u->assignRole('super_admin');
    $this->actingAs($u);
    $this->user = $u;
});

function ert_export(string $page): string
{
    $component = Livewire::test($page)->callAction('export_csv');
    $download = $component->effects['download'] ?? null;
    expect($download)->not->toBeNull("{$page}: Export CSV produced no download");

    return (string) base64_decode((string) $download['content'], true);
}

/**
 * Read the exported file back the way the import wizard reads a sheet, and run
 * every row through the importer with the column map the wizard would guess.
 */
function ert_reimport(string $entity, string $csv, int $userId): void
{
    $path = tempnam(sys_get_temp_dir(), 'ert');
    file_put_contents((string) $path, $csv);

    $wizard = new ImportWizard;
    $read = new ReflectionMethod($wizard, 'readCsvForImport');
    [$headers, $rows] = $read->invoke($wizard, $path);
    $handle = fopen((string) $path, 'r');
    fclose($handle);

    $importerClass = EntityExport::importers()[$entity];
    $map = ImportWizard::guessColumnMap($importerClass, $headers);

    EntityResolver::flushMemo();
    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => null, 'file_name' => 'reimport.csv', 'file_path' => (string) $path,
        'importer' => $importerClass, 'processed_rows' => 0, 'total_rows' => count($rows),
        'successful_rows' => 0, 'user_id' => $userId,
    ]);

    foreach ($rows as $row) {
        (new $importerClass($import, array_filter($map), []))($row);
    }
}

/**
 * @return list<string>
 */
function ert_lines(string $csv): array
{
    return array_values(array_filter(explode("\n", str_replace("\r", '', $csv)), static fn (string $l): bool => $l !== ''));
}

function ert_batch(int $repoId, string $number): Batch
{
    return Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => $number, 'type' => 'MAIN_COLLECTION', 'repository_id' => $repoId, 'is_active' => true,
        'description' => 'Batch ' . $number . ' — Għargħur',
    ]);
}

function ert_survives(string $entity, string $page, Closure $delete, int $userId, int $expectedRows): void
{
    $first = ert_export($page);
    $lines = ert_lines($first);

    expect(count($lines) - 1)->toBe($expectedRows, "{$entity}: the first export has the wrong number of rows");
    expect(str_getcsv(preg_replace('/^\x{FEFF}/u', '', $lines[0]) ?? '', escape: '\\'))
        ->toBe(EntityExport::headers($entity), "{$entity}: the header row is not the template's");

    $delete();
    ert_reimport($entity, $first, $userId);

    $second = ert_export($page);

    expect(ert_lines($second))->toBe($lines, "{$entity}: the re-imported records do not export the same");
}

it('round-trips subseries', function (): void {
    $parent = Series::create(['code' => 'ERTP', 'title' => 'Parent subseries', 'is_active' => true, 'repository_id' => $this->repo->id]);
    // Description and the wills flag are not template columns: the export adds them.
    Series::create(['code' => 'ERTC', 'title' => 'Child — notes, with a comma', 'is_active' => true, 'parent_id' => $parent->id, 'repository_id' => $this->repo->id, 'description' => 'Legacy label 7', 'is_wills_series' => true]);

    ert_survives('series', ListSeries::class, fn () => Series::query()->whereIn('code', ['ERTP', 'ERTC'])->orderByDesc('id')->get()->each->forceDelete(), $this->user->id, 2);
});

it('round-trips authorities, year ranges included', function (): void {
    Authority::create([
        'identifier' => 'R9001', 'alternative_identifier' => 'CRC-9001', 'surname' => 'Abela', 'given_names' => 'Ġużeppi',
        'entity_type' => 'Notary', 'practice_dates_start' => 1701, 'practice_dates_end' => 1734, 'ntg_dates_start' => 1720, 'ntg_dates_end' => 1725,
        'notes' => '- see the 1734 register',
    ]);
    Authority::create(['identifier' => 'R9002', 'alternative_identifier' => 'CRC-9002', 'surname' => 'Borg', 'given_names' => 'Marija', 'entity_type' => 'Notary']);

    ert_survives('authority', ListAuthorities::class, fn () => Authority::query()->whereIn('identifier', ['R9001', 'R9002'])->get()->each->forceDelete(), $this->user->id, 2);
});

it('round-trips locations, the parent included', function (): void {
    $room = Location::factory()->create(['code' => 'ERT-1', 'name' => 'Archive 1', 'repository_id' => $this->repo->id]);
    Location::factory()->create(['code' => 'ERT-1-45', 'name' => 'Shelf 45', 'parent_id' => $room->id, 'repository_id' => $this->repo->id]);

    ert_survives('location', ListLocations::class, fn () => Location::withoutGlobalScopes()->whereIn('code', ['ERT-1', 'ERT-1-45'])->orderByDesc('id')->get()->each->forceDelete(), $this->user->id, 2);
});

it('round-trips batches', function (): void {
    ert_batch($this->repo->id, '9501');
    ert_batch($this->repo->id, '9502');

    ert_survives('batch', ListBatches::class, fn () => Batch::withoutGlobalScopes()->whereIn('batch_number', ['9501', '9502'])->get()->each->forceDelete(), $this->user->id, 2);
});

it('round-trips boxes — parent, seal, destroyed, tracking note, location and disinfestation included', function (): void {
    $batch = ert_batch($this->repo->id, '9601');
    $room = Location::factory()->create(['code' => 'ERT-B-1', 'name' => 'Box room', 'repository_id' => $this->repo->id]);
    $ras = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create([
        'box_type' => 'RAS', 'box_number' => '61', 'batch_id' => $batch->id, 'barcode' => 'AA960001',
        'barcode_status' => 'IN', 'is_legacy' => false, 'seal_number' => '15329213', 'tracking_note' => 'Checked 2026-09',
        'notes' => '=not a formula', 'current_box_type' => 'RAS Box',
        // Not template columns: the export adds them, the importer reads them.
        'location_id' => $room->id, 'disinfestation_date' => '2025-11-04',
    ]);
    Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create([
        'box_type' => 'IN_SITU', 'box_number' => '62', 'batch_id' => $batch->id, 'barcode_status' => 'IN',
        'is_legacy' => false, 'parent_box_id' => $ras->id,
    ]);

    ert_survives('box', ListBoxes::class, fn () => Box::withoutGlobalScopes()->whereIn('box_number', ['61', '62'])->orderByDesc('id')->get()->each->forceDelete(), $this->user->id, 2);
});

it('round-trips document types', function (): void {
    DocumentType::create(['name' => 'Register', 'identifier' => 'REG', 'description' => 'Bound register', 'is_active' => true]);
    DocumentType::create(['name' => 'Original', 'identifier' => 'ORIG', 'is_active' => false]);

    ert_survives('documentType', ListDocumentTypes::class, fn () => DocumentType::query()->whereIn('identifier', ['REG', 'ORIG'])->get()->each->forceDelete(), $this->user->id, 2);
});

it('round-trips documents — box chain, barcodes, location, creators', function (): void {
    $series = Series::create(['code' => 'ERTS', 'title' => 'Registers', 'is_active' => true, 'repository_id' => $this->repo->id]);
    $batch = ert_batch($this->repo->id, '9701');
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create(['box_type' => 'RAS', 'box_number' => '71', 'batch_id' => $batch->id, 'barcode' => 'AA970001', 'barcode_status' => 'IN', 'is_legacy' => false]);
    $shelf = Location::factory()->create(['code' => 'ERT-2-10', 'name' => 'Shelf 10', 'repository_id' => $this->repo->id]);
    $notary = Authority::create(['identifier' => 'R9701', 'alternative_identifier' => 'CRC-9701', 'surname' => 'Caruana', 'given_names' => 'Vincenzo', 'entity_type' => 'Notary']);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'R9701/001', 'repository_id' => $this->repo->id, 'series_id' => $series->id, 'batch_id' => $batch->id,
        'current_box_id' => $box->id, 'location_id' => $shelf->id, 'document_type' => 'Register', 'catalogue_identifier' => 'CAT-1',
        'barcode_in' => 'AA970001', 'barcode_ras_1' => 'AA05760', 'status_1' => 'PERM_OUT', 'dates' => '1701-1702',
        'deeds' => '1-120', 'notes' => '+ loose leaf', 'part_number' => '2', 'torre' => true, 'museum_reference' => 'MR-9',
        // Not template columns (client feedback F2, and the legacy sheet's
        // per-box Destroyed cells): the export adds them.
        'number_of_acts' => '55', 'pages_folios' => 'ff. 1-120', 'current_box_type' => 'RAS Box',
        'ras_1_box_destroyed' => 'No', 'in_situ_box_2_destroyed' => '2024-03-06',
    ]);
    $doc->authorities()->attach($notary->id);

    ert_survives('document', ListDocuments::class, fn () => Document::withoutGlobalScopes()->whereKey($doc->id)->get()->each->forceDelete(), $this->user->id, 1);
});

it('round-trips volumes', function (): void {
    $series = Series::create(['code' => 'ERTV', 'title' => 'Vols', 'is_active' => true, 'repository_id' => $this->repo->id]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create(['identifier' => 'R9801/001', 'repository_id' => $this->repo->id, 'series_id' => $series->id, 'document_type' => 'Register']);
    Volume::create(['document_id' => $doc->id, 'volume_number' => '3', 'dates_start' => '1701-01-01', 'dates_end' => '1702-12-31', 'notes' => 'Bound with vol. 4']);

    ert_survives('volume', ListVolumes::class, fn () => Volume::query()->where('document_id', $doc->id)->get()->each->forceDelete(), $this->user->id, 1);
});

it('keeps an added column apart from the fixed field it shares a name with', function (): void {
    // "Digitised" is a fixed documents column, "Location" a name the box
    // importer reads as the box's location. Both used to be written under the
    // label, and on re-import the fixed field took the header.
    $series = Series::create(['code' => 'ERTX', 'title' => 'X', 'is_active' => true, 'repository_id' => $this->repo->id]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create(['identifier' => 'R9951/001', 'repository_id' => $this->repo->id, 'series_id' => $series->id, 'document_type' => 'Register', 'digitised' => 'NRA']);
    $docDef = CustomFieldDefinition::create(['repository_id' => $this->repo->id, 'entity_type' => 'document', 'key' => 'scan_done', 'label' => 'Digitised', 'type' => 'boolean', 'is_active' => true, 'sort_order' => 1]);
    CustomFieldValue::create(['custom_field_definition_id' => $docDef->id, 'customizable_type' => $doc->getMorphClass(), 'customizable_id' => $doc->id, 'value' => '1']);

    $batch = ert_batch($this->repo->id, '9951');
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create(['box_type' => 'RAS', 'box_number' => '95', 'batch_id' => $batch->id, 'barcode' => 'AA995001', 'barcode_status' => 'IN', 'is_legacy' => false]);
    $boxDef = CustomFieldDefinition::create(['repository_id' => $this->repo->id, 'entity_type' => 'box', 'key' => 'old_place', 'label' => 'Location', 'type' => 'text', 'is_active' => true, 'sort_order' => 1]);
    CustomFieldValue::create(['custom_field_definition_id' => $boxDef->id, 'customizable_type' => $box->getMorphClass(), 'customizable_id' => $box->id, 'value' => 'Old store, shelf 3']);
    CustomFieldResolver::flush();

    expect(EntityExport::headers('document'))->toContain('cf_scan_done')
        ->and(EntityExport::headers('box'))->toContain('cf_old_place');

    ert_survives('document', ListDocuments::class, fn () => Document::withoutGlobalScopes()->whereKey($doc->id)->get()->each->forceDelete(), $this->user->id, 1);
    ert_survives('box', ListBoxes::class, fn () => Box::withoutGlobalScopes()->whereKey($box->id)->get()->each->forceDelete(), $this->user->id, 1);

    $reimported = Document::withoutGlobalScopes()->where('identifier', 'R9951/001')->firstOrFail();
    expect($reimported->digitised)->toBe('NRA')
        ->and($reimported->getCustomFieldData()['scan_done'] ?? null)->toBeTrue()
        ->and(Box::withoutGlobalScopes()->where('box_number', '95')->firstOrFail()->location_id)->toBeNull();
});

it('writes a renamed column under its new name, and re-imports it', function (): void {
    ColumnLabelOverride::create(['repository_id' => $this->repo->id, 'entity_type' => 'box', 'field_key' => 'barcode', 'label' => 'Etichetta RAS']);
    ColumnLabels::flushMemo();
    $batch = ert_batch($this->repo->id, '9901');
    Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create(['box_type' => 'RAS', 'box_number' => '91', 'batch_id' => $batch->id, 'barcode' => 'AA990001', 'barcode_status' => 'IN', 'is_legacy' => false]);

    $csv = ert_export(ListBoxes::class);
    expect(ert_lines($csv)[0])->toContain('Etichetta RAS')->not->toContain(',Barcode,');

    ert_survives('box', ListBoxes::class, fn () => Box::withoutGlobalScopes()->where('box_number', '91')->get()->each->forceDelete(), $this->user->id, 1);
});

it('round-trips accessions — one row per document, authorities paired', function (): void {
    $series = Series::create(['code' => 'ERTA', 'title' => 'Accession registers', 'is_active' => true, 'repository_id' => $this->repo->id]);
    $notaryA = Authority::create(['identifier' => 'R9901', 'alternative_identifier' => 'CRC-9901', 'surname' => 'Grima', 'given_names' => 'Hugh', 'entity_type' => 'Notary']);
    $notaryB = Authority::create(['identifier' => 'R9902', 'alternative_identifier' => 'CRC-9902', 'surname' => 'Zammit', 'given_names' => 'Ċensu', 'entity_type' => 'Notary']);
    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => '9991', 'type' => 'NOTARY_ACCESSION', 'repository_id' => $this->repo->id, 'is_active' => true]);
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create(['box_type' => 'RAS', 'box_number' => '99', 'batch_id' => $batch->id, 'barcode' => 'AA999001', 'barcode_status' => 'IN', 'is_legacy' => false]);
    $accession = Accession::withoutGlobalScopes()->create(['code' => 'Hugh Grima Accession', 'accession_number' => '2025-124', 'repository_id' => $this->repo->id, 'authority_id' => $notaryA->id]);

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->create([
        'identifier' => 'R9901/014', 'repository_id' => $this->repo->id, 'series_id' => $series->id, 'batch_id' => $batch->id,
        'current_box_id' => $box->id, 'accession_id' => $accession->id, 'document_type' => 'Register', 'volume_number' => '14',
        'dates' => '1801-1803', 'number_of_acts' => 212, 'pages_folios' => '340ff', 'notes' => 'Water damage on f. 12',
    ]);
    $doc->authorities()->attach([$notaryA->id, $notaryB->id]);

    ert_survives('accession', ListAccessions::class, fn () => Document::withoutGlobalScopes()->whereKey($doc->id)->get()->each->forceDelete(), $this->user->id, 1);
});
