<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\Lookups\FlagTypeResource\Pages\EditFlagType;
use App\Jobs\ImportCsvSlice;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Document;
use App\Models\DocumentFlag;
use App\Models\DocumentType;
use App\Models\Lookup\FlagType;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\ColorPicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Client 2026-10-30, "BatchListIssues30.docx".
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
});

/**
 * @param list<array<string, string>> $rows
 */
function b30_import(array $rows, bool $overwrite): Import
{
    $csv = tempnam(sys_get_temp_dir(), 'b30') . '.csv';
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

it('updates the live document, not a deleted copy carrying the row\'s auto identifier (issue 7)', function (): void {
    $batch = Batch::withoutGlobalScopes()->create(['batch_number' => '7', 'type' => 'MAIN_COLLECTION', 'repository_id' => $this->repo->id]);
    $authority = Authority::withoutGlobalScopes()->create(['identifier' => 'NAM-18', 'alternative_identifier' => 'R18', 'surname' => 'Allegritto', 'given_names' => 'Nicola', 'repository_id' => $this->repo->id]);
    // In Situ box left blank so the first import does not recognise it.
    $original = Document::withoutGlobalScopes()->create(['identifier' => 'NRA-REG-ORIGINAL', 'repository_id' => $this->repo->id, 'series_id' => $this->series->id, 'batch_id' => $batch->id, 'ras_batch_1' => '7', 'ras_box_1' => '52', 'volume_number' => '69']);
    $original->forceFill(['created_at' => now()->subDays(2)])->saveQuietly();
    $original->authorities()->attach($authority->id, ['is_primary' => true]);
    $row = ['RAS Batch 1' => '7', 'RAS Box 1' => '52', 'In Situ Box 1' => 'NRA58', 'Authority Identifier' => 'R18', 'Volume' => '69', 'Subseries' => 'REG: Registers', 'Flags' => 'mould_treatment'];

    // What happened in production on 2026-10-29: the row made a copy, which
    // was then deleted and its flag resolved on the original.
    expect(b30_import([$row], overwrite: true)->successful_rows)->toBe(1);
    $copy = Document::withoutGlobalScopes()->whereKeyNot($original->id)->sole();
    $copy->delete();
    $original->forceFill(['in_situ_box_1' => 'NRA58'])->saveQuietly();

    $import = b30_import([$row], overwrite: true);

    expect($import->successful_rows)->toBe(1)
        ->and(Document::withoutGlobalScopes()->withTrashed()->find($copy->id)->trashed())->toBeTrue()
        ->and(Document::withoutGlobalScopes()->whereNull('deleted_at')->pluck('id')->all())->toBe([$original->id])
        ->and(DocumentFlag::withoutGlobalScopes()->where('document_id', $original->id)->where('status', 'open')->value('type'))->toBe('mould_treatment');
});

it('edits a document whose type is stored as its code, and keeps the type link in step (issue 2)', function (): void {
    $bastardello = DocumentType::query()->create(['name' => 'Bastardello', 'identifier' => 'DT00010', 'is_active' => true]);
    $accounts = DocumentType::query()->create(['name' => 'Accounts', 'identifier' => 'DT00002', 'is_active' => true]);
    $doc = Document::withoutGlobalScopes()->create(['identifier' => 'B30-TYPE', 'repository_id' => $this->repo->id, 'series_id' => $this->series->id, 'document_type' => 'DT00010', 'document_type_id' => $bastardello->id]);

    $page = Livewire::test(EditDocument::class, ['record' => $doc->getRouteKey()])
        ->assertFormFieldExists('document_type', fn ($field): bool => ($field->getOptions()['DT00010'] ?? null) === 'Bastardello')
        ->assertSchemaStateSet(['document_type' => 'DT00010']);

    // Saving untouched no longer fails on the type.
    $page->call('save')->assertHasNoFormErrors();

    $page->fillForm(['document_type' => 'DT00002'])->call('save')->assertHasNoFormErrors();
    expect($doc->fresh()->only(['document_type', 'document_type_id']))->toBe(['document_type' => 'DT00002', 'document_type_id' => $accounts->id]);

    // A document saved with the name (as the old form did) shows the type too.
    $doc->forceFill(['document_type' => 'Accounts'])->saveQuietly();
    Livewire::test(EditDocument::class, ['record' => $doc->getRouteKey()])->assertSchemaStateSet(['document_type' => 'DT00002']);
});

it('picks a flag type colour from a colour wheel, opening a legacy name as its colour (issue 1)', function (): void {
    $type = FlagType::query()->where('code', 'mould_treatment')->sole();

    $page = Livewire::test(EditFlagType::class, ['record' => $type->getRouteKey()])
        ->assertFormFieldExists('colour', fn ($field): bool => $field instanceof ColorPicker)
        ->assertSchemaStateSet(['colour' => '#dc2626']);

    $page->fillForm(['colour' => '#123abc'])->call('save')->assertHasNoFormErrors();
    expect($type->fresh()->colour)->toBe('#123abc')
        ->and(FlagType::cssColours()['mould_treatment'])->toBe('#123abc');
});

it('mixes AND and OR with an OR group inside the rules: (R16 OR R12) AND Bastardello (issue 4)', function (): void {
    $bastardello = DocumentType::query()->create(['name' => 'Bastardello', 'identifier' => 'DT00010', 'is_active' => true]);
    $register = DocumentType::query()->create(['name' => 'Register Volume', 'identifier' => 'DT00098', 'is_active' => true]);
    $make = function (string $code, DocumentType $type): Document {
        $authority = Authority::withoutGlobalScopes()->firstOrCreate(['alternative_identifier' => $code], ['identifier' => 'NAM-' . $code, 'surname' => $code, 'repository_id' => $this->repo->id]);
        $doc = Document::withoutGlobalScopes()->create(['identifier' => "B30-{$code}-{$type->identifier}", 'repository_id' => $this->repo->id, 'series_id' => $this->series->id, 'document_type' => $type->identifier, 'document_type_id' => $type->id]);
        $doc->authorities()->attach($authority->id, ['is_primary' => true]);

        return $doc;
    };
    $r16 = $make('R16', $bastardello);
    $r12 = $make('R12', $bastardello);
    $r16register = $make('R16', $register);
    $r99 = $make('R99', $bastardello);

    $code = fn (string $value): array => ['type' => 'authority_citing_code', 'data' => ['operator' => 'equals', 'settings' => ['text' => $value]]];
    $rules = [
        'either' => ['type' => 'or', 'data' => ['groups' => [
            'a' => ['rules' => ['x' => $code('R16')]],
            'b' => ['rules' => ['y' => $code('R12')]],
            // An alternative left empty adds nothing, it does not match everything.
            'c' => ['rules' => []],
        ]]],
        'type' => ['type' => 'documentTypeRef', 'data' => ['operator' => 'isRelatedTo', 'settings' => ['value' => [$bastardello->id]]]],
    ];

    Livewire::withQueryParams(['filters' => ['queryBuilder' => ['match' => 'all', 'rules' => $rules]]])
        ->test(ListDocuments::class)
        ->assertCanSeeTableRecords([$r16, $r12])
        ->assertCanNotSeeTableRecords([$r16register, $r99]);

    // Livewire accepts the path of a value inside a group (it stopped at 10 levels).
    expect(config('livewire.payload.max_nesting_depth'))->toBeGreaterThanOrEqual(13);
});
