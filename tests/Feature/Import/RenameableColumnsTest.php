<?php

declare(strict_types=1);

use App\Filament\Imports\AuthorityImporter;
use App\Filament\Pages\ImportWizard;
use App\Filament\Resources\AuthorityResource;
use App\Filament\Resources\AuthorityResource\Pages\ListAuthorities;
use App\Models\Authority;
use App\Models\ColumnLabelOverride;
use App\Models\CustomFieldDefinition;
use App\Models\Repository;
use App\Models\User;
use App\Support\BulkImport\TemplateGenerator;
use App\Support\ColumnLabels\ColumnLabels;
use App\Support\CustomFields\CustomFieldResolver;
use Filament\Actions\Imports\Jobs\ImportCsv;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\Field;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;

/*
 * Client request, 2026-09-24. Her Authority columns had been renamed three
 * times in nine days, each one a code change, a pull request and a deploy:
 * "It is becoming obvious that we are still renaming/adding new columns. The
 * columns are often not dependent on other parts."
 *
 * Two halves. Adding a column was already hers to do through custom fields —
 * Authorities just were not among the entities that had them. Renaming a
 * BUILT-IN column was nobody's to do without a release, and that is what
 * ColumnLabels adds.
 *
 * The thing worth pinning is not that a label changes, but that the label
 * changes EVERYWHERE AT ONCE: rename a column and the template header, the
 * importer's guess and the form all move together. A rename that reached the
 * template but not the importer would leave an orphan header — a column the
 * cataloguer fills in and the import silently discards.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
    ColumnLabels::flushMemo();
    CustomFieldResolver::flush();
});

afterEach(function (): void {
    ColumnLabels::flushMemo();
    CustomFieldResolver::flush();
});

function rc_admin(Repository $repo): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create([
        'email' => 'rename+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repo->id,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function rc_rename(Repository $repo, string $field, string $label): void
{
    ColumnLabelOverride::create([
        'repository_id' => $repo->id,
        'entity_type' => 'authority',
        'field_key' => $field,
        'label' => $label,
    ]);
    ColumnLabels::flushMemo();
}

/** Headers of the generated template, and the map the wizard guesses for them. */
function rc_headersAndMap(): array
{
    $headers = TemplateGenerator::headersFor('authority');
    $map = ImportWizard::guessColumnMap(AuthorityImporter::class, $headers);
    $claimed = array_values(array_filter($map, fn ($h) => $h !== null));

    return [$headers, $map, array_values(array_diff($headers, $claimed))];
}

it('ships the factory names when nothing has been renamed', function (): void {
    $repo = Repository::factory()->create(['code' => 'RC1']);
    $this->actingAs(rc_admin($repo));

    [$headers, , $orphans] = rc_headersAndMap();

    expect($headers[0])->toBe('NAM Authority Reference Code');
    expect($orphans)->toBe([]);
});

it('carries a rename into the template, the importer and the form together', function (): void {
    $repo = Repository::factory()->create(['code' => 'RC2']);
    $this->actingAs(rc_admin($repo));

    rc_rename($repo, 'date_of_creation', 'Creation Date Range');

    [$headers, $map, $orphans] = rc_headersAndMap();

    // Template: the new name, and the old one gone.
    expect($headers)->toContain('Creation Date Range');
    expect($headers)->not->toContain('Date of creation');

    // Importer: recognises the renamed header without manual mapping. This is
    // the half that is easy to forget and impossible to notice — the column
    // would just import blank.
    expect($map['date_of_creation'] ?? null)->toBe('Creation Date Range');
    expect($orphans)->toBe([]);

    // Form and record view read the same resolver.
    expect(ColumnLabels::get('authority', 'date_of_creation'))->toBe('Creation Date Range');
});

it('imports a sheet whose header uses the renamed column', function (): void {
    $repo = Repository::factory()->create(['code' => 'RC3']);
    $user = rc_admin($repo);
    $this->actingAs($user);

    rc_rename($repo, 'date_of_creation', 'Creation Date Range');

    [$headers, $map] = rc_headersAndMap();

    $row = array_fill_keys($headers, '');
    $row['Citing Reference Code'] = 'R-RENAME-1';
    $row['Creator Surname'] = 'Borg';
    $row['Creator Name'] = 'Anna';
    $row['Type of Entity'] = 'Notary';
    $row['Creation Date Range'] = '1607-1629';

    $import = Import::query()->create([
        'completed_at' => null, 'file_name' => 'r.csv', 'file_path' => '/tmp/r.csv',
        'importer' => AuthorityImporter::class, 'processed_rows' => 0,
        'total_rows' => 1, 'successful_rows' => 0, 'user_id' => $user->id,
    ]);
    app(ImportCsv::class, [
        'import' => $import,
        'rows' => base64_encode(serialize([$row])),
        'columnMap' => $map,
        'options' => [],
    ])->handle();

    // The value reaches the field the renamed column still stands for.
    expect(Authority::where('alternative_identifier', 'R-RENAME-1')->value('date_of_creation'))->toBe('1607-1629');
});

it('names the renamed column in the validation report, not the old name', function (): void {
    $repo = Repository::factory()->create(['code' => 'RC7']);
    $this->actingAs(rc_admin($repo));

    rc_rename($repo, 'alternative_identifier', 'Archive Reference');

    [$headers, $map] = rc_headersAndMap();

    // A row missing the required code: the preflight has to tell her which
    // column to fix, using the name she gave it. This is the label's own job —
    // the guess list cannot do it, which is why both are wired up.
    $row = array_fill_keys($headers, '');
    $row['Creator Surname'] = 'Borg';

    $result = ImportWizard::validateRows(AuthorityImporter::class, [$row], $map);

    expect($result['invalid'])->toBe(1);
    $fields = array_column($result['errors'], 'field');
    expect($fields)->toContain('Archive Reference');
    expect($fields)->not->toContain('Citing Reference Code');
});

it('still accepts the factory name on a sheet downloaded before the rename', function (): void {
    $repo = Repository::factory()->create(['code' => 'RC8']);
    $this->actingAs(rc_admin($repo));

    rc_rename($repo, 'date_of_creation', 'Creation Date Range');

    // She downloaded her sheet before renaming the column, so its header still
    // reads "Date of creation". The template offers the new name; the importer
    // has to answer to both, which is what the guess list adds on top of the
    // label.
    $map = ImportWizard::guessColumnMap(AuthorityImporter::class, [
        'NAM Authority Reference Code',
        'Date of creation',
    ]);

    expect($map['date_of_creation'] ?? null)->toBe('Date of creation');
});

it('restores the factory name when the rename is deleted', function (): void {
    $repo = Repository::factory()->create(['code' => 'RC4']);
    $this->actingAs(rc_admin($repo));

    rc_rename($repo, 'notes', 'Remarks');
    expect(TemplateGenerator::headersFor('authority'))->toContain('Remarks');

    ColumnLabelOverride::query()->delete();
    ColumnLabels::flushMemo();

    [$headers, , $orphans] = rc_headersAndMap();
    expect($headers)->toContain('Notes');
    expect($headers)->not->toContain('Remarks');
    expect($orphans)->toBe([]);
});

it('ignores a rename aimed at a column that is not renameable', function (): void {
    $repo = Repository::factory()->create(['code' => 'RC5']);
    $this->actingAs(rc_admin($repo));

    // A stale row — the field was removed from DEFAULTS since — must not
    // introduce a column nothing reads.
    ColumnLabelOverride::create([
        'repository_id' => $repo->id,
        'entity_type' => 'authority',
        'field_key' => 'a_field_that_no_longer_exists',
        'label' => 'Ghost Column',
    ]);
    ColumnLabels::flushMemo();

    [$headers, , $orphans] = rc_headersAndMap();

    expect($headers)->not->toContain('Ghost Column');
    expect($headers)->toHaveCount(19);
    expect($orphans)->toBe([]);
});

it('keeps one repository\'s renames out of another\'s', function (): void {
    $a = Repository::factory()->create(['code' => 'RCA']);
    $b = Repository::factory()->create(['code' => 'RCB']);

    rc_rename($a, 'notes', 'Only In A');

    $this->actingAs(rc_admin($b));
    ColumnLabels::flushMemo();

    expect(TemplateGenerator::headersFor('authority'))->not->toContain('Only In A');
});

it('lets a renamed column and an added column coexist', function (): void {
    $repo = Repository::factory()->create(['code' => 'RC6']);
    $this->actingAs(rc_admin($repo));

    rc_rename($repo, 'notes', 'Remarks');
    CustomFieldDefinition::create([
        'repository_id' => $repo->id,
        'entity_type' => 'authority',
        'key' => 'place_of_deposit',
        'label' => 'Place of Deposit',
        'type' => 'text',
        'is_active' => true,
        'sort_order' => 1,
    ]);
    CustomFieldResolver::flush();
    ColumnLabels::flushMemo();

    [$headers, , $orphans] = rc_headersAndMap();

    // Both halves of the request, working at the same time: one column renamed,
    // one added, and the importer still recognises every header.
    expect($headers)->toContain('Remarks');
    expect($headers)->toContain('Place of Deposit');
    expect($headers)->toHaveCount(20);
    expect($orphans)->toBe([]);
});

it('labels the form fields with the renamed column, not the factory name', function (): void {
    $repo = Repository::factory()->create(['code' => 'RC10']);
    $this->actingAs(rc_admin($repo));

    rc_rename($repo, 'identifier', 'Archive Reference');
    rc_rename($repo, 'alternative_identifier', 'Citation Code');

    // The form is where she meets the column most often, and Filament builds
    // its required/unique messages from the label — so getting the label right
    // is what makes an error name a field she can find on screen.
    //
    // Until 2026-09-25 this test pinned two hand-written regex messages
    // instead. Those rules are gone: they described the wrong columns.
    $schema = Schema::make(Livewire::test(ListAuthorities::class)->instance());

    $labels = [];
    foreach (AuthorityResource::form($schema)->getFlatComponents(withHidden: true) as $field) {
        if ($field instanceof Field) {
            $labels[$field->getName()] = $field->getLabel();
        }
    }

    expect($labels['identifier'] ?? null)->toBe('Archive Reference')
        ->and($labels['alternative_identifier'] ?? null)->toBe('Citation Code');
});

it('actually stores the value of an added column, not just its header', function (): void {
    $repo = Repository::factory()->create(['code' => 'RC11']);
    $user = rc_admin($repo);
    $this->actingAs($user);

    CustomFieldDefinition::create([
        'repository_id' => $repo->id,
        'entity_type' => 'authority',
        'key' => 'place_of_deposit',
        'label' => 'Place of Deposit',
        'type' => 'text',
        'is_active' => true,
        'sort_order' => 1,
    ]);
    CustomFieldResolver::flush();
    ColumnLabels::flushMemo();

    [$headers, $map] = rc_headersAndMap();

    $row = array_fill_keys($headers, '');
    $row['Citing Reference Code'] = 'R-CF-1';
    $row['Creator Surname'] = 'Borg';
    $row['Creator Name'] = 'Anna';
    $row['Place of Deposit'] = 'Valletta strongroom';

    $import = Import::query()->create([
        'completed_at' => null, 'file_name' => 'cf.csv', 'file_path' => '/tmp/cf.csv',
        'importer' => AuthorityImporter::class, 'processed_rows' => 0,
        'total_rows' => 1, 'successful_rows' => 0, 'user_id' => $user->id,
    ]);
    app(ImportCsv::class, [
        'import' => $import,
        'rows' => base64_encode(serialize([$row])),
        'columnMap' => $map,
        'options' => [],
    ])->handle();

    // A header that is accepted and then dropped is worse than one that is
    // rejected: the cataloguer fills the column in and the value disappears
    // without a word. Authorities have no repository_id of their own, so the
    // definitions have to be scoped by the ACTIVE repository instead.
    $authority = Authority::where('alternative_identifier', 'R-CF-1')->firstOrFail();
    expect($authority->getCustomFieldData())->toBe(['place_of_deposit' => 'Valletta strongroom']);
});

it('still shows a stored added-column value when no repository is active', function (): void {
    $repo = Repository::factory()->create(['code' => 'RC12']);
    $user = rc_admin($repo);
    $this->actingAs($user);

    $def = CustomFieldDefinition::create([
        'repository_id' => $repo->id,
        'entity_type' => 'authority',
        'key' => 'place_of_deposit',
        'label' => 'Place of Deposit',
        'type' => 'text',
        'is_active' => true,
        'sort_order' => 1,
    ]);
    CustomFieldResolver::flush();

    $authority = Authority::create(['identifier' => 'R-NULLREPO-1', 'surname' => 'Borg']);
    $authority->setCustomFieldData(['place_of_deposit' => 'Valletta strongroom'], false);

    // "All repositories" in the topbar resolves to a null active repository,
    // and so does a queue worker once the import job has ended. Scoping the
    // read on that alone made a value written a minute earlier read as absent.
    CustomFieldResolver::flush();
    $user->forceFill(['default_repository_id' => null])->save();
    auth()->logout();

    expect(CustomFieldResolver::activeRepositoryId())->toBeNull()
        ->and($authority->fresh()->getCustomFieldData())->toBe(['place_of_deposit' => 'Valletta strongroom']);

    // But a record with nothing stored must not inherit another archive's
    // columns just because no repository is selected.
    expect(Authority::create(['identifier' => 'R-NULLREPO-2', 'surname' => 'Vella'])->getCustomFieldData())->toBe([]);
    expect($def->fresh()->is_active)->toBeTrue();
});

it('keeps the dropdown on a renamed controlled-vocabulary column', function (): void {
    $repo = Repository::factory()->create(['code' => 'RC13']);
    $this->actingAs(rc_admin($repo));

    rc_rename($repo, 'level_of_detail', 'Cataloguing Depth');

    $path = tempnam(sys_get_temp_dir(), 'tpl') . '.xlsx';
    $response = TemplateGenerator::download('authority');
    ob_start();
    $response->sendContent();
    file_put_contents($path, (string) ob_get_clean());
    $sheet = IOFactory::load($path)->getSheet(0);

    $headers = [];
    foreach ($sheet->getRowIterator(1, 1) as $row) {
        foreach ($row->getCellIterator() as $cell) {
            $headers[] = (string) $cell->getValue();
        }
    }
    $index = array_search('Cataloguing Depth', $headers, strict: true);
    expect($index)->not->toBeFalse();

    // The dropdown is the only thing stopping a free-typed value that the
    // import will then reject row by row. Keying the list by header meant a
    // rename quietly removed it: the sheet still looked right.
    $letter = Coordinate::stringFromColumnIndex($index + 1);
    $validation = $sheet->getCell($letter . '2')->getDataValidation();

    expect($validation->getType())->toBe(DataValidation::TYPE_LIST)
        ->and($validation->getFormula1())->toContain('Full Level')
        ->and($validation->getError())->toStartWith('Cataloguing Depth must be one of');

    // nosemgrep: php.lang.security.unlink-use.unlink-use -- $path is the temp xlsx this test just wrote via tempnam(), never user input.
    @unlink($path);
});

it('refuses to rename a column to a name another column already uses', function (): void {
    $repo = Repository::factory()->create(['code' => 'RC14']);
    $this->actingAs(rc_admin($repo));

    CustomFieldDefinition::create([
        'repository_id' => $repo->id, 'entity_type' => 'authority',
        'key' => 'place_of_deposit', 'label' => 'Place of Deposit',
        'type' => 'text', 'is_active' => true, 'sort_order' => 1,
    ]);
    CustomFieldResolver::flush();
    ColumnLabels::flushMemo();

    // Two columns with the same header is the one failure the operator cannot
    // see: the sheet looks right, and the importer quietly keeps one of them.
    // Both directions have to be refused — clashing with a built-in name and
    // clashing with a column she added herself.
    foreach ([['notes', 'Status'], ['notes', 'Place of Deposit']] as [$field, $clash]) {
        expect(fn () => rc_rename($repo, $field, $clash))
            ->toThrow(ValidationException::class);
    }

    // And the headers stay unique whatever happens.
    [$headers] = rc_headersAndMap();
    expect($headers)->toHaveCount(count(array_unique($headers)));
});
