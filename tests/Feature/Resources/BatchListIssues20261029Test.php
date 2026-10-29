<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Filament\Resources\BoxResource;
use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\DocumentResource\Pages\ViewDocument;
use App\Filament\Support\MatchQueryBuilder;
use App\Jobs\ImportCsvSlice;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\Location;
use App\Models\Lookup\FlagType;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use Filament\Actions\Imports\Models\Import;
use Filament\Tables\Filters\TrashedFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Client 2026-10-29, "Batch List Issues.docx": flags by name and colour on
 * the document and in the list, filters built only from "Add rule" with
 * AND / OR above the rules and NOT on every rule, a Location rule, the
 * columns that belong to the box import out of the documents wizard, and a
 * row typed again from the template updating its document instead of
 * copying it.
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
    $this->user->givePermissionTo(Permission::firstOrCreate(['name' => 'create_document_flag', 'guard_name' => 'web']));
    $this->actingAs($this->user);
    $this->series = Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true, 'repository_id' => $this->repo->id]);
    FlagType::query()->updateOrCreate(['code' => 'mould_treatment'], ['label' => 'Mould Treatment', 'colour' => 'red', 'is_active' => true, 'sort_order' => 1]);
    FlagType::query()->updateOrCreate(['code' => 'entry_issue'], ['label' => 'Entry Issue', 'colour' => 'pink', 'is_active' => true, 'sort_order' => 2]);
});

function b29_doc(array $attrs = []): Document
{
    $createdAt = $attrs['created_at'] ?? null;
    unset($attrs['created_at']);
    $doc = Document::withoutGlobalScopes()->create(array_merge([
        'identifier' => 'B29-' . strtoupper(substr(uniqid(), -8)),
        'repository_id' => test()->repo->id,
        'series_id' => test()->series->id,
    ], $attrs));
    if ($createdAt !== null) {
        $doc->forceFill(['created_at' => $createdAt])->saveQuietly();
    }

    return $doc;
}

/**
 * @param array<string, mixed> $rules
 */
function b29_list(array $rules, string $match = 'all'): mixed
{
    return Livewire::withQueryParams(['filters' => ['queryBuilder' => ['match' => $match, 'rules' => $rules]]])
        ->test(ListDocuments::class);
}

/**
 * @param array<string, mixed> $settings
 * @return array<string, mixed>
 */
function b29_rule(string $type, string $operator, array $settings = []): array
{
    return ['type' => $type, 'data' => ['operator' => $operator, 'settings' => $settings]];
}

/**
 * @param list<array<string, string>> $rows
 */
function b29_import(array $rows, bool $overwrite): Import
{
    $csv = tempnam(sys_get_temp_dir(), 'b29') . '.csv';
    $f = fopen($csv, 'w');
    fputcsv($f, array_keys($rows[0]));
    $offset = ftell($f);
    foreach ($rows as $row) {
        fputcsv($f, array_values($row));
    }
    fclose($f);

    $map = array_filter(ImportWizard::wizardColumnMap(DocumentImporter::class, ImportWizard::csvHeaderKeys($csv)));
    $import = Import::query()->create(['file_name' => 'd.csv', 'file_path' => $csv, 'importer' => DocumentImporter::class, 'total_rows' => count($rows), 'user_id' => test()->user->id]);
    (new ImportCsvSlice($import, $csv, $offset, 1, count($rows), $map, ['repository_id' => test()->repo->id, 'skip_duplicates' => ! $overwrite]))->handle();
    test()->actingAs(test()->user);

    return $import->refresh();
}

it('shows open flags by name, in their colour, in the list and on the document', function (): void {
    $doc = b29_doc();
    DocumentFlag::create(['document_id' => $doc->id, 'type' => 'mould_treatment', 'title' => 'x']);
    DocumentFlag::create(['document_id' => $doc->id, 'type' => 'entry_issue', 'title' => 'y', 'status' => 'resolved']);

    $html = Livewire::test(ListDocuments::class)->assertSee('Mould Treatment')->assertDontSee('Entry Issue')->html();
    // Red (#dc2626) becomes Filament's oklch palette at hue ~27.
    expect($html)->toMatch('/oklch\([0-9.]+ [0-9.]+ 27\.[0-9]+\)/');

    Livewire::test(ViewDocument::class, ['record' => $doc->getRouteKey()])
        ->assertSee('Mould Treatment')
        ->assertDontSee('Colour code');
});

it('sets the flags by name on the document form, resolving the ones removed', function (): void {
    $doc = b29_doc();
    DocumentFlag::create(['document_id' => $doc->id, 'type' => 'entry_issue', 'title' => 'Entry Issue']);

    Livewire::test(EditDocument::class, ['record' => $doc->getRouteKey()])
        ->assertFormFieldExists('open_flag_types')
        ->assertFormSet(['open_flag_types' => ['entry_issue']])
        ->fillForm(['open_flag_types' => ['mould_treatment']])
        ->call('save')
        ->assertHasNoFormErrors();

    $flags = DocumentFlag::withoutGlobalScopes()->where('document_id', $doc->id)->get()->keyBy('type');
    expect($flags['mould_treatment']->status)->toBe('open')
        ->and($flags['mould_treatment']->title)->toBe('Mould Treatment')
        ->and($flags['entry_issue']->status)->toBe('resolved');
});

it('holds only "Add rule" on the filter panel, with AND / OR above the rules and NOT on each', function (): void {
    $table = Livewire::test(ListDocuments::class)->instance()->getTable();
    expect(collect($table->getFilters())->map(fn ($f) => $f::class)->values()->all())
        ->toBe([MatchQueryBuilder::class, TrashedFilter::class]);

    $a = b29_doc(['volume_number' => '1']);
    $b = b29_doc(['volume_number' => '2']);
    $c = b29_doc(['volume_number' => '3']);
    $rules = [
        'one' => b29_rule('volume_number', 'equals', ['text' => '1']),
        'two' => b29_rule('volume_number', 'equals', ['text' => '2']),
    ];

    b29_list($rules, 'any')->assertCanSeeTableRecords([$a, $b])->assertCanNotSeeTableRecords([$c])->assertSee('Any of:');
    b29_list($rules, 'all')->assertCanNotSeeTableRecords([$a, $b, $c]);
    b29_list(['not' => b29_rule('volume_number', 'equals.inverse', ['text' => '1'])])
        ->assertCanSeeTableRecords([$b, $c])->assertCanNotSeeTableRecords([$a]);
});

it('filters by flag, location (with the box\'s and the rooms inside), custody status and disinfested', function (): void {
    $room = Location::withoutGlobalScopes()->create(['name' => 'Mould Room', 'code' => 'MOULD', 'type' => 'room', 'repository_id' => $this->repo->id, 'is_active' => true]);
    $shelf = Location::withoutGlobalScopes()->create(['name' => 'Shelf 1', 'code' => 'MOULD-1', 'type' => 'shelf', 'parent_id' => $room->id, 'repository_id' => $this->repo->id, 'is_active' => true]);
    $box = Box::withoutGlobalScopes()->create(['box_type' => 'MAV', 'box_number' => '9', 'repository_id' => $this->repo->id, 'barcode_status' => 'IN', 'is_legacy' => true, 'provenance_unknown' => true, 'location_id' => $shelf->id, 'disinfestation_date' => '2024-01-01']);

    $onShelf = b29_doc(['location_id' => $shelf->id]);
    $inBox = b29_doc(['current_box_id' => $box->id]);
    $elsewhere = b29_doc(['custody_status' => 'not_in_box']);
    DocumentFlag::create(['document_id' => $elsewhere->id, 'type' => 'mould_treatment', 'title' => 'x']);

    b29_list(['l' => b29_rule('location', 'is', ['values' => [$room->id]])])
        ->assertCanSeeTableRecords([$onShelf, $inBox])->assertCanNotSeeTableRecords([$elsewhere]);
    b29_list(['f' => b29_rule('flag', 'is', ['values' => ['mould_treatment']])])
        ->assertCanSeeTableRecords([$elsewhere])->assertCanNotSeeTableRecords([$onShelf, $inBox]);
    b29_list(['f' => b29_rule('flag', 'any.inverse')])
        ->assertCanSeeTableRecords([$onShelf, $inBox])->assertCanNotSeeTableRecords([$elsewhere]);
    b29_list(['c' => b29_rule('custody_status', 'is', ['values' => ['not_in_box']])])
        ->assertCanSeeTableRecords([$elsewhere])->assertCanNotSeeTableRecords([$onShelf]);
    b29_list(['d' => b29_rule('disinfested', 'yes')])
        ->assertCanSeeTableRecords([$inBox])->assertCanNotSeeTableRecords([$onShelf, $elsewhere]);

    // A box's "View documents" opens the Box (now or earlier) rule.
    parse_str((string) parse_url(BoxResource::documentsUrl($box), PHP_URL_QUERY), $query);
    Livewire::withQueryParams($query)->test(ListDocuments::class)
        ->assertCanSeeTableRecords([$inBox])->assertCanNotSeeTableRecords([$onShelf, $elsewhere]);
});

it('keeps the box\'s own columns out of the documents import wizard', function (): void {
    $headers = ['RAS Batch 1', 'RAS Box 1', 'Seal Number', 'Current Box', 'No of Acts', 'Pages/Folios', 'RAS 1 Box Destroyed', 'Current box barcode'];

    expect(ImportWizard::wizardColumnMap(DocumentImporter::class, $headers))
        ->not->toHaveKeys(DocumentImporter::WIZARD_HIDDEN_COLUMNS)
        ->toHaveKeys(['batch_number', 'current_box_number'])
        // The console batch-list import still maps them.
        ->and(ImportWizard::guessColumnMap(DocumentImporter::class, $headers))->toHaveKey('seal_number');
});

it('updates the document a template row describes instead of copying it, and adds its flag', function (): void {
    $batch = Batch::withoutGlobalScopes()->create(['batch_number' => '7', 'type' => 'MAIN_COLLECTION', 'repository_id' => $this->repo->id]);
    $authority = Authority::withoutGlobalScopes()->create(['identifier' => 'NAM-18', 'alternative_identifier' => 'R18', 'surname' => 'Allegritto', 'given_names' => 'Nicola', 'repository_id' => $this->repo->id]);
    $original = b29_doc(['batch_id' => $batch->id, 'ras_batch_1' => '7', 'ras_box_1' => '52', 'in_situ_box_1' => 'NRA58', 'volume_number' => '69', 'created_at' => now()->subDay()]);
    $original->authorities()->attach($authority->id, ['is_primary' => true]);
    $row = ['RAS Batch 1' => '7', 'RAS Box 1' => '52', 'In Situ Box 1' => 'NRA58', 'Authority Identifier' => 'R18', 'Volume' => '69', 'Subseries' => 'REG: Registers', 'Flags' => 'mould_treatment'];

    // Overwrite unticked: the row is the existing document, so it is skipped.
    $skipped = b29_import([$row], overwrite: false);
    expect($skipped->successful_rows)->toBe(0)
        ->and(Document::withoutGlobalScopes()->count())->toBe(1);

    $import = b29_import([$row], overwrite: true);
    expect($import->successful_rows)->toBe(1)
        ->and(Document::withoutGlobalScopes()->count())->toBe(1)
        ->and($original->fresh()->identifier)->toBe($original->identifier)
        ->and(DocumentFlag::withoutGlobalScopes()->where('document_id', $original->id)->value('type'))->toBe('mould_treatment');

    // Two documents at the same place: the row cannot tell which, and says so.
    $twin = b29_doc(['batch_id' => $batch->id, 'ras_batch_1' => '7', 'ras_box_1' => '52', 'in_situ_box_1' => 'NRA58', 'volume_number' => '69', 'created_at' => now()->subDay()]);
    $twin->authorities()->attach($authority->id, ['is_primary' => true]);
    $ambiguous = b29_import([$row], overwrite: true);
    expect($ambiguous->successful_rows)->toBe(0)
        ->and(json_encode($ambiguous->failedRows()->first()->validation_error))->toContain('matches 2 documents')
        ->and(Document::withoutGlobalScopes()->count())->toBe(2);
});
