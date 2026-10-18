<?php

declare(strict_types=1);

use App\Filament\Resources\BoxResource\Pages\ListBoxes;
use App\Filament\Resources\BoxResource\Pages\ViewBox;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\DocumentIdentifierHistory;
use App\Models\DocumentType;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Client 2026-10-18, on the Documents and Boxes lists:
 *   "Identifier should not be mandatory to be shown", "Barcode (IN) should not
 *    be an option in documents", "Disinfested should be changed to Disinfested
 *    Date (05/08/2023)", "Notary — show Citing Reference Code (Actual Authority
 *    Reference Code) and Creator with the Name and Surname", "Document Type —
 *    show the label", "Series — REG: Registers", "Tracking / Museum Location /
 *    … not found here"; Boxes: "Remove disinfestation date", "Tracking, Seal
 *    Number, Box Note not found", a destroyed box showing no location while
 *    its documents are in Archive 15, and In Situ boxes showing status IN.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    bl_seedShieldPermissions();
    $this->repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    $user = User::factory()->create(['is_active' => true, 'default_repository_id' => $this->repo->id]);
    $user->assignRole('super_admin');
    $this->actingAs($user);
    $this->batch = Batch::withoutGlobalScopes()->create(['batch_number' => '28', 'type' => 'MAIN_COLLECTION', 'repository_id' => $this->repo->id]);
    $this->archive15 = Location::withoutGlobalScopes()->create(['name' => 'Archive 15', 'code' => 'ROOM-1-30', 'type' => 'room', 'repository_id' => $this->repo->id, 'depth' => 0, 'is_active' => true]);
});

function lcf_document(array $attrs = []): Document
{
    return Document::withoutGlobalScopes()->create(array_merge([
        'identifier' => 'NRA-REG-' . strtoupper(substr(uniqid(), -8)),
        'repository_id' => test()->repo->id,
        'batch_id' => test()->batch->id,
        'series_id' => Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true, 'repository_id' => test()->repo->id])->id,
    ], $attrs));
}

it('shows the authority as Citing Reference Code and Creator, the type by name, the series as CODE: Title', function (): void {
    $series = Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true, 'repository_id' => $this->repo->id]);
    $type = DocumentType::create(['name' => 'Index', 'identifier' => 'DT00057']);
    $doc = lcf_document(['series_id' => $series->id, 'document_type' => 'DT00057', 'document_type_id' => $type->id, 'disinfestation_date' => '2023-08-05']);
    $doc->authorities()->attach(Authority::create(['identifier' => 'NAM-1', 'alternative_identifier' => 'R358/A', 'alternative_identifier_warrant' => '631', 'surname' => 'Abela', 'given_names' => 'Giuseppe', 'entity_type' => 'PERSON'])->id);

    Livewire::test(ListDocuments::class)
        ->assertTableColumnFormattedStateSet('document_type', 'Index', $doc)
        ->assertTableColumnFormattedStateSet('series.code', 'REG: Registers', $doc)
        ->assertTableColumnStateSet('creator_names', ['Giuseppe Abela'], $doc)
        ->assertTableColumnStateSet('authorities.alternative_identifier', ['R358/A'], $doc)
        ->assertTableColumnStateSet('authorities.alternative_identifier_warrant', ['631'], $doc)
        ->assertTableColumnFormattedStateSet('disinfestation_date', '05/08/2023', $doc)
        ->assertTableColumnExists('disinfestation_date', fn ($column): bool => $column->getLabel() === 'Disinfested Date')
        ->assertTableColumnDoesNotExist('barcode_in')
        ->assertTableColumnDoesNotExist('authorities.surname');
});

it('offers every column of the documents sheet, hidden until picked, and can hide the identifier', function (): void {
    $doc = lcf_document(['tracking' => 'Brought to NRA 07/08/2025 by MPA', 'museum_location' => 'MUSE-1-37', 'digitised' => 'NRA', 'accession_code_legacy' => 'Notary Tabone Accession', 'museum_reference' => 'R218/11', 'object_reference_number' => '96/0116/GDG/AAV', 'citation_reference' => 'Sharjah Exhibition']);
    DocumentIdentifierHistory::create(['document_id' => $doc->id, 'previous_identifier' => '70', 'previous_volume' => '3', 'new_identifier' => $doc->identifier, 'repository_id' => $this->repo->id, 'reason' => 'Imported — previously attributed']);

    $page = Livewire::test(ListDocuments::class);
    foreach (['identifier', 'tracking', 'museum_location', 'digitised', 'accession_code_legacy', 'museum_reference', 'object_reference_number', 'citation_reference', 'authorities.alternative_identifier_warrant', 'prev_attributed_identifier', 'prev_attributed_volume', 'dates_year_start', 'dates_year_end'] as $column) {
        $page->assertTableColumnExists($column, fn ($c): bool => $c->isToggleable() && $c->isToggledHiddenByDefault());
    }
    $page->assertTableColumnStateSet('tracking', 'Brought to NRA 07/08/2025 by MPA', $doc)
        ->assertTableColumnStateSet('accession_code_legacy', 'Notary Tabone Accession', $doc)
        ->assertTableColumnStateSet('prev_attributed_identifier', '70', $doc)
        ->assertTableColumnStateSet('prev_attributed_volume', '3', $doc);
});

it('shows a box without a location where all its documents are, and no status for a box without a barcode', function (): void {
    // The documents were placed before the box was destroyed (a document
    // cannot be put in a destroyed box), and the box keeps no location.
    $destroyed = Box::withoutGlobalScopes()->create(['box_type' => 'RAS', 'box_number' => '147', 'batch_id' => $this->batch->id, 'barcode' => 'AC13696', 'barcode_status' => 'PERM_OUT']);
    lcf_document(['current_box_id' => $destroyed->id, 'location_id' => $this->archive15->id]);
    lcf_document(['current_box_id' => $destroyed->id, 'location_id' => $this->archive15->id]);
    Box::withoutGlobalScopes()->whereKey($destroyed->id)->update(['destroyed_at' => now(), 'location_id' => null]);
    $destroyed->refresh();
    $nra = Box::withoutGlobalScopes()->create(['box_type' => 'NRA', 'box_number' => '202', 'batch_id' => null, 'repository_id' => $this->repo->id, 'is_legacy' => true, 'provenance_unknown' => true, 'seal_number' => 'S-1', 'notes' => 'Steel cabinet', 'tracking_note' => 'Taken to Comtec']);

    Livewire::test(ListBoxes::class)
        ->assertTableColumnStateSet('location.full_path', 'Archive 15', $destroyed)
        ->assertTableColumnFormattedStateSet('barcode_status', 'PERM_OUT', $destroyed)
        ->assertTableColumnFormattedStateSet('barcode_status', '—', $nra)
        ->assertTableColumnStateSet('seal_number', 'S-1', $nra)
        ->assertTableColumnStateSet('notes', 'Steel cabinet', $nra)
        ->assertTableColumnStateSet('tracking_note', 'Taken to Comtec', $nra)
        ->assertTableColumnDoesNotExist('disinfestation_date');
});

it('says the same on the box page: no status without a barcode, the documents\' location', function (): void {
    $nra = Box::withoutGlobalScopes()->create(['box_type' => 'NRA', 'box_number' => '202', 'batch_id' => null, 'repository_id' => $this->repo->id, 'is_legacy' => true, 'provenance_unknown' => true]);
    lcf_document(['current_box_id' => $nra->id, 'location_id' => $this->archive15->id]);
    // Destroyed afterwards, so the box keeps no location of its own.
    Box::withoutGlobalScopes()->whereKey($nra->id)->update(['destroyed_at' => now(), 'location_id' => null]);

    Livewire::test(ViewBox::class, ['record' => $nra->getKey()])
        ->assertSchemaStateSet(['barcode_status' => null, 'location.full_path' => $this->archive15->fresh()->full_path])
        ->assertSee('Archive 15')
        ->assertSee('From its documents');
});
