<?php

declare(strict_types=1);

use App\Filament\Imports\AuthorityImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Authority;
use App\Models\Repository;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Jobs\ImportCsv;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/*
 * Client, 2026-09-25: "The Citing Reference Code is the primary key as it is
 * failing to import" and "The NAM Authority Reference Code is optional".
 *
 * Her sheet of 676 creators settles it: the Citing Reference Code is filled in
 * 676 times and every value is distinct; the NAM code is filled in 80 times.
 * The importer required the NAM code and matched rows on it, so 596 rows failed
 * on a value the archive does not hold, and the 80 that passed would have
 * deduplicated on the wrong column.
 *
 * The shape of her data is what these tests reproduce, not a tidy invention:
 * most rows carry no NAM code at all, and the few that do read "MT AF-P000058".
 */
uses(RefreshDatabase::class);

function crc_admin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $user = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id]);
    $user->assignRole('super_admin');
    $user->repositories()->syncWithoutDetaching([$repo->id => ['is_default' => true]]);

    return $user;
}

/** One row shaped like hers: Citing always, NAM only sometimes. */
function crc_row(string $citing, ?string $nam = null, string $surname = 'Borg'): array
{
    return [
        'NAM Authority Reference Code' => $nam ?? '',
        'Citing Reference Code' => $citing,
        'Alternate Reference Code' => '',
        'Past Reference Code' => '',
        'Type of Entity' => 'Person',
        'Creator Surname' => $surname,
        'Creator Name' => 'Anna',
        'Authorised form of name' => $surname . ', Anna',
    ];
}

function crc_import(array $rows, User $user): Import
{
    $map = ImportWizard::guessColumnMap(AuthorityImporter::class, array_keys($rows[0]));
    $import = Import::query()->create([
        'completed_at' => null, 'file_name' => 'a.csv', 'file_path' => '/tmp/a.csv',
        'importer' => AuthorityImporter::class, 'processed_rows' => 0,
        'total_rows' => count($rows), 'successful_rows' => 0, 'user_id' => $user->id,
    ]);
    app(ImportCsv::class, [
        'import' => $import,
        'rows' => base64_encode(serialize($rows)),
        'columnMap' => $map,
        'options' => [],
    ])->handle();

    return $import->refresh();
}

it('imports a row that has no NAM Authority Reference Code', function (): void {
    $user = crc_admin();
    $this->actingAs($user);

    // The exact failure she reported: 596 of 676 rows rejected for a missing
    // NAM code. One such row is enough to pin it.
    $rows = [crc_row('R1')];
    $map = ImportWizard::guessColumnMap(AuthorityImporter::class, array_keys($rows[0]));
    $result = ImportWizard::validateRows(AuthorityImporter::class, $rows, $map);

    expect($result['invalid'])->toBe(0)
        ->and($result['errors'])->toBe([]);

    crc_import($rows, $user);

    $authority = Authority::where('alternative_identifier', 'R1')->first();
    expect($authority)->not->toBeNull()
        ->and($authority->identifier)->toBeNull();
});

it('rejects a row with no Citing Reference Code, which is now the required one', function (): void {
    $this->actingAs(crc_admin());

    $rows = [crc_row('', 'MT AF-P000058')];
    $map = ImportWizard::guessColumnMap(AuthorityImporter::class, array_keys($rows[0]));
    $result = ImportWizard::validateRows(AuthorityImporter::class, $rows, $map);

    expect($result['invalid'])->toBe(1)
        ->and(array_column($result['errors'], 'field'))->toContain('Citing Reference Code');
});

it('matches an existing creator on the Citing Reference Code, not on the NAM code', function (): void {
    $user = crc_admin();
    $this->actingAs($user);

    crc_import([crc_row('R1', null, 'Borg')], $user);
    expect(Authority::count())->toBe(1);

    // Same creator, same Citing code, and now a NAM code has been catalogued.
    // Matching on the NAM code would have inserted a second Borg; matching on
    // the Citing code updates the one that is there.
    crc_import([crc_row('R1', 'MT AF-P000058', 'Borg')], $user);

    expect(Authority::count())->toBe(1)
        ->and(Authority::first()->identifier)->toBe('MT AF-P000058');
});

it('keeps two creators apart when neither has a NAM code', function (): void {
    $user = crc_admin();
    $this->actingAs($user);

    // 596 of her rows look exactly like this pair. Matching on an empty NAM
    // code would collapse them into one record, or skip the second as a
    // duplicate — both silent, both wrong.
    crc_import([crc_row('R1', null, 'Borg'), crc_row('R2', null, 'Vella')], $user);

    expect(Authority::count())->toBe(2)
        ->and(Authority::pluck('alternative_identifier')->sort()->values()->all())->toBe(['R1', 'R2']);
});

it('re-imports the same sheet without inserting anything or failing on itself', function (): void {
    $user = crc_admin();
    $this->actingAs($user);

    $rows = [crc_row('R1', 'MT AF-P000058'), crc_row('R2'), crc_row('R3')];
    crc_import($rows, $user);
    expect(Authority::count())->toBe(3);

    // A re-run has to be uneventful. This is where a unique rule on the NAM
    // code inside the importer would have failed every row carrying one,
    // against the record it had just created.
    $second = crc_import($rows, $user);

    expect(Authority::count())->toBe(3)
        ->and($second->getFailedRowsCount())->toBe(0);
});

it('still finds a creator by either code when a document refers to it', function (): void {
    $user = crc_admin();
    $this->actingAs($user);

    crc_import([crc_row('R4', 'MT AF-P000058', 'Zammit')], $user);
    $expected = Authority::where('alternative_identifier', 'R4')->value('id');

    // Documents cite creators by whichever code the cataloguer had to hand.
    // Both have to arrive at the same record — this is what stops the swap
    // from quietly detaching documents from their notary.
    expect(EntityResolver::resolveAuthority('R4'))
        ->toMatchArray(['authority_id' => $expected])
        ->and(EntityResolver::resolveAuthority('MT AF-P000058'))
        ->toMatchArray(['authority_id' => $expected, 'method' => 'identifier']);
});

it('refuses a second creator with the same Citing Reference Code', function (): void {
    $user = crc_admin();
    $this->actingAs($user);

    Authority::create(['alternative_identifier' => 'R9', 'surname' => 'Borg']);

    // Not a format rule — a uniqueness one. The key has to stay a key, or
    // resolving a document's creator becomes a guess between two records.
    expect(fn () => Authority::create(['alternative_identifier' => 'R9', 'surname' => 'Vella']))
        ->toThrow(QueryException::class);
});

it('accepts a code that does not start with R or I', function (): void {
    $this->actingAs(crc_admin());

    // 675 of her codes start with R and one with I, but the old rules pinned a
    // shape on the wrong column and blocked the import outright. A code that
    // does not fit the pattern is the archive's business, not a reason to stop.
    $rows = [crc_row('X-900'), crc_row('R1', 'ANYTHING-GOES-HERE')];
    $map = ImportWizard::guessColumnMap(AuthorityImporter::class, array_keys($rows[0]));

    expect(ImportWizard::validateRows(AuthorityImporter::class, $rows, $map)['invalid'])->toBe(0);
});

it('names the column the way it is written when a row is missing it', function (): void {
    $this->actingAs(crc_admin());

    $rows = [crc_row('')];
    $map = ImportWizard::guessColumnMap(AuthorityImporter::class, array_keys($rows[0]));
    $messages = array_column(ImportWizard::validateRows(AuthorityImporter::class, $rows, $map)['errors'], 'message');

    // Filament lower-cases the first letter of the label to build this
    // sentence, which rendered "The citing Reference Code field is required."
    // She reads this message on every bad row; it should at least spell the
    // column the way the column is spelled.
    expect($messages)->toContain('The Citing Reference Code field is required.')
        ->and(implode(' ', $messages))->not->toContain('citing Reference Code field');
});
