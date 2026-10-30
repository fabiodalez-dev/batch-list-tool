<?php

declare(strict_types=1);

use App\Filament\Imports\BoxImporter;
use App\Filament\Pages\ImportWizard;
use App\Filament\Resources\BatchResource\Pages\EditBatch;
use App\Filament\Resources\BatchResource\Pages\ViewBatch;
use App\Filament\Resources\BoxResource\Pages\CreateBox;
use App\Filament\Resources\BoxResource\Pages\EditBox;
use App\Filament\Resources\BoxResource\Pages\ViewBox;
use App\Filament\Resources\LocationResource\Pages\EditLocation;
use App\Filament\Resources\LocationResource\Pages\ViewLocation;
use App\Filament\Resources\RepositoryResource\Pages\EditRepository;
use App\Filament\Resources\RepositoryResource\Pages\ViewRepository;
use App\Filament\Resources\SeriesResource\Pages\EditSeries;
use App\Filament\Resources\SeriesResource\Pages\ListSeries;
use App\Filament\Resources\SeriesResource\Pages\ViewSeries;
use App\Filament\Support\SearchableSelects;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Location;
use App\Models\Lookup\CurrentBoxType;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\Entry;
use Filament\Schemas\Components\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Client 2026-10-30, "BatchListIssues30.docx", issues 9–15 and the general
 * comment on View / Edit: condensed sections, the same on View and Edit, the
 * Boxes form, subseries counts and dropdowns that open on their options.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    bl_seedShieldPermissions();
    $this->repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    $this->user = User::factory()->create(['is_active' => true, 'default_repository_id' => $this->repo->id]);
    $this->user->assignRole('super_admin');
    $this->actingAs($this->user);
    $this->batch = Batch::withoutGlobalScope(RepositoryScope::class)->firstOrCreate(['batch_number' => '19', 'repository_id' => $this->repo->id], ['type' => 'MAIN_COLLECTION']);
    $this->batch33 = Batch::withoutGlobalScope(RepositoryScope::class)->firstOrCreate(['batch_number' => '33', 'repository_id' => $this->repo->id], ['type' => 'MAIN_COLLECTION']);
});

/**
 * Visible top-level sections of a page schema: heading => collapsed.
 *
 * @return array<string, bool>
 */
function l30_sections(Testable $lw, string $schema): array
{
    $out = [];
    foreach ($lw->instance()->getSchema($schema)->getComponents() as $component) {
        if ($component instanceof Section) {
            $out[(string) $component->getHeading()] = $component->isCollapsed();
        }
    }

    return $out;
}

/**
 * Names of the visible fields / entries inside one top-level section.
 *
 * @return list<string>
 */
function l30_names(Testable $lw, string $schema, string $heading): array
{
    foreach ($lw->instance()->getSchema($schema)->getComponents() as $component) {
        if ($component instanceof Section && (string) $component->getHeading() === $heading) {
            $names = [];
            foreach ($component->getChildSchema()->getFlatComponents() as $child) {
                if ($child instanceof Field || $child instanceof Entry) {
                    $names[] = $child->getName();
                }
            }

            return $names;
        }
    }

    return [];
}

function l30_field(Testable $lw, string $name): Field
{
    return $lw->instance()->getSchema('form')->getFlatFields(withHidden: true)[$name];
}

function l30_box(array $attrs): Box
{
    return Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->create(array_merge([
        'is_legacy' => false, 'barcode_status' => 'IN',
    ], $attrs));
}

// ---------------------------------------------------------------------------
// Issues 9, 10, 12, 13, 14 + general comment — one section, Audit info apart.
// ---------------------------------------------------------------------------

it('condenses the Repository view and edit into one Details section, audit info closed (issue 9)', function (): void {
    $view = Livewire::test(ViewRepository::class, ['record' => $this->repo->getKey()]);
    expect(l30_sections($view, 'infolist'))->toBe(['Details' => false, 'Audit info' => true])
        ->and(l30_names($view, 'infolist', 'Details'))->toContain('code', 'name', 'is_active', 'batches_count', 'documents_count', 'description');

    $edit = Livewire::test(EditRepository::class, ['record' => $this->repo->getKey()]);
    expect(l30_sections($edit, 'form'))->toBe(['Details' => false])
        ->and(l30_names($edit, 'form', 'Details'))->toBe(['code', 'name', 'is_active', 'description']);
});

it('condenses the Location view and edit into one Details section in the same order (issue 10)', function (): void {
    $location = Location::factory()->create(['repository_id' => $this->repo->id, 'name' => 'Archive 1']);

    $view = Livewire::test(ViewLocation::class, ['record' => $location->getKey()]);
    expect(l30_sections($view, 'infolist'))->toBe(['Details' => false, 'Audit info' => true])
        ->and(l30_names($view, 'infolist', 'Details'))->toBe(['repository.code', 'type', 'name', 'code', 'is_active', 'boxes_count', 'documents_count', 'notes']);

    $edit = Livewire::test(EditLocation::class, ['record' => $location->getKey()]);
    expect(l30_sections($edit, 'form'))->toBe(['Details' => false])
        ->and(l30_names($edit, 'form', 'Details'))->toBe(['repository_id', 'type', 'name', 'code', 'is_active', 'notes']);
});

it('shows the Repository on the Subseries view, Level of description with the identification, inputter and date in Audit info (issue 12)', function (): void {
    $series = Series::create([
        'code' => 'REG', 'title' => 'Registers', 'is_active' => true, 'repository_id' => $this->repo->id,
        'level_of_description' => 'Subseries', 'name_of_inputter' => 'Charlene', 'date_of_creation' => '1607-1629',
    ]);

    $view = Livewire::test(ViewSeries::class, ['record' => $series->getKey()]);
    expect(l30_sections($view, 'infolist'))->toBe(['Details' => false, 'Audit info' => true])
        ->and(array_slice(l30_names($view, 'infolist', 'Details'), 0, 4))->toBe(['code', 'title', 'level_of_description', 'repository.code'])
        ->and(l30_names($view, 'infolist', 'Audit info'))->toContain('name_of_inputter', 'date_of_creation');
    $view->assertSeeText('Repository')->assertSeeText('NRA')->assertDontSeeText('Archival metadata');

    // The form has the same two parts, the same fields where they are on the view.
    $edit = Livewire::test(EditSeries::class, ['record' => $series->getKey()]);
    expect(l30_sections($edit, 'form'))->toBe(['Details' => false, 'Audit info' => true])
        ->and(array_slice(l30_names($edit, 'form', 'Details'), 0, 4))->toBe(['code', 'title', 'level_of_description', 'repository_id'])
        ->and(l30_names($edit, 'form', 'Audit info'))->toBe(['name_of_inputter', 'date_of_creation']);

    $edit->fillForm(['level_of_description' => 'Series', 'name_of_inputter' => 'Fabio'])->call('save')->assertHasNoFormErrors();
    expect($series->fresh()->level_of_description)->toBe('Series')
        ->and($series->fresh()->name_of_inputter)->toBe('Fabio');
});

it('keeps the sheet inputter visible to a non super admin, in Audit info (issue 12)', function (): void {
    $admin = User::factory()->create(['is_active' => true, 'default_repository_id' => $this->repo->id]);
    $admin->assignRole('admin');
    $this->actingAs($admin);
    $series = Series::create(['code' => 'REG', 'title' => 'Registers', 'is_active' => true, 'repository_id' => $this->repo->id, 'name_of_inputter' => 'Charlene']);

    $view = Livewire::test(ViewSeries::class, ['record' => $series->getKey()]);
    expect(l30_sections($view, 'infolist'))->toHaveKey('Audit info')
        ->and(l30_names($view, 'infolist', 'Audit info'))->toBe(['name_of_inputter', 'date_of_creation']);
});

it('condenses the Batch view with its Repository shown, and the edit in the same order (issue 13)', function (): void {
    $view = Livewire::test(ViewBatch::class, ['record' => $this->batch->getKey()]);
    expect(l30_sections($view, 'infolist'))->toBe(['Details' => false, 'Audit info' => true])
        ->and(l30_names($view, 'infolist', 'Details'))->toBe(['batch_number', 'type', 'repository.code', 'is_active', 'boxes_count', 'documents_count', 'accessions.code', 'description']);
    $view->assertSeeText('Repository')->assertSeeText('NRA')->assertDontSeeText('Scope & status');

    $edit = Livewire::test(EditBatch::class, ['record' => $this->batch->getKey()]);
    expect(l30_sections($edit, 'form'))->toBe(['Details' => false])
        ->and(l30_names($edit, 'form', 'Details'))->toBe(['batch_number', 'type', 'repository_id', 'is_active', 'accessions', 'description']);
});

it('condenses the Box view into one Details section with Audit info on its own (issue 14)', function (): void {
    $box = l30_box(['box_type' => 'RAS', 'box_number' => '7', 'batch_id' => $this->batch->id, 'barcode' => 'AA0007', 'current_box_type' => 'RAS Box', 'notes' => 'n']);

    $view = Livewire::test(ViewBox::class, ['record' => $box->getKey()]);
    expect(l30_sections($view, 'infolist'))->toBe(['Details' => false, 'Audit info' => true]);
    $names = l30_names($view, 'infolist', 'Details');
    expect(array_slice($names, 0, 4))->toBe(['box_type', 'box_number', 'batch.batch_number', 'current_box_type'])
        ->and($names)->toContain('barcode', 'seal_number', 'barcode_status', 'location.full_path', 'documents_count', 'notes', 'tracking_note')
        ->and($names)->not->toContain('destroyed_at');
    $view->assertSeeText('RAS Box')->assertDontSeeText('Barcode & status');

    $box->markDestroyed('Flood', $this->user->id);
    $destroyed = Livewire::test(ViewBox::class, ['record' => $box->getKey()]);
    expect(l30_names($destroyed, 'infolist', 'Details'))->toContain('destroyed_at', 'destroyedBy.name', 'destroyed_reason');
});

it('lays the Box edit out like its view, with the legacy histories after it (general comment)', function (): void {
    $box = l30_box(['box_type' => 'RAS', 'box_number' => '7', 'batch_id' => $this->batch->id, 'barcode' => 'AA0007']);

    $edit = Livewire::test(EditBox::class, ['record' => $box->getKey()]);
    expect(l30_sections($edit, 'form'))->toBe(['Details' => false, 'Barcode legacy / history' => true, 'Seal legacy / history' => true]);
    $names = l30_names($edit, 'form', 'Details');
    expect(array_slice($names, 0, 4))->toBe(['box_type', 'box_number', 'batch_id', 'current_box_type'])
        ->and($names)->toContain('barcode', 'seal_number', 'barcode_status', 'location_id', 'notes', 'tracking_note', 'destroyed_at', 'destroyed_reason');
});

// ---------------------------------------------------------------------------
// Issue 15 — the Boxes form.
// ---------------------------------------------------------------------------

it('offers the Physical Box Type lookup as the Current Box Type dropdown, on create and edit (issue 15)', function (): void {
    CurrentBoxType::create(['code' => 'Conservation Box', 'label' => 'Conservation Box', 'is_active' => true, 'sort_order' => 9, 'counts_as' => 1]);

    $create = Livewire::test(CreateBox::class)->assertFormFieldVisible('current_box_type');
    $select = l30_field($create, 'current_box_type');
    expect($select)->toBeInstanceOf(Select::class)
        ->and($select->isSearchable())->toBeTrue()
        ->and($select->getOptions())->toHaveKeys(['RAS Box', 'Big Brown Box', 'Small Brown Box', 'Conservation Box']);

    // A legacy free-text value on an existing box stays selectable, so an edit loses nothing.
    $box = l30_box(['box_type' => 'RAS', 'box_number' => '8', 'batch_id' => $this->batch->id, 'barcode' => 'AA0008', 'current_box_type' => 'Standard Blue Box - Mould']);
    $edit = Livewire::test(EditBox::class, ['record' => $box->getKey()])->assertFormFieldVisible('current_box_type');
    expect(l30_field($edit, 'current_box_type')->getOptions())->toHaveKey('Standard Blue Box - Mould');
    $edit->fillForm(['notes' => 'checked'])->call('save')->assertHasNoFormErrors();
    expect($box->fresh()->current_box_type)->toBe('Standard Blue Box - Mould');

    Livewire::test(CreateBox::class)
        ->fillForm(['box_type' => 'RAS', 'batch_id' => $this->batch->id, 'box_number' => '9', 'barcode' => 'AA0009', 'current_box_type' => 'Conservation Box'])
        ->call('create')
        ->assertHasNoFormErrors();
    expect(Box::query()->where('box_number', '9')->value('current_box_type'))->toBe('Conservation Box');
});

it('puts Box type and Box identifier side by side (issue 15)', function (): void {
    $lw = Livewire::test(CreateBox::class)->fillForm(['box_type' => 'NRA']);
    expect(array_slice(l30_names($lw, 'form', 'Details'), 0, 2))->toBe(['box_type', 'box_number']);
    $lw->assertSeeText('Box identifier');
});

it('shows the barcode status for RAS boxes only, and keeps a stored status on another type (issue 15)', function (): void {
    Livewire::test(CreateBox::class)
        ->fillForm(['box_type' => 'RAS'])->assertFormFieldVisible('barcode_status')
        ->fillForm(['box_type' => 'NRA'])->assertFormFieldHidden('barcode_status')
        ->fillForm(['box_type' => 'IN_SITU'])->assertFormFieldHidden('barcode_status');

    $ras = l30_box(['box_type' => 'RAS', 'box_number' => '1', 'batch_id' => $this->batch->id, 'barcode' => 'AA0001']);
    $nra = l30_box(['box_type' => 'NRA', 'box_number' => 'NRA1', 'batch_id' => null, 'parent_box_id' => $ras->id, 'barcode_status' => 'PERM_OUT']);

    Livewire::test(EditBox::class, ['record' => $nra->getKey()])
        ->assertFormFieldHidden('barcode_status')
        ->fillForm(['notes' => 'edited'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($nra->fresh()->barcode_status)->toBe('PERM_OUT');
});

it('shows no legacy or destruction features on a new box, and keeps them on edit (issue 15)', function (): void {
    $create = Livewire::test(CreateBox::class)->fillForm(['box_type' => 'RAS']);
    expect(l30_sections($create, 'form'))->toBe(['Details' => false]);
    $create->assertFormFieldHidden('destroyed_at')->assertFormFieldHidden('destroyed_reason')->assertFormFieldHidden('is_legacy')
        ->assertDontSeeText('Barcode legacy / history')->assertDontSeeText('Seal legacy / history');

    $box = l30_box(['box_type' => 'RAS', 'box_number' => '2', 'batch_id' => $this->batch->id, 'barcode' => 'AA0002']);
    Livewire::test(EditBox::class, ['record' => $box->getKey()])
        ->assertFormFieldVisible('destroyed_at')
        ->assertFormFieldVisible('destroyed_reason')
        ->assertSeeText('Barcode legacy / history')
        ->assertSeeText('Seal legacy / history');
});

it('picks the parent RAS box by batch first, then a box of that batch with its status (issue 15)', function (): void {
    $p1 = l30_box(['box_type' => 'RAS', 'box_number' => '1', 'batch_id' => $this->batch33->id, 'barcode' => 'B33-1', 'barcode_status' => 'PERM_OUT']);
    $p2 = l30_box(['box_type' => 'RAS', 'box_number' => '10', 'batch_id' => $this->batch33->id, 'barcode' => 'B33-10']);
    $other = l30_box(['box_type' => 'RAS', 'box_number' => '1', 'batch_id' => $this->batch->id, 'barcode' => 'B19-1']);

    $lw = Livewire::test(CreateBox::class)->fillForm(['box_type' => 'NRA']);
    expect(l30_field($lw, 'parent_batch_id')->getOptions())->toHaveKeys([$this->batch->id, $this->batch33->id]);

    $lw->fillForm(['parent_batch_id' => $this->batch33->id]);
    expect(l30_field($lw, 'parent_box_id')->getOptions())->toBe([
        $p1->id => 'Box 1 — RAS — PERM_OUT',
        $p2->id => 'Box 10 — RAS — IN',
    ]);

    $lw->fillForm(['parent_box_id' => $p1->id, 'box_number' => 'NRA7'])->call('create')->assertHasNoFormErrors();
    $created = Box::withoutGlobalScopes()->where('box_number', 'NRA7')->firstOrFail();
    expect((int) $created->parent_box_id)->toBe($p1->id);

    // Edit opens on the parent's batch; switching batch drops a parent that is not in it.
    $edit = Livewire::test(EditBox::class, ['record' => $created->getKey()])
        ->assertFormSet(['parent_batch_id' => $this->batch33->id, 'parent_box_id' => $p1->id]);
    $edit->fillForm(['parent_batch_id' => $this->batch->id])->assertFormSet(['parent_box_id' => null]);
    expect(l30_field($edit, 'parent_box_id')->getOptions())->toBe([$other->id => 'Box 1 — RAS — IN']);

    // A non-RAS box cannot be posted as the parent.
    Livewire::test(CreateBox::class)
        ->fillForm(['box_type' => 'NRA', 'box_number' => 'NRA8', 'parent_box_id' => $created->id])
        ->call('create')
        ->assertHasFormErrors(['parent_box_id']);
});

it('refuses a second box with the same type and number with no batch, in the form (issue 15)', function (): void {
    $ras = l30_box(['box_type' => 'RAS', 'box_number' => '1', 'batch_id' => $this->batch->id, 'barcode' => 'AA0001']);
    l30_box(['box_type' => 'NRA', 'box_number' => '500', 'batch_id' => null, 'parent_box_id' => $ras->id]);

    Livewire::test(CreateBox::class)
        ->fillForm(['box_type' => 'NRA', 'box_number' => '500', 'provenance_unknown' => true])
        ->call('create')
        ->assertHasFormErrors(['box_number']);
    expect(Box::withoutGlobalScopes()->where('box_number', '500')->count())->toBe(1);

    // Another type, or a batch, makes it a different box.
    Livewire::test(CreateBox::class)
        ->fillForm(['box_type' => 'IN_SITU', 'box_number' => '500', 'provenance_unknown' => true])
        ->call('create')
        ->assertHasNoFormErrors();
    Livewire::test(CreateBox::class)
        ->fillForm(['box_type' => 'NRA', 'box_number' => '500', 'batch_id' => $this->batch->id, 'provenance_unknown' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    // Within a batch the same rule holds.
    Livewire::test(CreateBox::class)
        ->fillForm(['box_type' => 'RAS', 'box_number' => '1', 'batch_id' => $this->batch->id, 'barcode' => 'ZZ0001'])
        ->call('create')
        ->assertHasFormErrors(['box_number']);
});

it('refuses the duplicate at model level too, null batch included, so no path can write it (issue 15)', function (): void {
    l30_box(['box_type' => 'NRA', 'box_number' => '500', 'batch_id' => null, 'repository_id' => $this->repo->id, 'provenance_unknown' => true]);

    expect(fn () => l30_box(['box_type' => 'NRA', 'box_number' => '500', 'batch_id' => null, 'repository_id' => $this->repo->id, 'provenance_unknown' => true]))
        ->toThrow(ValidationException::class, 'There is already a NRA box 500 with no batch');

    // Another repository has its own NRA 500.
    $other = Repository::create(['code' => 'OTH', 'name' => 'Other']);
    l30_box(['box_type' => 'NRA', 'box_number' => '500', 'batch_id' => null, 'repository_id' => $other->id, 'provenance_unknown' => true]);

    // In a batch.
    l30_box(['box_type' => 'RAS', 'box_number' => '3', 'batch_id' => $this->batch->id, 'barcode' => 'AA0003']);
    expect(fn () => l30_box(['box_type' => 'RAS', 'box_number' => '3', 'batch_id' => $this->batch->id, 'barcode' => 'AA0333']))
        ->toThrow(ValidationException::class, 'in batch 19');

    // Renaming an existing box onto a taken identity is refused; an unrelated edit is not.
    $nra2 = l30_box(['box_type' => 'NRA', 'box_number' => '501', 'batch_id' => null, 'repository_id' => $this->repo->id, 'provenance_unknown' => true]);
    expect(fn () => $nra2->update(['box_number' => '500']))->toThrow(ValidationException::class);
    $nra2->refresh()->update(['notes' => 'fine']);
    expect($nra2->fresh()->notes)->toBe('fine');

    // A deleted box does not hold its identity.
    $nra2->delete();
    l30_box(['box_type' => 'NRA', 'box_number' => '501', 'batch_id' => null, 'repository_id' => $this->repo->id, 'provenance_unknown' => true]);
    expect(fn () => $nra2->restore())->toThrow(ValidationException::class);
});

it('updates the existing batch-less box from an import row instead of failing or duplicating it (issue 15)', function (): void {
    $existing = l30_box(['box_type' => 'NRA', 'box_number' => '500', 'batch_id' => null, 'repository_id' => $this->repo->id, 'provenance_unknown' => true]);

    EntityResolver::flushMemo();
    $row = ['Box type' => 'NRA', 'Box number' => '500', 'Batch number' => '', 'Parent box number' => '', 'Barcode status' => '', 'Is legacy' => 'No', 'Provenance unknown' => 'Yes', 'Tracking note' => 'from the sheet'];
    $import = Import::query()->create([
        'completed_at' => null, 'file_name' => 'f.xlsx', 'file_path' => '/tmp/f.xlsx',
        'importer' => BoxImporter::class, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $this->user->id,
    ]);
    (new BoxImporter($import, ImportWizard::guessColumnMap(BoxImporter::class, array_keys($row)), []))($row);

    expect(Box::withoutGlobalScopes()->where('box_type', 'NRA')->where('box_number', '500')->count())->toBe(1)
        ->and($existing->fresh()->tracking_note)->toBe('from the sheet');
});

// ---------------------------------------------------------------------------
// Issue 12 — counts; issue 11 — Parent column.
// ---------------------------------------------------------------------------

it('counts the documents of a series together with those of its subseries (issue 12)', function (): void {
    $r = Series::create(['code' => 'R', 'title' => 'Registers', 'is_active' => true, 'repository_id' => $this->repo->id]);
    $reg = Series::create(['code' => 'REG', 'title' => 'Registers', 'is_active' => true, 'repository_id' => $this->repo->id, 'parent_id' => $r->id]);
    $rwl = Series::create(['code' => 'RWL', 'title' => 'Wills', 'is_active' => true, 'repository_id' => $this->repo->id, 'parent_id' => $reg->id]);
    Document::factory()->count(1)->create(['series_id' => $r->id, 'repository_id' => $this->repo->id]);
    Document::factory()->count(2)->create(['series_id' => $reg->id, 'repository_id' => $this->repo->id]);
    Document::factory()->count(3)->create(['series_id' => $rwl->id, 'repository_id' => $this->repo->id]);

    expect($r->documentsIncludingDescendantsCount())->toBe(6)
        ->and($reg->documentsIncludingDescendantsCount())->toBe(5)
        ->and($rwl->documentsIncludingDescendantsCount())->toBe(3);

    Livewire::test(ViewSeries::class, ['record' => $r->getKey()])
        ->assertSeeText('Documents (this subseries only)')
        ->assertSeeText('Documents (incl. subseries below)')
        ->assertSeeInOrder(['Documents (this subseries only)', '1', 'Documents (incl. subseries below)', '6']);
});

it('drops the Parent column from the Subseries list, the Hierarchy shows it (issue 11)', function (): void {
    Livewire::test(ListSeries::class)
        ->assertTableColumnDoesNotExist('parent.code')
        ->assertTableColumnExists('hierarchy_path');
});

// ---------------------------------------------------------------------------
// General comment — dropdowns open on their options and filter as you type.
// ---------------------------------------------------------------------------

it('opens the server-searched dropdowns on their first options, and filters as you type', function (): void {
    $select = SearchableSelects::batch('batch_id', 'batch');
    expect($select->isPreloaded())->toBeTrue()
        ->and($select->isSearchable())->toBeTrue();

    $lw = Livewire::test(CreateBox::class);
    $batchSelect = l30_field($lw, 'batch_id');
    expect($batchSelect->getOptions())->toHaveKeys([$this->batch->id, $this->batch33->id])
        ->and($batchSelect->getSearchResults('33'))->toBe([$this->batch33->id => 'Batch 33 — MAIN_COLLECTION']);

    // The small lookups are searchable lists, not type-only fields.
    foreach (['box_type', 'current_box_type'] as $name) {
        expect(l30_field($lw, $name)->isSearchable())->toBeTrue();
    }
});
