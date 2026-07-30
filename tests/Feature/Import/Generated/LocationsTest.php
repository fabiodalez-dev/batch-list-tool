<?php

declare(strict_types=1);

use App\Filament\Imports\LocationImporter;
use App\Models\Location;
use App\Models\LocationType;
use App\Models\Repository;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use HayderHatem\FilamentExcelImport\Actions\Imports\Jobs\ImportExcel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;

/**
 * LocationImporter — REAL streaming path (ImportExcel), against a DIRTY
 * database, driven by the client's actual production files:
 *   - nra/inbox/prod-uploads/20260728_075809_36792a2a.csv (29 root locations)
 *   - nra/outbox/2026-07-22_NAF_import_examples/example_location_import.xlsx
 *     (2-row "Data" sheet + a "READ ME" instructions sheet)
 *
 * FOCUS: parent_name + repository_code virtual columns (phantom-attribute
 * INSERT bug), type validation against active location_types UNION
 * Location::TYPES, blank repository_code => global, auto-generated code,
 * parent resolution order/scoping.
 *
 * Rows below come from the real production CSV wherever possible (via
 * loc_realRow()/loc_realRows()) — the client's actual name/type/repository_code
 * combinations. Where a test needs a value the CSV genuinely never contains
 * (a missing parent, an unknown repository code, a custom/alias/invalid type,
 * an explicit code, an update-pass note) a real row is used as the base and
 * only the one or two cells the scenario needs are mutated — never a whole
 * hand-authored row.
 */
uses(RefreshDatabase::class);

const LOC_PROD_CSV = __DIR__ . '/../../../../nra/inbox/prod-uploads/20260728_075809_36792a2a.csv';
const LOC_XLSX_SAMPLE = __DIR__ . '/../../../../nra/outbox/2026-07-22_NAF_import_examples/example_location_import.xlsx';

function loc_admin(?int $repoId = null): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repoId]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * Run the REAL hayderhatem ImportExcel job (the streaming path the "Import
 * Excel/CSV" button dispatches) for the given rows.
 *
 * @param array<int, array<string, mixed>> $rows keyed by Excel header
 * @param array<string, string> $columnMap importerField => Excel header
 * @param array<string, mixed> $options
 */
function loc_run(array $rows, array $columnMap, int $userId, array $options = []): Import
{
    EntityResolver::flushMemo();
    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'locations.xlsx',
        'file_path' => '/tmp/locations.xlsx',
        'importer' => LocationImporter::class,
        'processed_rows' => 0,
        'total_rows' => count($rows),
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);

    $job = new ImportExcel(
        importId: $import->getKey(),
        rows: base64_encode(serialize($rows)),
        startRow: null,
        endRow: null,
        columnMap: $columnMap,
        options: $options,
    );
    $job->handle();

    return $import->refresh();
}

/** @return array<int, string> */
function loc_failures(Import $import): array
{
    return $import->failedRows()->pluck('validation_error')->all();
}

/** Standard column map matching the real production CSV/xlsx header row. */
function loc_columnMap(): array
{
    return [
        'name' => 'name',
        'type' => 'type',
        'parent_name' => 'parent_name',
        'repository_code' => 'repository_code',
        'code' => 'code',
        'notes' => 'notes',
        'sort_order' => 'sort_order',
        'is_active' => 'is_active',
    ];
}

/**
 * All 29 rows of the real production CSV, keyed by their real "name" cell,
 * exactly as the client's spreadsheet left them (headers === loc_columnMap()
 * values, so a row is directly usable as-is).
 *
 * @return array<string, array<string, string>>
 */
function loc_realRows(): array
{
    $csv = array_map(str_getcsv(...), file(LOC_PROD_CSV));
    $headers = array_map(fn ($h) => trim((string) $h), $csv[0]);
    $dataRows = array_slice($csv, 1);

    $rows = [];
    foreach ($dataRows as $row) {
        $keyed = [];
        foreach ($headers as $i => $header) {
            $keyed[$header] = $row[$i] ?? '';
        }
        $rows[$keyed['name']] = $keyed;
    }

    return $rows;
}

/**
 * One real row from the prod CSV, by its real "name" cell. Callers mutate
 * only the specific cell(s) their edge case needs, keeping everything else
 * exactly as the client uploaded it.
 *
 * @return array<string, string>
 */
function loc_realRow(string $name): array
{
    return loc_realRows()[$name];
}

// ─── Phantom-attribute INSERT bug (parent_name / repository_code) ───────────

test('a row that maps repository_code does not blow up the INSERT with a phantom column', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    // Real row: "Archive 1","Repository","","NRA","","","","" — maps
    // repository_code exactly like every root row in the client's file.
    $import = loc_run([loc_realRow('Archive 1')], loc_columnMap(), $u->id);

    expect(loc_failures($import))->toBe([]);
    $loc = Location::where('name', 'Archive 1')->first();
    expect($loc)->not->toBeNull()
        ->and($loc->repository_id)->toBe($repo->id);
});

test('a row that maps BOTH parent_name and repository_code does not blow up the INSERT', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    $parent = Location::create(['name' => 'Cataloguing', 'type' => 'room', 'repository_id' => $repo->id, 'is_active' => true]);

    // Real row, only parent_name mutated — the client's file never nests
    // locations, so a parent link is the one cell this scenario needs.
    $row = loc_realRow('Mould Room');
    $row['parent_name'] = 'Cataloguing';

    $import = loc_run([$row], loc_columnMap(), $u->id);

    expect(loc_failures($import))->toBe([]);
    $loc = Location::where('name', 'Mould Room')->first();
    expect($loc)->not->toBeNull()
        ->and($loc->parent_id)->toBe($parent->id)
        ->and($loc->repository_id)->toBe($repo->id);
});

// ─── Type validation: aliases, canonical, legacy, custom lookup rows ────────

test('a legacy alias type ("archive") is normalised to the canonical "repository"', function () {
    $u = loc_admin();
    $this->actingAs($u);

    // The client's file always writes the canonical "Repository", never the
    // legacy alias spelling — mutate the type cell (and blank repository_code,
    // since this test intentionally runs without a Repository fixture) to
    // exercise the alias path.
    $row = loc_realRow('Archive 1');
    $row['type'] = 'archive';
    $row['repository_code'] = '';

    $import = loc_run([$row], loc_columnMap(), $u->id);

    expect(loc_failures($import))->toBe([]);
    expect(Location::where('name', 'Archive 1')->value('type'))->toBe('repository');
});

test('a legacy alias type ("vetrina") is normalised to the canonical "showcase"', function () {
    $u = loc_admin();
    $this->actingAs($u);

    $row = loc_realRow('Showcase 1');
    $row['type'] = 'vetrina';
    $row['repository_code'] = '';

    $import = loc_run([$row], loc_columnMap(), $u->id);

    expect(loc_failures($import))->toBe([]);
    expect(Location::where('name', 'Showcase 1')->value('type'))->toBe('showcase');
});

test('uppercase type value from the real CSV ("Repository") is accepted case-insensitively', function () {
    $u = loc_admin();
    $this->actingAs($u);

    // Real row, type cell left exactly as the client wrote it ("Repository");
    // only repository_code is blanked because this test runs without a
    // Repository fixture.
    $row = loc_realRow('Archive 1');
    $row['repository_code'] = '';

    $import = loc_run([$row], loc_columnMap(), $u->id);

    expect(loc_failures($import))->toBe([]);
    expect(Location::where('name', 'Archive 1')->value('type'))->toBe('repository');
});

test('a legacy type ("shelf") not in the canonical lookup still imports via the Location::TYPES union', function () {
    $u = loc_admin();
    $this->actingAs($u);

    // location_types seeds only room/museum/repository. 'shelf' is legacy
    // (still in Location::TYPES const) and must still be accepted per the
    // acceptedTypeCodes() UNION documented on LocationImporter. The client's
    // file never uses 'shelf', so mutate the type cell of a real row.
    $row = loc_realRow('Cataloguing');
    $row['type'] = 'shelf';
    $row['repository_code'] = '';

    $import = loc_run([$row], loc_columnMap(), $u->id);

    expect(loc_failures($import))->toBe([]);
    expect(Location::where('name', 'Cataloguing')->value('type'))->toBe('shelf');
});

test('a custom ACTIVE location_types row not in Location::TYPES const is accepted', function () {
    $u = loc_admin();
    $this->actingAs($u);

    LocationType::create(['code' => 'vault', 'label' => 'Vault', 'sort_order' => 10, 'is_active' => true]);

    $row = loc_realRow('Conservation');
    $row['type'] = 'vault';
    $row['repository_code'] = '';

    $import = loc_run([$row], loc_columnMap(), $u->id);

    expect(loc_failures($import))->toBe([]);
    expect(Location::where('name', 'Conservation')->value('type'))->toBe('vault');
});

test('an INACTIVE custom location_types row (not in Location::TYPES) is REJECTED', function () {
    $u = loc_admin();
    $this->actingAs($u);

    LocationType::create(['code' => 'crate', 'label' => 'Crate', 'sort_order' => 11, 'is_active' => false]);

    $row = loc_realRow('Mould Room');
    $row['type'] = 'crate';
    $row['repository_code'] = '';

    $import = loc_run([$row], loc_columnMap(), $u->id);

    $failures = loc_failures($import);
    expect($failures)->toHaveCount(1);
    expect(Location::where('name', 'Mould Room')->exists())->toBeFalse();
});

test('a completely invalid type value is rejected with a clear error', function () {
    $u = loc_admin();
    $this->actingAs($u);

    $row = loc_realRow('Old Mould Room');
    $row['type'] = 'spaceship';
    $row['repository_code'] = '';

    $import = loc_run([$row], loc_columnMap(), $u->id);

    $failures = loc_failures($import);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->not->toContain('generic_validation')
        ->and(strtolower($failures[0]))->toContain('type');
    expect(Location::where('name', 'Old Mould Room')->exists())->toBeFalse();
});

// ─── blank repository_code => global (repository_id NULL) ───────────────────

test('blank repository_code makes the location global (repository_id NULL)', function () {
    $u = loc_admin();
    $this->actingAs($u);

    // Every root row in the client's file is scoped to "NRA" — blank it out
    // to exercise the global-location path.
    $row = loc_realRow('Conservation');
    $row['repository_code'] = '';

    $import = loc_run([$row], loc_columnMap(), $u->id);

    expect(loc_failures($import))->toBe([]);
    $loc = Location::where('name', 'Conservation')->first();
    expect($loc)->not->toBeNull()
        ->and($loc->repository_id)->toBeNull();
});

test('an unknown repository_code is rejected with a clear "not found" error', function () {
    $u = loc_admin();
    $this->actingAs($u);

    $row = loc_realRow('Cabinet');
    $row['repository_code'] = 'GHOST';

    $import = loc_run([$row], loc_columnMap(), $u->id);

    $failures = loc_failures($import);
    expect($failures)->toHaveCount(1)
        ->and(strtolower($failures[0]))->toContain('not found');
    expect(Location::where('name', 'Cabinet')->exists())->toBeFalse();
});

test('repository_code is resolved case-insensitively with surrounding whitespace', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    $row = loc_realRow('Mounted');
    $row['repository_code'] = '  nra  ';

    $import = loc_run([$row], loc_columnMap(), $u->id);

    expect(loc_failures($import))->toBe([]);
    expect(Location::where('name', 'Mounted')->value('repository_id'))->toBe($repo->id);
});

// ─── Auto-generated code when blank ──────────────────────────────────────────

test('a blank code column auto-generates a TYPE-REPO-N code', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    // Real row, entirely unmodified — every row in the client's file has a
    // blank code cell, exactly what this scenario needs.
    $import = loc_run([loc_realRow('Cataloguing')], loc_columnMap(), $u->id);

    expect(loc_failures($import))->toBe([]);
    $code = Location::where('name', 'Cataloguing')->value('code');
    expect($code)->not->toBeNull()
        ->and($code)->toStartWith('ROOM-' . $repo->id . '-');
});

test('a blank code column on a GLOBAL location auto-generates with the "0" repo suffix', function () {
    $u = loc_admin();
    $this->actingAs($u);

    $row = loc_realRow('Conservation');
    $row['repository_code'] = '';

    $import = loc_run([$row], loc_columnMap(), $u->id);

    expect(loc_failures($import))->toBe([]);
    $code = Location::where('name', 'Conservation')->value('code');
    expect($code)->toStartWith('ROOM-0-');
});

test('an explicitly supplied code is NOT overwritten by auto-generation', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    // The client's file never carries an explicit code — mutate just the
    // code cell of a real row to supply one.
    $row = loc_realRow('Mould Room');
    $row['code'] = 'MY-CODE-1';

    $import = loc_run([$row], loc_columnMap(), $u->id);

    expect(loc_failures($import))->toBe([]);
    expect(Location::where('name', 'Mould Room')->value('code'))->toBe('MY-CODE-1');
});

// ─── Parent resolution: order, missing parent, two-pass re-run ──────────────

test('a row referencing a parent that does not exist yet fails with a clear message', function () {
    $u = loc_admin();
    $this->actingAs($u);

    $row = loc_realRow('Old Mould Room');
    $row['parent_name'] = 'Nonexistent Room';
    $row['repository_code'] = '';

    $import = loc_run([$row], loc_columnMap(), $u->id);

    $failures = loc_failures($import);
    expect($failures)->toHaveCount(1)
        ->and(strtolower($failures[0]))->toContain('parent')
        ->and(strtolower($failures[0]))->toContain('not found');
    expect(Location::where('name', 'Old Mould Room')->exists())->toBeFalse();
});

test('parent-before-child in the same file resolves parent_id correctly', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    // The client's file is flat (every parent_name blank) — pair two real
    // rows and mutate only the child's parent_name to build a hierarchy.
    $childRow = loc_realRow('Conservation');
    $childRow['parent_name'] = 'Cataloguing';

    $import = loc_run(
        [loc_realRow('Cataloguing'), $childRow],
        loc_columnMap(),
        $u->id,
    );

    expect(loc_failures($import))->toBe([]);
    $room = Location::where('name', 'Cataloguing')->first();
    $child = Location::where('name', 'Conservation')->first();
    expect($child->parent_id)->toBe($room->id);
});

test('child-before-parent in the same file fails the child, but re-running (parent now present) resolves it', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    $childRow = loc_realRow('Old Mould Room');
    $childRow['parent_name'] = 'Mould Room';

    // Pass 1: child listed BEFORE its parent — Filament processes rows in
    // file order, so the child row fails.
    $import1 = loc_run(
        [$childRow, loc_realRow('Mould Room')],
        loc_columnMap(),
        $u->id,
    );

    expect(loc_failures($import1))->toHaveCount(1);
    expect(Location::where('name', 'Mould Room')->exists())->toBeTrue();
    expect(Location::where('name', 'Old Mould Room')->exists())->toBeFalse();

    // Pass 2: re-run just the previously-failed row — parent now exists.
    $import2 = loc_run([$childRow], loc_columnMap(), $u->id);

    expect(loc_failures($import2))->toBe([]);
    $room = Location::where('name', 'Mould Room')->first();
    $child = Location::where('name', 'Old Mould Room')->first();
    expect($child->parent_id)->toBe($room->id);
});

test('a repository-scoped child can adopt a GLOBAL parent', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    $globalParent = Location::create(['name' => 'Conservation Lab', 'type' => 'room', 'repository_id' => null, 'is_active' => true]);

    $row = loc_realRow('Cabinet');
    $row['parent_name'] = 'Conservation Lab';

    $import = loc_run([$row], loc_columnMap(), $u->id);

    expect(loc_failures($import))->toBe([]);
    $child = Location::where('name', 'Cabinet')->first();
    expect($child->parent_id)->toBe($globalParent->id)
        ->and($child->repository_id)->toBe($repo->id);
});

test('when the same name exists BOTH repo-scoped and global, the repo-scoped parent wins', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    // NOTE: Location::create(['repository_id' => null, ...]) while acting as
    // a super_admin with a default_repository_id gets its repository_id
    // silently overwritten by BelongsToRepository's creating hook (a SEPARATE
    // confirmed bug — see the xlsx "Conservation Lab" test). withoutEvents()
    // bypasses that hook so THIS fixture is genuinely global, isolating the
    // thing actually under test here: the parent-preference ORDER BY.
    $globalRoom = Location::withoutEvents(fn () => Location::create(['name' => 'Shared Name Room', 'type' => 'room', 'repository_id' => null, 'is_active' => true]));
    $scopedRoom = Location::create(['name' => 'Shared Name Room', 'type' => 'room', 'repository_id' => $repo->id, 'is_active' => true]);

    $row = loc_realRow('Mounted');
    $row['parent_name'] = 'Shared Name Room';

    $import = loc_run([$row], loc_columnMap(), $u->id);

    expect(loc_failures($import))->toBe([]);
    $child = Location::where('name', 'Mounted')->first();
    expect($child->parent_id)->toBe($scopedRoom->id)
        ->and($child->parent_id)->not->toBe($globalRoom->id);
});

// ─── Idempotency / composite-key matching, dirty DB ─────────────────────────

test('re-importing the same location updates it in place, no duplicate row', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    $row = loc_realRow('Cataloguing');
    $row['notes'] = 'first pass';

    loc_run([$row], loc_columnMap(), $u->id);
    expect(Location::where('name', 'Cataloguing')->count())->toBe(1);

    $row['notes'] = 'second pass — updated';
    $import2 = loc_run([$row], loc_columnMap(), $u->id);

    expect(loc_failures($import2))->toBe([]);
    expect(Location::where('name', 'Cataloguing')->count())->toBe(1)
        ->and(Location::where('name', 'Cataloguing')->value('notes'))->toBe('second pass — updated');
});

test('a soft-deleted location (same name/repo/parent) is RESTORED on re-import, not duplicated', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    Location::create(['name' => 'Mould Room', 'type' => 'room', 'repository_id' => $repo->id, 'is_active' => true])->delete();
    expect(Location::count())->toBe(0)
        ->and(Location::withTrashed()->count())->toBe(1);

    $import = loc_run([loc_realRow('Mould Room')], loc_columnMap(), $u->id);

    expect(loc_failures($import))->toBe([]);
    expect(Location::count())->toBe(1)
        ->and(Location::withTrashed()->count())->toBe(1);
});

test('two locations with the SAME name under DIFFERENT parents are not merged (composite key)', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    $roomA = Location::create(['name' => 'Room A', 'type' => 'room', 'repository_id' => $repo->id, 'is_active' => true]);
    $roomB = Location::create(['name' => 'Room B', 'type' => 'room', 'repository_id' => $repo->id, 'is_active' => true]);

    $rowA = loc_realRow('Cabinet');
    $rowA['parent_name'] = 'Room A';
    $rowB = loc_realRow('Cabinet');
    $rowB['parent_name'] = 'Room B';

    $import = loc_run([$rowA, $rowB], loc_columnMap(), $u->id);

    expect(loc_failures($import))->toBe([]);
    expect(Location::where('name', 'Cabinet')->count())->toBe(2);
    $underA = Location::where('name', 'Cabinet')->where('parent_id', $roomA->id)->first();
    $underB = Location::where('name', 'Cabinet')->where('parent_id', $roomB->id)->first();
    expect($underA)->not->toBeNull()
        ->and($underB)->not->toBeNull()
        ->and($underA->id)->not->toBe($underB->id);
});

test('skip_duplicates against an existing live location surfaces a clear "already exists" skip', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    Location::create(['name' => 'Cabinet', 'type' => 'museum', 'repository_id' => $repo->id, 'is_active' => true]);

    $import = loc_run(
        [loc_realRow('Cabinet')],
        loc_columnMap(),
        $u->id,
        ['skip_duplicates' => true],
    );

    $failures = loc_failures($import);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->not->toContain('generic_validation')
        ->and(strtolower($failures[0]))->toContain('skip');
});

// ─── Real production CSV: 29 root locations (Archive/Museum/Room) ──────────

test('the real production CSV (29 rows) imports cleanly through the streaming path', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    $rows = array_values(loc_realRows());

    $import = loc_run($rows, loc_columnMap(), $u->id);

    expect(loc_failures($import))->toBe([]);
    expect(Location::count())->toBe(count($rows));
    expect(Location::where('name', 'Archive 1')->value('type'))->toBe('repository')
        ->and(Location::where('name', 'Showcase 1')->value('type'))->toBe('museum')
        ->and(Location::where('name', 'Cataloguing')->value('type'))->toBe('room')
        ->and(Location::where('name', 'Old Mould Room')->value('repository_id'))->toBe($repo->id);
})->skip(fn (): bool => ! is_file(LOC_PROD_CSV), 'production sample CSV not present');

// ─── Real xlsx sample: "Data" sheet + repo-scoped and global rows ──────────

// KNOWN FAILURE — confirmed product bug, not a test mistake (see the returned
// bug report): BelongsToRepository::bootBelongsToRepository()'s `creating`
// hook (app/Models/Concerns/BelongsToRepository.php ~L47-53) overwrites an
// explicit repository_id=null with the acting super_admin/admin's
// default_repository_id whenever one is set. LocationImporter::afterFill()
// correctly leaves repository_id null for a blank repository_code (see the
// PASSING "blank repository_code makes the location global" test above,
// which uses an admin WITHOUT a default repository) — but the moment the
// importing admin has a default repository (the realistic day-to-day case),
// the xlsx template's own documented "leave repository blank" instruction
// for a shared location like "Conservation Lab" is silently defeated.
test('the real xlsx "Data" sheet imports both a repo-scoped and a global location', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    $reader = IOFactory::createReaderForFile(LOC_XLSX_SAMPLE);
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load(LOC_XLSX_SAMPLE);
    $sheet = $spreadsheet->getSheetByName('Data');
    $rawRows = array_values(array_filter(
        $sheet->toArray(null, true, false, false),
        fn (array $r): bool => array_filter($r, fn ($c) => $c !== null && $c !== '') !== [],
    ));

    $headers = array_map(fn ($h) => (string) $h, $rawRows[0]);
    $columnMap = array_combine($headers, $headers);

    $rows = [];
    foreach (array_slice($rawRows, 1) as $row) {
        $keyed = [];
        foreach ($headers as $i => $header) {
            $keyed[$header] = $row[$i] === null ? '' : (string) $row[$i];
        }
        $rows[] = $keyed;
    }

    $import = loc_run($rows, $columnMap, $u->id);

    expect(loc_failures($import))->toBe([]);
    expect(Location::count())->toBe(2);

    $archiveRoom = Location::where('name', 'Archive Room 1')->first();
    $lab = Location::where('name', 'Conservation Lab')->first();
    expect($archiveRoom->repository_id)->toBe($repo->id)
        ->and($archiveRoom->type)->toBe('room')
        ->and($archiveRoom->sort_order)->toBe(1)
        ->and($lab->repository_id)->toBeNull()
        ->and($lab->type)->toBe('room');
})->skip(fn (): bool => ! is_file(LOC_XLSX_SAMPLE), 'xlsx sample not present');

test('the "READ ME" sheet is never mistaken for the data sheet by name-based lookup', function () {
    $reader = IOFactory::createReaderForFile(LOC_XLSX_SAMPLE);
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load(LOC_XLSX_SAMPLE);

    expect($spreadsheet->getSheetNames())->toContain('Data')
        ->and($spreadsheet->getSheetNames())->toContain('READ ME');

    $readMe = $spreadsheet->getSheetByName('READ ME')->toArray(null, true, false, false);
    // The instructions sheet has no 'name'/'type' header row shape at all —
    // if it were ever fed to the importer as data it would fail loudly
    // rather than silently importing garbage rows.
    expect($readMe[0][0] ?? null)->not->toBe('name');
})->skip(fn (): bool => ! is_file(LOC_XLSX_SAMPLE), 'xlsx sample not present');

// ─── is_active default when blank ────────────────────────────────────────────

test('a blank is_active column defaults the new location to active', function () {
    $u = loc_admin();
    $this->actingAs($u);

    // Every row in the client's file has a blank is_active cell — real row,
    // repository_code only blanked because this test runs without a
    // Repository fixture.
    $row = loc_realRow('Archive 5');
    $row['repository_code'] = '';

    $import = loc_run([$row], loc_columnMap(), $u->id);

    expect(loc_failures($import))->toBe([]);
    expect(Location::where('name', 'Archive 5')->value('is_active'))->toBeTrue();
});

// ─── Numeric Excel cells (sort_order arriving as int) ────────────────────────

// LEFT SYNTHETIC — verified there is no real-file source for this exact
// scenario: the prod CSV's sort_order cells are always blank strings, and
// even the real xlsx sample's sort_order column stores its "1"/"2" values as
// inlineStr cells (confirmed via PhpSpreadsheet's getCell()->getDataType()),
// not native numeric cells — so no file in nra/ can produce the raw PHP int
// state this test exercises (Filament's numeric() cast on an actual int,
// vs. a numeric string). See "left_synthetic" note.
test('a numeric sort_order cell (native int from Excel) imports cleanly', function () {
    $u = loc_admin();
    $this->actingAs($u);

    $import = loc_run(
        [['name' => 'Numeric Sort Room', 'type' => 'room', 'parent_name' => '', 'repository_code' => '', 'code' => '', 'notes' => '', 'sort_order' => 5, 'is_active' => '']],
        loc_columnMap(),
        $u->id,
    );

    expect(loc_failures($import))->toBe([]);
    expect(Location::where('name', 'Numeric Sort Room')->value('sort_order'))->toBe(5);
});

test('REGRESSION (bug D): importing a row that UPDATES a pre-existing code-less location assigns it a code', function () {
    // Mirrors production: seeded default locations ("Archive 1", "Cataloguing")
    // existed with a NULL code; the import matches them by name and used to
    // leave them without an identifier because auto-code only fired on create.
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    $seeded = Location::withoutGlobalScopes()->create([
        'name' => 'Cataloguing', 'type' => 'room', 'repository_id' => $repo->id, 'code' => null,
    ]);
    // A pre-existing default really can lack a code (it was created before the
    // save-hook backfill); force it back to null to reproduce that exact state.
    Location::withoutGlobalScopes()->whereKey($seeded->id)->update(['code' => null]);
    expect(Location::withoutGlobalScopes()->whereKey($seeded->id)->value('code'))->toBeNull();

    $row = loc_realRow('Cataloguing');
    $row['notes'] = 'updated';

    $import = loc_run([$row], loc_columnMap(), $u->id);

    expect(loc_failures($import))->toBe([]);
    // The same row was UPDATED (not duplicated) AND now carries an auto code.
    expect(Location::withoutGlobalScopes()->where('name', 'Cataloguing')->count())->toBe(1);
    expect(Location::withoutGlobalScopes()->where('name', 'Cataloguing')->value('code'))->not->toBeNull();
});
