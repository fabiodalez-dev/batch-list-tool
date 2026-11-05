<?php

declare(strict_types=1);

use App\Filament\Imports\AuthorityImporter;
use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Filament\Resources\AuthorityResource\Pages\CreateAuthority;
use App\Filament\Resources\AuthorityResource\Pages\EditAuthority;
use App\Filament\Resources\AuthorityResource\Pages\ListAuthorities;
use App\Filament\Resources\AuthorityResource\Pages\ViewAuthority;
use App\Filament\Resources\DocumentResource\Pages\CreateDocument;
use App\Filament\Resources\DocumentResource\Pages\EditDocument;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\Lookups\CustodyStatusTypeResource;
use App\Filament\Resources\Lookups\CustodyStatusTypeResource\Pages\CreateCustodyStatusType;
use App\Filament\Resources\Lookups\CustodyStatusTypeResource\Pages\EditCustodyStatusType;
use App\Filament\Resources\Lookups\CustodyStatusTypeResource\Pages\ListCustodyStatusTypes;
use App\Filament\Resources\Lookups\CustodyStatusTypeResource\Pages\ViewCustodyStatusType;
use App\Filament\Resources\Lookups\EntityTypeResource;
use App\Filament\Resources\Lookups\EntityTypeResource\Pages\CreateEntityType;
use App\Filament\Resources\Lookups\EntityTypeResource\Pages\EditEntityType;
use App\Filament\Resources\Lookups\EntityTypeResource\Pages\ListEntityTypes;
use App\Filament\Resources\Lookups\EntityTypeResource\Pages\ViewEntityType;
use App\Jobs\ImportCsvSlice;
use App\Models\Authority;
use App\Models\Document;
use App\Models\Lookup\CustodyStatusType;
use App\Models\Lookup\EntityType;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Actions\DeleteAction;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\Column;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;

/**
 * Client 2026-11-05, Issues 4, 5 and 11: the Authorities list and pages, and the
 * two new lookup pages, Entity Types and Custody Status Types.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    $this->repo = Repository::factory()->create(['code' => 'R1105']);
    $this->user = bl_actor('super_admin', $this->repo);
    $this->actingAs($this->user);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true]);
});

/** @param array<string, mixed> $attrs */
function aal_authority(array $attrs = []): Authority
{
    static $n = 0;
    $n++;

    return Authority::create(array_merge([
        'alternative_identifier' => 'AAL' . $n,
        'surname' => 'Psaila',
        'given_names' => 'Giuseppe',
        'entity_type' => 'Person',
    ], $attrs));
}

/** @param array<string, mixed> $overrides */
function aal_form(array $overrides = []): array
{
    return array_merge([
        'alternative_identifier' => 'R900',
        'entity_type' => 'Person',
        'surname' => 'Psaila',
        'given_names' => 'Giuseppe Raimondo',
    ], $overrides);
}

/**
 * Through the job the wizard dispatches, as production does.
 *
 * @param class-string $importer
 * @param list<array<string, string>> $rows
 */
function aal_import(string $importer, array $rows, array $options = []): Import
{
    $user = test()->user;
    $headers = array_keys($rows[0]);
    $csv = tempnam(sys_get_temp_dir(), 'aal') . '.csv';
    $f = fopen($csv, 'w');
    fputcsv($f, $headers);
    $offset = ftell($f);
    foreach ($rows as $row) {
        fputcsv($f, array_values($row));
    }
    fclose($f);

    $map = array_filter(ImportWizard::guessColumnMap($importer, ImportWizard::csvHeaderKeys($csv)));
    $import = Import::query()->create([
        'file_name' => 'a.csv', 'file_path' => $csv, 'importer' => $importer,
        'total_rows' => count($rows), 'user_id' => $user->id,
    ]);
    EntityResolver::flushMemo();

    (new ImportCsvSlice($import, $csv, $offset, 1, count($rows), $map, $options + ['repository_id' => test()->repo->id]))->handle();

    return $import->refresh();
}

// ─── Issue 4: list ─────────────────────────────────────────────────────────

it('sorts the Authorities list by Past Reference Code', function (): void {
    $b = aal_authority(['previous_temporary_identifiers' => 'BBB']);
    $a = aal_authority(['previous_temporary_identifiers' => 'AAA']);
    $c = aal_authority(['previous_temporary_identifiers' => 'CCC']);

    Livewire::test(ListAuthorities::class)
        ->sortTable('previous_temporary_identifiers')
        ->assertCanSeeTableRecords([$a, $b, $c], inOrder: true)
        ->sortTable('previous_temporary_identifiers', 'desc')
        ->assertCanSeeTableRecords([$c, $b, $a], inOrder: true);
});

it('leaves no real column of the Authorities list unsortable', function (): void {
    $columns = Livewire::test(ListAuthorities::class)->instance()->getTable()->getColumns();

    $unsortable = collect($columns)
        ->reject(fn (Column $column): bool => $column->isSortable())
        ->keys()
        ->all();

    expect($unsortable)->toBe([]);
});

// ─── Issue 5: sections and fields ──────────────────────────────────────────

dataset('her order', [[[
    'Control Area',
    'Citing Reference Code', 'NAM Authority Reference Code', 'Alternate Reference Code', 'Past Reference Code',
    'Note', 'Level of detail', 'Status', 'Rules and/or conventions', 'Date of creation', 'Creator of record',
    'Identity Area',
    'Type of Entity', 'Creator Surname', 'Creator Name', 'Name Suffix', 'Maiden Surname', 'Authorised Form of Name',
    'Description Area',
    'Practice date start', 'Practice date end', 'NTG date start', 'NTG date end', 'Functions, Occupations and Activities',
]]]);

it('shows her sections and fields in her order on New', function (array $order): void {
    Livewire::test(CreateAuthority::class)->assertSeeInOrder($order);
})->with('her order');

it('shows her sections and fields in her order on Edit', function (array $order): void {
    Livewire::test(EditAuthority::class, ['record' => aal_authority()->getKey()])->assertSeeInOrder($order);
})->with('her order');

it('shows her sections and fields in her order on View, Audit info last for a super admin', function (array $order): void {
    Livewire::test(ViewAuthority::class, ['record' => aal_authority()->getKey()])
        ->assertSeeInOrder([...$order, 'Audit info']);
})->with('her order');

it('does not show Audit info on View to an admin', function (): void {
    $this->actingAs(bl_actor('admin', $this->repo));

    Livewire::test(ViewAuthority::class, ['record' => aal_authority()->getKey()])->assertDontSee('Audit info');
});

it('still gates the fields by field permissions', function (): void {
    config(['field_permissions.authority.notes' => ['read' => ['super_admin'], 'write' => ['super_admin']]]);
    $this->actingAs(bl_actor('editor', $this->repo));

    Livewire::test(CreateAuthority::class)->assertFormFieldDoesNotExist('notes');
});

it('offers the years newest first, from 1400 to this year, as Select Year', function (): void {
    foreach (['practice_dates_start', 'practice_dates_end', 'ntg_dates_start', 'ntg_dates_end'] as $field) {
        Livewire::test(CreateAuthority::class)->assertFormFieldExists($field, function (Select $select): bool {
            $years = array_keys($select->getOptions());

            return $select->isSearchable()
                && $select->getPlaceholder() === 'Select Year'
                && $years[0] === (int) now()->year
                && end($years) === 1400
                && count($years) === (int) now()->year - 1400 + 1;
        });
    }
});

it('saves the years chosen', function (): void {
    Livewire::test(CreateAuthority::class)
        ->fillForm(aal_form(['practice_dates_start' => 1831, 'practice_dates_end' => 1878, 'ntg_dates_start' => '1840', 'ntg_dates_end' => '1850']))
        ->call('create')
        ->assertHasNoFormErrors();

    $a = Authority::where('alternative_identifier', 'R900')->firstOrFail();
    expect([$a->practice_dates_start, $a->practice_dates_end, $a->ntg_dates_start, $a->ntg_dates_end])->toBe([1831, 1878, 1840, 1850]);
});

it('refuses a year outside the list and an end before the start, on the server', function (): void {
    Livewire::test(CreateAuthority::class)
        ->fillForm(aal_form(['practice_dates_start' => 1399]))
        ->call('create')
        ->assertHasFormErrors(['practice_dates_start']);

    Livewire::test(CreateAuthority::class)
        ->fillForm(aal_form(['practice_dates_start' => 1878, 'practice_dates_end' => 1831]))
        ->call('create')
        ->assertHasFormErrors(['practice_dates_end']);

    Livewire::test(CreateAuthority::class)
        ->fillForm(aal_form(['ntg_dates_start' => 1878, 'ntg_dates_end' => 1831]))
        ->call('create')
        ->assertHasFormErrors(['ntg_dates_end']);

    expect(Authority::where('alternative_identifier', 'R900')->exists())->toBeFalse();
});

it('keeps a stored year outside the range selectable, and only on its own record', function (): void {
    $a = aal_authority(['practice_dates_start' => 1350, 'authorised_form_of_name' => 'Kept']);

    Livewire::test(EditAuthority::class, ['record' => $a->getKey()])
        ->assertFormSet(['practice_dates_start' => 1350])
        ->assertFormFieldExists('practice_dates_start', fn (Select $s): bool => array_key_last($s->getOptions()) === 1350)
        ->fillForm(['surname' => 'Changed'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($a->refresh()->practice_dates_start)->toBe(1350);

    Livewire::test(EditAuthority::class, ['record' => aal_authority()->getKey()])
        ->fillForm(['practice_dates_start' => 1350])
        ->call('save')
        ->assertHasFormErrors(['practice_dates_start']);
});

// ─── Issue 5: Authorised Form of Name ──────────────────────────────────────

it('creates the Authorised Form of Name when saved blank, with a helper saying so', function (): void {
    Livewire::test(CreateAuthority::class)
        ->assertSee('Created automatically')
        ->fillForm(aal_form(['practice_dates_start' => 1831, 'practice_dates_end' => 1878]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Authority::where('alternative_identifier', 'R900')->value('authorised_form_of_name'))
        ->toBe('Psaila, Giuseppe Raimondo, 1831-1878');
});

it('recomputes the form when a name part or a date is corrected on the form', function (): void {
    $a = aal_authority(['practice_dates_start' => 1831, 'practice_dates_end' => 1878]);
    expect($a->authorised_form_of_name)->toBe('Psaila, Giuseppe, 1831-1878');

    Livewire::test(EditAuthority::class, ['record' => $a->getKey()])
        ->fillForm(['given_names' => 'Giuseppe Raimondo', 'practice_dates_end' => 1880])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($a->refresh()->authorised_form_of_name)->toBe('Psaila, Giuseppe Raimondo, 1831-1880');
});

it('never overwrites a hand-edited form', function (): void {
    $a = aal_authority(['practice_dates_start' => 1831]);

    // Edited on its own.
    Livewire::test(EditAuthority::class, ['record' => $a->getKey()])
        ->fillForm(['authorised_form_of_name' => 'Psaila, Dr Giuseppe'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($a->refresh()->authorised_form_of_name)->toBe('Psaila, Dr Giuseppe');

    // Then a correction to the name leaves the edit alone.
    Livewire::test(EditAuthority::class, ['record' => $a->getKey()])
        ->fillForm(['surname' => 'Psaylla'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($a->refresh()->authorised_form_of_name)->toBe('Psaila, Dr Giuseppe');

    // Edited in the same save as a correction: the edit wins.
    $b = aal_authority();
    Livewire::test(EditAuthority::class, ['record' => $b->getKey()])
        ->fillForm(['surname' => 'Borg', 'authorised_form_of_name' => 'Borg, known as Psaila'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($b->refresh()->authorised_form_of_name)->toBe('Borg, known as Psaila');
});

it('fills the form again when someone clears it', function (): void {
    $a = aal_authority(['authorised_form_of_name' => 'By hand']);

    Livewire::test(EditAuthority::class, ['record' => $a->getKey()])
        ->fillForm(['authorised_form_of_name' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($a->refresh()->authorised_form_of_name)->toBe('Psaila, Giuseppe');
});

it('import: a value in the sheet wins, a blank one is filled', function (): void {
    $import = aal_import(AuthorityImporter::class, [
        ['Citing Reference Code' => 'AF1', 'Creator Surname' => 'Abela', 'Creator Name' => 'Antonio', 'Authorised Form of Name' => 'Abela, Fra Antonio', 'Type of Entity' => 'Notary'],
        ['Citing Reference Code' => 'AF2', 'Creator Surname' => 'Bonnici', 'Creator Name' => 'Anna', 'Authorised Form of Name' => '', 'Type of Entity' => 'Notary'],
    ]);

    expect($import->successful_rows)->toBe(2)
        ->and(Authority::where('alternative_identifier', 'AF1')->value('authorised_form_of_name'))->toBe('Abela, Fra Antonio')
        ->and(Authority::where('alternative_identifier', 'AF2')->value('authorised_form_of_name'))->toBe('Bonnici, Anna');
});

it('import: a blank cell does not wipe a hand-edited form, and follows a corrected computed one', function (): void {
    $edited = aal_authority(['alternative_identifier' => 'AF3', 'authorised_form_of_name' => 'Written by hand']);
    $computed = aal_authority(['alternative_identifier' => 'AF4']);

    aal_import(AuthorityImporter::class, [
        ['Citing Reference Code' => 'AF3', 'Creator Surname' => 'Other', 'Creator Name' => 'Name', 'Authorised Form of Name' => '', 'Type of Entity' => 'Person'],
        ['Citing Reference Code' => 'AF4', 'Creator Surname' => 'Other', 'Creator Name' => 'Name', 'Authorised Form of Name' => '', 'Type of Entity' => 'Person'],
    ], ['overwrite_existing' => true]);

    expect($edited->refresh()->surname)->toBe('Other')
        ->and($edited->authorised_form_of_name)->toBe('Written by hand')
        ->and($computed->refresh()->authorised_form_of_name)->toBe('Other, Name');
});

it('import: the value in the sheet wins even when it equals the old computed form', function (): void {
    $a = aal_authority(['alternative_identifier' => 'AF5']);
    expect($a->authorised_form_of_name)->toBe('Psaila, Giuseppe');

    aal_import(AuthorityImporter::class, [
        ['Citing Reference Code' => 'AF5', 'Creator Surname' => 'Other', 'Creator Name' => 'Name', 'Authorised Form of Name' => 'Psaila, Giuseppe', 'Type of Entity' => 'Person'],
    ], ['overwrite_existing' => true]);

    expect($a->refresh()->surname)->toBe('Other')
        ->and($a->authorised_form_of_name)->toBe('Psaila, Giuseppe');
});

it('the authority template carries her labels, and a sheet saved before keeps importing', function (): void {
    expect(TemplateGenerator::headersFor('authority'))->toContain('Authorised Form of Name', 'Functions, Occupations and Activities', 'Note');

    $import = aal_import(AuthorityImporter::class, [[
        'Citing Reference Code' => 'AF6', 'Creator Surname' => 'Old', 'Creator Name' => 'Sheet', 'Type of Entity' => 'PERSON',
        'Authorised form of name' => 'Old, Fra Sheet', 'Functions, occupations and activities' => 'Notary', 'Notes' => 'An old note',
    ]]);

    $a = Authority::where('alternative_identifier', 'AF6')->firstOrFail();
    expect($import->successful_rows)->toBe(1)
        ->and($a->authorised_form_of_name)->toBe('Old, Fra Sheet')
        ->and($a->functions_occupations_activities)->toBe('Notary')
        ->and($a->notes)->toBe('An old note');
});

// ─── Issue 5: Entity Types ─────────────────────────────────────────────────

it('seeds Notary, Interventor and Person, and offers Person on the authority form', function (): void {
    expect(array_keys(EntityType::options()))->toBe(['Notary', 'Interventor', 'Person']);

    Livewire::test(CreateAuthority::class)
        ->assertFormSet(['entity_type' => 'Notary'])
        ->assertFormFieldExists('entity_type', fn (Select $s): bool => $s->getOptions() === ['Notary' => 'Notary', 'Interventor' => 'Interventor', 'Person' => 'Person'])
        ->fillForm(aal_form(['entity_type' => 'Person']))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Authority::where('alternative_identifier', 'R900')->value('entity_type'))->toBe('Person');
});

it('adds, edits, views and deletes an entity type on its page, for the authority form to offer', function (): void {
    Livewire::test(CreateEntityType::class)
        ->fillForm(['code' => 'Family', 'label' => 'Family', 'sort_order' => 40, 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();
    $type = EntityType::where('code', 'Family')->firstOrFail();

    Livewire::test(CreateAuthority::class)
        ->fillForm(aal_form(['entity_type' => 'Family']))
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(EditEntityType::class, ['record' => $type->getKey()])
        ->fillForm(['label' => 'A family'])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(ViewEntityType::class, ['record' => $type->getKey()])
        ->assertOk()
        ->assertSchemaStateSet(['label' => 'A family']);

    // The list shows the label of the stored code, and so does the view page.
    $a = Authority::where('alternative_identifier', 'R900')->firstOrFail();
    Livewire::test(ListAuthorities::class)->assertTableColumnFormattedStateSet('entity_type', 'A family', $a);
    Livewire::test(ViewAuthority::class, ['record' => $a->getKey()])->assertSee('A family');

    Livewire::test(ListEntityTypes::class)
        ->assertCanSeeTableRecords(EntityType::all())
        ->callTableBulkAction('delete', [$type]);
    expect(EntityType::where('code', 'Family')->exists())->toBeFalse();
});

it('keeps a value the lookup no longer offers selectable on the record that holds it', function (): void {
    $type = EntityType::create(['code' => 'Old type', 'label' => 'Old type', 'is_active' => true]);
    $a = aal_authority(['entity_type' => 'Old type', 'authorised_form_of_name' => 'K']);
    $type->update(['is_active' => false]);

    Livewire::test(EditAuthority::class, ['record' => $a->getKey()])
        ->assertFormFieldExists('entity_type', fn (Select $s): bool => $s->getOptions()['Old type'] === 'Old type (inactive)')
        ->fillForm(['surname' => 'Changed'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($a->refresh()->entity_type)->toBe('Old type');

    // ...and only there.
    Livewire::test(CreateAuthority::class)
        ->fillForm(aal_form(['entity_type' => 'Old type']))
        ->call('create')
        ->assertHasFormErrors(['entity_type']);
});

it('leaves the default empty when Notary is inactive', function (): void {
    EntityType::where('code', 'Notary')->firstOrFail()->update(['is_active' => false]);

    Livewire::test(CreateAuthority::class)->assertFormSet(['entity_type' => null]);
});

it('filters the Authorities list by a single Person', function (): void {
    $person = aal_authority(['entity_type' => 'Person']);
    $notary = aal_authority(['entity_type' => 'Notary']);

    Livewire::test(ListAuthorities::class)
        ->filterTable('queryBuilder', doc_rule('entity_type', 'is', ['values' => ['Person']]))
        ->assertCanSeeTableRecords([$person])
        ->assertCanNotSeeTableRecords([$notary]);
});

dataset('person spellings', ['PERSON', 'person', 'Person', ' pErSoN ']);

it('imports any casing of Person as Person', function (string $spelling): void {
    $import = aal_import(AuthorityImporter::class, [
        ['Citing Reference Code' => 'ET1', 'Creator Surname' => 'Abela', 'Creator Name' => 'A', 'Type of Entity' => $spelling],
    ]);

    expect($import->successful_rows)->toBe(1)
        ->and(Authority::where('alternative_identifier', 'ET1')->value('entity_type'))->toBe('Person');
})->with('person spellings');

it('preflight accepts PERSON and a type added on the page, and refuses one the page does not list', function (): void {
    EntityType::create(['code' => 'Family', 'label' => 'A family', 'is_active' => true]);
    $headers = ['Citing Reference Code', 'Type of Entity', 'Creator Surname'];
    $map = ImportWizard::guessColumnMap(AuthorityImporter::class, $headers);
    $row = fn (string $type): array => ['Citing Reference Code' => 'P1', 'Type of Entity' => $type, 'Creator Surname' => 'B'];

    $ok = ImportWizard::validateRows(AuthorityImporter::class, [$row('PERSON'), $row('family'), $row('A FAMILY'), $row('')], $map);
    $bad = ImportWizard::validateRows(AuthorityImporter::class, [$row('INSTITUTION'), $row('Persn')], $map);

    expect($ok['invalid'])->toBe(0)->and($ok['valid'])->toBe(4)
        ->and($bad['invalid'])->toBe(2);
});

it('migration: PERSON rows become Person, with no audit, leaving updated_at, and running again changes nothing', function (): void {
    config(['audit.console' => true]);
    $migration = require database_path('migrations/2026_11_05_120000_create_entity_types_table.php');
    $migration->down();

    $id = DB::table('authorities')->insertGetId([
        'alternative_identifier' => 'MIG1', 'surname' => 'Old', 'entity_type' => 'PERSON',
        'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00',
    ]);
    $notary = DB::table('authorities')->insertGetId([
        'alternative_identifier' => 'MIG2', 'surname' => 'Old', 'entity_type' => 'Notary',
        'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00',
    ]);
    $audits = Audit::query()->count();

    $migration->up();

    $row = DB::table('authorities')->find($id);
    expect($row->entity_type)->toBe('Person')
        ->and((string) $row->updated_at)->toStartWith('2020-01-01')
        ->and(DB::table('authorities')->find($notary)->entity_type)->toBe('Notary')
        ->and(Audit::query()->count())->toBe($audits)
        ->and(array_keys(EntityType::options()))->toBe(['Notary', 'Interventor', 'Person']);

    // A new row with no type no longer brings the old spelling back.
    $fresh = DB::table('authorities')->insertGetId(['alternative_identifier' => 'MIG3', 'surname' => 'New']);
    expect(DB::table('authorities')->find($fresh)->entity_type)->toBe('Person');

    // down() restores it, and a second up() lands on the same result.
    $migration->down();
    expect(DB::table('authorities')->find($id)->entity_type)->toBe('PERSON');
    $migration->up();
    expect(DB::table('authorities')->find($id)->entity_type)->toBe('Person');
    // DDL commits on MySQL/MariaDB, which would leave these rows behind for the
    // next tests.
})->skip(fn (): bool => DB::getDriverName() !== 'sqlite', 'DDL inside the test transaction: SQLite only');

it('allows an entity type longer than the old 16-character column', function (): void {
    EntityType::create(['code' => 'Notary to Government', 'label' => 'Notary to Government', 'is_active' => true]);

    $a = aal_authority(['entity_type' => 'Notary to Government']);

    expect($a->refresh()->entity_type)->toBe('Notary to Government');
});

// ─── Issue 11: the page ────────────────────────────────────────────────────

it('seeds the custody status types with their labels', function (): void {
    expect(Document::custodyLabels())->toBe([
        'in_box' => 'In Box',
        'not_in_box' => 'Not in Box',
        'mounted_no_box' => 'Mounted; no box',
        'conservation_box' => 'Conservation Box',
    ]);
});

it('adds, edits, views and deletes a custody status on its page', function (): void {
    Livewire::test(CreateCustodyStatusType::class)
        ->fillForm(['code' => 'on_loan', 'label' => 'On Loan', 'sort_order' => 50, 'is_active' => true])
        ->call('create')
        ->assertHasNoFormErrors();
    $type = CustodyStatusType::where('code', 'on_loan')->firstOrFail();
    expect(Document::custodyLabels())->toHaveKey('on_loan', 'On Loan');

    Livewire::test(EditCustodyStatusType::class, ['record' => $type->getKey()])
        ->fillForm(['label' => 'Out on loan'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect(Document::custodyLabels()['on_loan'])->toBe('Out on loan');

    Livewire::test(ViewCustodyStatusType::class, ['record' => $type->getKey()])
        ->assertOk()
        ->assertSchemaStateSet(['label' => 'Out on loan']);

    Livewire::test(EditCustodyStatusType::class, ['record' => $type->getKey()])
        ->callAction(DeleteAction::class);
    expect(CustodyStatusType::where('code', 'on_loan')->exists())->toBeFalse()
        ->and(Document::custodyLabels())->not->toHaveKey('on_loan');
});

it('renames In Box, but will not delete, deactivate or re-code it', function (): void {
    $inBox = CustodyStatusType::where('code', 'in_box')->firstOrFail();

    Livewire::test(EditCustodyStatusType::class, ['record' => $inBox->getKey()])
        ->assertActionHidden(DeleteAction::class)
        ->assertFormFieldDisabled('code')
        ->assertFormFieldDisabled('is_active')
        // A forged post of the disabled fields does not reach the model.
        ->set('data.code', 'boxed')
        ->set('data.is_active', false)
        ->fillForm(['label' => 'In a box'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($inBox->refresh()->code)->toBe('in_box')
        ->and($inBox->is_active)->toBeTrue()
        ->and($inBox->label)->toBe('In a box');

    Livewire::test(ListCustodyStatusTypes::class)
        ->assertTableActionHidden('toggle_active', $inBox)
        ->callTableBulkAction('delete', [$inBox]);
    expect(CustodyStatusType::where('code', 'in_box')->exists())->toBeTrue();

    // The model refuses it whatever the route.
    expect(fn () => $inBox->delete())->toThrow(ValidationException::class);
    expect(fn () => $inBox->update(['is_active' => false]))->toThrow(ValidationException::class);
    expect(fn () => $inBox->update(['code' => 'boxed']))->toThrow(ValidationException::class);
});

it('refuses deleting a status documents use, with a clear message', function (): void {
    $type = CustodyStatusType::create(['code' => 'on_loan', 'label' => 'On Loan', 'is_active' => true]);
    $doc = Document::factory()->create(['custody_status' => 'on_loan']);

    Livewire::test(EditCustodyStatusType::class, ['record' => $type->getKey()])
        ->callAction(DeleteAction::class)
        ->assertNotified('Not deleted');
    expect(CustodyStatusType::where('code', 'on_loan')->exists())->toBeTrue();

    Livewire::test(ListCustodyStatusTypes::class)->callTableBulkAction('delete', [$type]);
    expect(CustodyStatusType::where('code', 'on_loan')->exists())->toBeTrue();

    expect(fn () => $type->delete())->toThrow(ValidationException::class, 'documents still have this custody status');

    // A trashed document or another repository's counts: it would be left with
    // an unknown code just the same.
    $other = Repository::factory()->create(['code' => 'OTHER']);
    Document::withoutGlobalScope(RepositoryScope::class)->whereKey($doc->getKey())->update(['repository_id' => $other->id]);
    expect(fn () => $type->delete())->toThrow(ValidationException::class);
    Document::withoutGlobalScope(RepositoryScope::class)->whereKey($doc->getKey())->first()->delete();
    expect(fn () => $type->delete())->toThrow(ValidationException::class);
});

it('deletes the unused ones of a selection and reports the others', function (): void {
    $used = CustodyStatusType::create(['code' => 'on_loan', 'label' => 'On Loan', 'is_active' => true]);
    $unused = CustodyStatusType::create(['code' => 'lost', 'label' => 'Lost', 'is_active' => true]);
    Document::factory()->create(['custody_status' => 'on_loan']);

    Livewire::test(ListCustodyStatusTypes::class)
        ->callTableBulkAction('delete', [$used, $unused])
        ->assertNotified();

    expect(CustodyStatusType::where('code', 'lost')->exists())->toBeFalse()
        ->and(CustodyStatusType::where('code', 'on_loan')->exists())->toBeTrue();
});

it('will not re-code a status documents hold, and will one they do not', function (): void {
    $used = CustodyStatusType::create(['code' => 'on_loan', 'label' => 'On Loan', 'is_active' => true]);
    $free = CustodyStatusType::create(['code' => 'lost', 'label' => 'Lost', 'is_active' => true]);
    Document::factory()->create(['custody_status' => 'on_loan']);

    Livewire::test(EditCustodyStatusType::class, ['record' => $used->getKey()])->assertFormFieldDisabled('code');
    expect(fn () => $used->update(['code' => 'loaned']))->toThrow(ValidationException::class);

    Livewire::test(EditCustodyStatusType::class, ['record' => $free->getKey()])
        ->fillForm(['code' => 'missing'])
        ->call('save')
        ->assertHasNoFormErrors();
    expect($free->refresh()->code)->toBe('missing');
});

it('lets only admins reach the two pages', function (string $resource): void {
    $actor = function (string $role): User {
        $user = bl_actor($role, $this->repo);
        $user->update(['is_active' => true]);

        return $user;
    };

    $this->actingAs($actor('editor'));
    expect($resource::canAccess())->toBeFalse();
    $this->get($resource::getUrl('index'))->assertForbidden();

    $this->actingAs($actor('admin'));
    expect($resource::canAccess())->toBeTrue();
    $this->get($resource::getUrl('index'))->assertOk();
})->with([[EntityTypeResource::class], [CustodyStatusTypeResource::class]]);

// ─── Issue 11: documents ───────────────────────────────────────────────────

it('accepts a status added on the page, and refuses one the page does not list', function (): void {
    expect(fn () => Document::factory()->create(['custody_status' => 'on_loan']))->toThrow(ValidationException::class);

    CustodyStatusType::create(['code' => 'on_loan', 'label' => 'On Loan', 'is_active' => true]);

    expect(Document::factory()->create(['custody_status' => 'ON_LOAN'])->fresh()->custody_status)->toBe('on_loan');
});

it('refuses a deactivated status on a new write, while a document holding it still saves', function (): void {
    $type = CustodyStatusType::create(['code' => 'on_loan', 'label' => 'On Loan', 'is_active' => true]);
    $holding = Document::factory()->create(['custody_status' => 'on_loan']);
    $type->update(['is_active' => false]);

    $holding->refresh()->update(['notes' => 'still saves']);
    expect($holding->refresh()->notes)->toBe('still saves')
        ->and($holding->custody_status)->toBe('on_loan');

    expect(fn () => Document::factory()->create(['custody_status' => 'on_loan']))->toThrow(ValidationException::class);
    $other = Document::factory()->create();
    expect(fn () => $other->update(['custody_status' => 'on_loan']))->toThrow(ValidationException::class);
});

it('shows the page\'s statuses on the document form, keeping a deactivated one on its own record', function (): void {
    CustodyStatusType::create(['code' => 'on_loan', 'label' => 'On Loan', 'is_active' => true]);

    Livewire::test(CreateDocument::class)
        ->assertFormFieldExists('custody_status', fn (Select $s): bool => $s->getOptions()['on_loan'] === 'On Loan' && $s->getOptions()['in_box'] === 'In Box');

    $doc = Document::factory()->create(['custody_status' => 'on_loan']);
    CustodyStatusType::where('code', 'on_loan')->firstOrFail()->update(['is_active' => false]);

    Livewire::test(CreateDocument::class)
        ->assertFormFieldExists('custody_status', fn (Select $s): bool => ! array_key_exists('on_loan', $s->getOptions()));
    Livewire::test(EditDocument::class, ['record' => $doc->getKey()])
        ->assertFormFieldExists('custody_status', fn (Select $s): bool => $s->getOptions()['on_loan'] === 'On Loan (inactive)');
});

it('shows and filters the statuses on the Documents list, a deactivated one too', function (): void {
    CustodyStatusType::create(['code' => 'on_loan', 'label' => 'On Loan', 'is_active' => true]);
    $loaned = Document::factory()->create(['custody_status' => 'on_loan', 'repository_id' => $this->repo->id]);
    $boxed = Document::factory()->create(['repository_id' => $this->repo->id]);
    CustodyStatusType::where('code', 'on_loan')->firstOrFail()->update(['is_active' => false]);

    Livewire::test(ListDocuments::class)
        ->assertTableColumnFormattedStateSet('custody_status', 'On Loan', $loaned)
        ->filterTable('queryBuilder', doc_rule('custody_status', 'is', ['values' => ['on_loan']]))
        ->assertCanSeeTableRecords([$loaned])
        ->assertCanNotSeeTableRecords([$boxed]);
});

function aal_docRow(array $extra): array
{
    return array_merge(['Document Identifier' => 'CS-' . uniqid(), 'Subseries' => 'REG'], $extra);
}

it('imports the label or the code of a status added on the page, and its old spellings', function (): void {
    CustodyStatusType::create(['code' => 'on_loan', 'label' => 'Out on Loan', 'is_active' => true]);

    $rows = [
        aal_docRow(['Custody Status' => 'out on loan']),
        aal_docRow(['Custody Status' => 'ON_LOAN']),
        aal_docRow(['Custody Status' => 'In box']),
        aal_docRow(['Custody Status' => 'not in box']),
        aal_docRow(['Custody Status' => 'Mounted']),
        aal_docRow(['Custody Status' => 'Mounted; no box']),
        aal_docRow(['Custody Status' => 'Conservation Box']),
    ];
    $import = aal_import(DocumentImporter::class, $rows);

    expect($import->successful_rows)->toBe(7);
    $custody = fn (array $row): string => Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', $row['Document Identifier'])->value('custody_status');
    expect(array_map($custody, $rows))->toBe(['on_loan', 'on_loan', 'in_box', 'not_in_box', 'mounted_no_box', 'mounted_no_box', 'conservation_box']);
});

it('fails the row on a status the page does not list, naming the ones it does', function (): void {
    $row = aal_docRow(['Custody Status' => 'Teleported']);

    $import = aal_import(DocumentImporter::class, [$row]);

    expect($import->successful_rows)->toBe(0)
        ->and(Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', $row['Document Identifier'])->exists())->toBeFalse();
    $failed = DB::table('failed_import_rows')->where('import_id', $import->id)->value('validation_error');
    expect($failed)->toContain('Not in Box');
});

it('sees a status added by another process when the next slice starts: no stale list in a queue worker', function (): void {
    // The worker has already read the list.
    expect(Document::custodyLabels())->not->toHaveKey('on_loan');

    // Another process (the web request) adds a status: the model hooks of THIS
    // process do not run.
    DB::table('custody_status_types')->insert(['code' => 'on_loan', 'label' => 'On Loan', 'is_active' => true, 'sort_order' => 50]);
    expect(Document::custodyLabels())->not->toHaveKey('on_loan');

    $row = aal_docRow(['Custody Status' => 'On Loan']);
    $import = aal_import(DocumentImporter::class, [$row]);

    expect($import->successful_rows)->toBe(1)
        ->and(Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', $row['Document Identifier'])->value('custody_status'))->toBe('on_loan');
});

it('reads the list once per request, and the queue worker forgets it between jobs', function (): void {
    DB::enableQueryLog();
    Document::custodyLabels();
    CustodyStatusType::labels();
    CustodyStatusType::codes();
    expect(collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], 'custody_status_types')))->toHaveCount(1);
    DB::disableQueryLog();

    DB::table('custody_status_types')->where('code', 'in_box')->update(['label' => 'Renamed elsewhere']);
    expect(Document::custodyLabels()['in_box'])->toBe('In Box');

    // What Queue\Worker's reset does between jobs.
    app()->forgetScopedInstances();
    expect(Document::custodyLabels()['in_box'])->toBe('Renamed elsewhere');
});

it('import: never writes not_in_box when the page has deactivated it', function (): void {
    CustodyStatusType::where('code', 'not_in_box')->firstOrFail()->update(['is_active' => false]);

    $row = aal_docRow(['Custody Status' => '']);
    aal_import(DocumentImporter::class, [$row]);

    expect(Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', $row['Document Identifier'])->value('custody_status'))->toBe('in_box');
});

it('import: a blank cell leaves a status that is neither in nor out of a box alone', function (): void {
    CustodyStatusType::create(['code' => 'on_loan', 'label' => 'On Loan', 'is_active' => true]);
    $row = aal_docRow(['Custody Status' => '']);
    aal_import(DocumentImporter::class, [$row]);
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->where('identifier', $row['Document Identifier'])->firstOrFail();
    expect($doc->custody_status)->toBe('not_in_box');

    foreach (['on_loan', 'conservation_box', 'mounted_no_box'] as $status) {
        $doc->update(['custody_status' => $status]);
        aal_import(DocumentImporter::class, [array_merge($row, ['Custody Status' => ''])], ['overwrite_existing' => true]);
        expect($doc->refresh()->custody_status)->toBe($status);
    }
});
