<?php

declare(strict_types=1);

use App\Filament\Imports\SeriesImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Series;
use App\Models\User;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use App\Support\BulkImport\TemplateGenerator;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Bus\PendingBatch as PendingBatchContract;
use Illuminate\Support\Facades\Bus;
use Spatie\Permission\Models\Role;

/*
 * Client request, 2026-09-14. The Series sheet marks rows as "Series" or
 * "SubSeries", but that text only NAMES the level — it never attached a row to
 * anything, so the cataloguer was setting every parent by hand after each
 * import. The template now carries a "Parent" column and the importer wires the
 * hierarchy up in one pass.
 *
 * Two halves are tested: the importer resolving one row's parent, and the
 * wizard ordering rows so a parent is always created before its children.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

function sp_admin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create([
        'email' => 'series-parent+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

/** Drive the importer's real pipeline over one row, as the queue job does. */
function sp_import(array $data, int $userId): void
{
    $import = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'series.xlsx',
        'file_path' => '/tmp/series.xlsx',
        'importer' => SeriesImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);

    $columnMap = array_combine(array_keys($data), array_keys($data));
    $importer = new SeriesImporter($import, $columnMap, []);
    $importer($data);
}

/* ── the importer ─────────────────────────────────────────────────────── */

it('attaches a subseries to the parent named in the sheet', function (): void {
    $user = sp_admin();
    $this->actingAs($user);

    sp_import(['code' => 'R', 'title' => 'Register Copies', 'level_of_description' => 'Series'], $user->id);
    sp_import(['code' => 'REG', 'title' => 'Registers', 'level_of_description' => 'SubSeries', 'parent_code' => 'R'], $user->id);

    $reg = Series::where('code', 'REG')->firstOrFail();
    $r = Series::where('code', 'R')->firstOrFail();

    expect($reg->parent_id)->toBe($r->id);
    // The whole point of the request: the tree, not just the word.
    expect($reg->qualifiedTitle())->toContain('R');
});

it('accepts the pasted "CODE: label" form operators use in identifier columns', function (): void {
    $user = sp_admin();
    $this->actingAs($user);

    sp_import(['code' => 'R', 'title' => 'Register Copies'], $user->id);
    sp_import(['code' => 'RWL', 'title' => 'Public Wills', 'parent_code' => 'R: Register Copies (Registro)'], $user->id);

    expect(Series::where('code', 'RWL')->firstOrFail()->parent_id)
        ->toBe(Series::where('code', 'R')->firstOrFail()->id);
});

it('fails the row when the named parent does not exist, instead of importing it flat', function (): void {
    $user = sp_admin();
    $this->actingAs($user);

    // Silently importing this as top-level is the outcome being prevented: the
    // cataloguer would believe the tree was built and see a flat list.
    expect(fn () => sp_import(['code' => 'REG', 'title' => 'Registers', 'parent_code' => 'NOPE'], $user->id))
        ->toThrow(ValidationException::class);

    expect(Series::where('code', 'REG')->exists())->toBeFalse();
});

it('refuses to make a series its own parent', function (): void {
    $user = sp_admin();
    $this->actingAs($user);

    expect(fn () => sp_import(['code' => 'R', 'title' => 'Register Copies', 'parent_code' => 'r'], $user->id))
        ->toThrow(ValidationException::class);
});

it('refuses a parent that would close a loop', function (): void {
    $user = sp_admin();
    $this->actingAs($user);

    sp_import(['code' => 'R', 'title' => 'Register Copies'], $user->id);
    sp_import(['code' => 'REG', 'title' => 'Registers', 'parent_code' => 'R'], $user->id);

    // R is already above REG; putting R under REG would make the chain eat itself.
    expect(fn () => sp_import(['code' => 'R', 'title' => 'Register Copies', 'parent_code' => 'REG'], $user->id))
        ->toThrow(ValidationException::class);

    expect(Series::where('code', 'R')->firstOrFail()->parent_id)->toBeNull();
});

it('leaves an existing parent alone when the Parent cell is blank', function (): void {
    $user = sp_admin();
    $this->actingAs($user);

    sp_import(['code' => 'R', 'title' => 'Register Copies'], $user->id);
    sp_import(['code' => 'REG', 'title' => 'Registers', 'parent_code' => 'R'], $user->id);

    // Re-importing a sheet with the column left empty must not flatten a
    // hierarchy someone set up — blank means "not stated", not "no parent".
    sp_import(['code' => 'REG', 'title' => 'Registers', 'parent_code' => ''], $user->id);

    expect(Series::where('code', 'REG')->firstOrFail()->parent_id)
        ->toBe(Series::where('code', 'R')->firstOrFail()->id);
});

/* ── the wizard's row ordering ────────────────────────────────────────── */

function sp_order(array $rows): array
{
    $method = new ReflectionMethod(ImportWizard::class, 'sortSeriesRowsParentsFirst');
    $method->setAccessible(true);

    return array_column(
        $method->invoke(null, $rows, ['code' => 'Identifier', 'parent_code' => 'Parent']),
        'Identifier'
    );
}

it('moves a parent ahead of a child listed before it', function (): void {
    // The sheet as an operator might sort it — alphabetically, children first.
    $ordered = sp_order([
        ['Identifier' => 'REG', 'Parent' => 'R'],
        ['Identifier' => 'RWL', 'Parent' => 'REG'],
        ['Identifier' => 'R', 'Parent' => ''],
    ]);

    expect($ordered)->toBe(['R', 'REG', 'RWL']);
});

it('keeps rows whose parent is not in the sheet, for the database to resolve', function (): void {
    $ordered = sp_order([
        ['Identifier' => 'REG', 'Parent' => 'ALREADY-IN-DB'],
        ['Identifier' => 'R', 'Parent' => ''],
    ]);

    expect($ordered)->toHaveCount(2)->toContain('REG')->toContain('R');
});

it('does not hang on a cycle, and returns every row for the importer to reject', function (): void {
    // A → B → A. No ordering exists; the loop must terminate rather than spin.
    $ordered = sp_order([
        ['Identifier' => 'A', 'Parent' => 'B'],
        ['Identifier' => 'B', 'Parent' => 'A'],
    ]);

    expect($ordered)->toHaveCount(2)->toContain('A')->toContain('B');
});

it('leaves an older sheet without a Parent column untouched', function (): void {
    $rows = [['Identifier' => 'REG'], ['Identifier' => 'R']];
    $method = new ReflectionMethod(ImportWizard::class, 'sortSeriesRowsParentsFirst');
    $method->setAccessible(true);

    // Only 'code' is mapped — no Parent column in the file at all.
    expect($method->invoke(null, $rows, ['code' => 'Identifier']))->toBe($rows);
});

/* ── template ↔ importer round trip ──────────────────────────────────── */

it('maps the generated template\'s Parent column onto the importer without manual mapping', function (): void {
    // A column the operator has to map by hand is a column most operators will
    // leave unmapped, and an unmapped Parent imports the whole sheet flat with
    // no error. The guess list and the template header must agree.
    $headers = TemplateGenerator::headersFor('series');
    $map = ImportWizard::guessColumnMap(SeriesImporter::class, $headers);

    expect($map['parent_code'] ?? null)->toBe('Parent');
    expect($map['code'] ?? null)->toBe('Identifier');
    expect($map['level_of_description'] ?? null)->toBe('Level of description');
});

it('builds the whole tree from the example sheet shipped to the client', function (): void {
    $user = sp_admin();
    $this->actingAs($user);

    // nra/ holds real client data and is intentionally untracked, so the sheet
    // is absent in CI and in a fresh checkout. Skip cleanly there, exactly as
    // tests/Pest.php does for the whole Feature/Import/Generated suite —
    // asserting the file exists would turn "fixture unavailable" into a red
    // build. The skip is scoped to this one case on purpose: the other twelve
    // tests in this file need no fixture and must keep running in CI.
    $path = base_path('nra/outbox/2026-07-22_NAF_import_examples/example_series_import.xlsx');
    if (! is_file($path)) {
        test()->markTestSkipped('client import fixtures (nra/) are untracked and absent in this environment');
    }

    $sheet = Excel::toArray(new class {}, $path)[0];
    $headers = array_map(static fn ($h): string => trim((string) $h), $sheet[0]);
    $map = ImportWizard::guessColumnMap(SeriesImporter::class, $headers);
    $map = array_filter($map, static fn ($v): bool => $v !== null && $v !== '');

    $rows = [];
    foreach (array_slice($sheet, 1) as $line) {
        if (! is_array($line) || trim((string) ($line[0] ?? '')) === '') {
            continue;
        }
        $rows[] = array_combine($headers, array_pad(array_slice($line, 0, count($headers)), count($headers), null));
    }
    expect($rows)->not->toBeEmpty();

    // Order exactly as the wizard does before dispatching the chunks.
    $reflection = new ReflectionMethod(ImportWizard::class, 'sortSeriesRowsParentsFirst');
    $reflection->setAccessible(true);
    $rows = $reflection->invoke(null, $rows, $map);

    foreach ($rows as $row) {
        sp_import(array_combine(
            array_keys($map),
            array_map(static fn (string $excel) => $row[$excel] ?? null, $map)
        ), $user->id);
    }

    // Every SubSeries row in the sheet must have come out attached.
    $reg = Series::where('code', 'REG')->firstOrFail();
    $owl = Series::where('code', 'OWL')->firstOrFail();
    expect($reg->parent->code)->toBe('R');
    expect($owl->parent->code)->toBe('O');
    // …and the two roots must stay roots.
    expect(Series::where('code', 'R')->firstOrFail()->parent_id)->toBeNull();
    expect(Series::where('code', 'O')->firstOrFail()->parent_id)->toBeNull();

    $orphans = Series::whereNull('parent_id')->pluck('code')->sort()->values()->all();
    expect($orphans)->toBe(['O', 'R']);
});

it('has the wizard itself apply the ordering before the rows reach the queue', function (): void {
    // The end-to-end test above calls sortSeriesRowsParentsFirst() directly, so
    // it stays green even if the wizard stops calling it — which is exactly the
    // regression that would send children to the queue ahead of their parents.
    // This asserts the wiring, not the helper.
    $user = sp_admin();
    $this->actingAs($user);

    Bus::fake();

    $rows = [
        ['Identifier' => 'REG', 'Standard title in English (Plural)' => 'Registers', 'Parent' => 'R'],
        ['Identifier' => 'R', 'Standard title in English (Plural)' => 'Register Copies', 'Parent' => ''],
    ];
    $map = ImportWizard::guessColumnMap(SeriesImporter::class, array_keys($rows[0]));
    $map = array_filter($map, static fn ($v): bool => $v !== null && $v !== '');

    $page = new ImportWizard;
    $dispatch = new ReflectionMethod(ImportWizard::class, 'dispatchImportBatch');
    $dispatch->setAccessible(true);
    $dispatch->invoke($page, SeriesImporter::class, 'series.xlsx', '/tmp/series.xlsx', $rows, $map, []);

    $dispatched = [];
    Bus::assertBatched(function (PendingBatchContract $batch) use (&$dispatched): bool {
        foreach ($batch->jobs as $job) {
            // The wizard base64-encodes a serialize()d chunk onto the job; reading
            // it back is the only way to see the order the queue will receive.
            // allowed_classes:false keeps this to plain arrays and scalars, so no
            // object can be instantiated from the payload — and the payload is
            // produced by the wizard in this same process, not by user input.
            /** @var array<int, array<string, mixed>> $chunk */
            // nosemgrep: php.lang.security.unserialize-use.unserialize-use
            $chunk = unserialize(
                base64_decode((new ReflectionProperty($job, 'rows'))->getValue($job)),
                ['allowed_classes' => false],
            );
            foreach ($chunk as $row) {
                $dispatched[] = $row['Identifier'] ?? null;
            }
        }

        return true;
    });

    expect($dispatched)->toBe(['R', 'REG']);
});
