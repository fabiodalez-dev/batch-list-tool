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

// ─── Phantom-attribute INSERT bug (parent_name / repository_code) ───────────

test('a row that maps repository_code does not blow up the INSERT with a phantom column', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    $import = loc_run(
        [['name' => 'Archive 1', 'type' => 'Repository', 'parent_name' => '', 'repository_code' => 'NRA', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => '']],
        loc_columnMap(),
        $u->id,
    );

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

    $import = loc_run(
        [['name' => 'Shelf A', 'type' => 'shelf', 'parent_name' => 'Cataloguing', 'repository_code' => 'NRA', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => '']],
        loc_columnMap(),
        $u->id,
    );

    expect(loc_failures($import))->toBe([]);
    $loc = Location::where('name', 'Shelf A')->first();
    expect($loc)->not->toBeNull()
        ->and($loc->parent_id)->toBe($parent->id)
        ->and($loc->repository_id)->toBe($repo->id);
});

// ─── Type validation: aliases, canonical, legacy, custom lookup rows ────────

test('a legacy alias type ("archive") is normalised to the canonical "repository"', function () {
    $u = loc_admin();
    $this->actingAs($u);

    $import = loc_run(
        [['name' => 'Big Archive', 'type' => 'archive', 'parent_name' => '', 'repository_code' => '', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => '']],
        loc_columnMap(),
        $u->id,
    );

    expect(loc_failures($import))->toBe([]);
    expect(Location::where('name', 'Big Archive')->value('type'))->toBe('repository');
});

test('a legacy alias type ("vetrina") is normalised to the canonical "showcase"', function () {
    $u = loc_admin();
    $this->actingAs($u);

    $import = loc_run(
        [['name' => 'Vetrina 1', 'type' => 'vetrina', 'parent_name' => '', 'repository_code' => '', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => '']],
        loc_columnMap(),
        $u->id,
    );

    expect(loc_failures($import))->toBe([]);
    expect(Location::where('name', 'Vetrina 1')->value('type'))->toBe('showcase');
});

test('uppercase type value from the real CSV ("Repository") is accepted case-insensitively', function () {
    $u = loc_admin();
    $this->actingAs($u);

    $import = loc_run(
        [['name' => 'Archive 99', 'type' => 'Repository', 'parent_name' => '', 'repository_code' => '', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => '']],
        loc_columnMap(),
        $u->id,
    );

    expect(loc_failures($import))->toBe([]);
    expect(Location::where('name', 'Archive 99')->value('type'))->toBe('repository');
});

test('a legacy type ("shelf") not in the canonical lookup still imports via the Location::TYPES union', function () {
    $u = loc_admin();
    $this->actingAs($u);

    // location_types seeds only room/museum/repository. 'shelf' is legacy
    // (still in Location::TYPES const) and must still be accepted per the
    // acceptedTypeCodes() UNION documented on LocationImporter.
    $import = loc_run(
        [['name' => 'Old Shelf', 'type' => 'shelf', 'parent_name' => '', 'repository_code' => '', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => '']],
        loc_columnMap(),
        $u->id,
    );

    expect(loc_failures($import))->toBe([]);
    expect(Location::where('name', 'Old Shelf')->value('type'))->toBe('shelf');
});

test('a custom ACTIVE location_types row not in Location::TYPES const is accepted', function () {
    $u = loc_admin();
    $this->actingAs($u);

    LocationType::create(['code' => 'vault', 'label' => 'Vault', 'sort_order' => 10, 'is_active' => true]);

    $import = loc_run(
        [['name' => 'Vault 1', 'type' => 'vault', 'parent_name' => '', 'repository_code' => '', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => '']],
        loc_columnMap(),
        $u->id,
    );

    expect(loc_failures($import))->toBe([]);
    expect(Location::where('name', 'Vault 1')->value('type'))->toBe('vault');
});

test('an INACTIVE custom location_types row (not in Location::TYPES) is REJECTED', function () {
    $u = loc_admin();
    $this->actingAs($u);

    LocationType::create(['code' => 'crate', 'label' => 'Crate', 'sort_order' => 11, 'is_active' => false]);

    $import = loc_run(
        [['name' => 'Crate 1', 'type' => 'crate', 'parent_name' => '', 'repository_code' => '', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => '']],
        loc_columnMap(),
        $u->id,
    );

    $failures = loc_failures($import);
    expect($failures)->toHaveCount(1);
    expect(Location::where('name', 'Crate 1')->exists())->toBeFalse();
});

test('a completely invalid type value is rejected with a clear error', function () {
    $u = loc_admin();
    $this->actingAs($u);

    $import = loc_run(
        [['name' => 'Mystery', 'type' => 'spaceship', 'parent_name' => '', 'repository_code' => '', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => '']],
        loc_columnMap(),
        $u->id,
    );

    $failures = loc_failures($import);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->not->toContain('generic_validation')
        ->and(strtolower($failures[0]))->toContain('type');
    expect(Location::where('name', 'Mystery')->exists())->toBeFalse();
});

// ─── blank repository_code => global (repository_id NULL) ───────────────────

test('blank repository_code makes the location global (repository_id NULL)', function () {
    $u = loc_admin();
    $this->actingAs($u);

    $import = loc_run(
        [['name' => 'Conservation Lab', 'type' => 'room', 'parent_name' => '', 'repository_code' => '', 'code' => '', 'notes' => 'shared', 'sort_order' => '', 'is_active' => '']],
        loc_columnMap(),
        $u->id,
    );

    expect(loc_failures($import))->toBe([]);
    $loc = Location::where('name', 'Conservation Lab')->first();
    expect($loc)->not->toBeNull()
        ->and($loc->repository_id)->toBeNull();
});

test('an unknown repository_code is rejected with a clear "not found" error', function () {
    $u = loc_admin();
    $this->actingAs($u);

    $import = loc_run(
        [['name' => 'Somewhere', 'type' => 'room', 'parent_name' => '', 'repository_code' => 'GHOST', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => '']],
        loc_columnMap(),
        $u->id,
    );

    $failures = loc_failures($import);
    expect($failures)->toHaveCount(1)
        ->and(strtolower($failures[0]))->toContain('not found');
    expect(Location::where('name', 'Somewhere')->exists())->toBeFalse();
});

test('repository_code is resolved case-insensitively with surrounding whitespace', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    $import = loc_run(
        [['name' => 'Messy Repo Code', 'type' => 'room', 'parent_name' => '', 'repository_code' => '  nra  ', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => '']],
        loc_columnMap(),
        $u->id,
    );

    expect(loc_failures($import))->toBe([]);
    expect(Location::where('name', 'Messy Repo Code')->value('repository_id'))->toBe($repo->id);
});

// ─── Auto-generated code when blank ──────────────────────────────────────────

test('a blank code column auto-generates a TYPE-REPO-N code', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    $import = loc_run(
        [['name' => 'Auto Room', 'type' => 'room', 'parent_name' => '', 'repository_code' => 'NRA', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => '']],
        loc_columnMap(),
        $u->id,
    );

    expect(loc_failures($import))->toBe([]);
    $code = Location::where('name', 'Auto Room')->value('code');
    expect($code)->not->toBeNull()
        ->and($code)->toStartWith('ROOM-' . $repo->id . '-');
});

test('a blank code column on a GLOBAL location auto-generates with the "0" repo suffix', function () {
    $u = loc_admin();
    $this->actingAs($u);

    $import = loc_run(
        [['name' => 'Global Room', 'type' => 'room', 'parent_name' => '', 'repository_code' => '', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => '']],
        loc_columnMap(),
        $u->id,
    );

    expect(loc_failures($import))->toBe([]);
    $code = Location::where('name', 'Global Room')->value('code');
    expect($code)->toStartWith('ROOM-0-');
});

test('an explicitly supplied code is NOT overwritten by auto-generation', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    $import = loc_run(
        [['name' => 'Explicit Code Room', 'type' => 'room', 'parent_name' => '', 'repository_code' => 'NRA', 'code' => 'MY-CODE-1', 'notes' => '', 'sort_order' => '', 'is_active' => '']],
        loc_columnMap(),
        $u->id,
    );

    expect(loc_failures($import))->toBe([]);
    expect(Location::where('name', 'Explicit Code Room')->value('code'))->toBe('MY-CODE-1');
});

// ─── Parent resolution: order, missing parent, two-pass re-run ──────────────

test('a row referencing a parent that does not exist yet fails with a clear message', function () {
    $u = loc_admin();
    $this->actingAs($u);

    $import = loc_run(
        [['name' => 'Orphan Shelf', 'type' => 'shelf', 'parent_name' => 'Nonexistent Room', 'repository_code' => '', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => '']],
        loc_columnMap(),
        $u->id,
    );

    $failures = loc_failures($import);
    expect($failures)->toHaveCount(1)
        ->and(strtolower($failures[0]))->toContain('parent')
        ->and(strtolower($failures[0]))->toContain('not found');
    expect(Location::where('name', 'Orphan Shelf')->exists())->toBeFalse();
});

test('parent-before-child in the same file resolves parent_id correctly', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    $import = loc_run(
        [
            ['name' => 'Room 3', 'type' => 'room', 'parent_name' => '', 'repository_code' => 'NRA', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => ''],
            ['name' => 'Shelf B', 'type' => 'shelf', 'parent_name' => 'Room 3', 'repository_code' => 'NRA', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => ''],
        ],
        loc_columnMap(),
        $u->id,
    );

    expect(loc_failures($import))->toBe([]);
    $room = Location::where('name', 'Room 3')->first();
    $shelf = Location::where('name', 'Shelf B')->first();
    expect($shelf->parent_id)->toBe($room->id);
});

test('child-before-parent in the same file fails the child, but re-running (parent now present) resolves it', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    // Pass 1: child listed BEFORE its parent — Filament processes rows in
    // file order, so the child row fails.
    $import1 = loc_run(
        [
            ['name' => 'Shelf C', 'type' => 'shelf', 'parent_name' => 'Room 4', 'repository_code' => 'NRA', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => ''],
            ['name' => 'Room 4', 'type' => 'room', 'parent_name' => '', 'repository_code' => 'NRA', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => ''],
        ],
        loc_columnMap(),
        $u->id,
    );

    expect(loc_failures($import1))->toHaveCount(1);
    expect(Location::where('name', 'Room 4')->exists())->toBeTrue();
    expect(Location::where('name', 'Shelf C')->exists())->toBeFalse();

    // Pass 2: re-run just the previously-failed row — parent now exists.
    $import2 = loc_run(
        [['name' => 'Shelf C', 'type' => 'shelf', 'parent_name' => 'Room 4', 'repository_code' => 'NRA', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => '']],
        loc_columnMap(),
        $u->id,
    );

    expect(loc_failures($import2))->toBe([]);
    $room = Location::where('name', 'Room 4')->first();
    $shelf = Location::where('name', 'Shelf C')->first();
    expect($shelf->parent_id)->toBe($room->id);
});

test('a repository-scoped child can adopt a GLOBAL parent', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    $globalParent = Location::create(['name' => 'Conservation Lab', 'type' => 'room', 'repository_id' => null, 'is_active' => true]);

    $import = loc_run(
        [['name' => 'Bench 1', 'type' => 'shelf', 'parent_name' => 'Conservation Lab', 'repository_code' => 'NRA', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => '']],
        loc_columnMap(),
        $u->id,
    );

    expect(loc_failures($import))->toBe([]);
    $bench = Location::where('name', 'Bench 1')->first();
    expect($bench->parent_id)->toBe($globalParent->id)
        ->and($bench->repository_id)->toBe($repo->id);
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

    $import = loc_run(
        [['name' => 'Child Of Scoped', 'type' => 'shelf', 'parent_name' => 'Shared Name Room', 'repository_code' => 'NRA', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => '']],
        loc_columnMap(),
        $u->id,
    );

    expect(loc_failures($import))->toBe([]);
    $child = Location::where('name', 'Child Of Scoped')->first();
    expect($child->parent_id)->toBe($scopedRoom->id)
        ->and($child->parent_id)->not->toBe($globalRoom->id);
});

// ─── Idempotency / composite-key matching, dirty DB ─────────────────────────

test('re-importing the same location updates it in place, no duplicate row', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    $rows = [['name' => 'Cataloguing', 'type' => 'room', 'parent_name' => '', 'repository_code' => 'NRA', 'code' => '', 'notes' => 'first pass', 'sort_order' => '', 'is_active' => '']];

    loc_run($rows, loc_columnMap(), $u->id);
    expect(Location::where('name', 'Cataloguing')->count())->toBe(1);

    $rows[0]['notes'] = 'second pass — updated';
    $import2 = loc_run($rows, loc_columnMap(), $u->id);

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

    $import = loc_run(
        [['name' => 'Mould Room', 'type' => 'room', 'parent_name' => '', 'repository_code' => 'NRA', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => '']],
        loc_columnMap(),
        $u->id,
    );

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

    $import = loc_run(
        [
            ['name' => 'Shelf 1', 'type' => 'shelf', 'parent_name' => 'Room A', 'repository_code' => 'NRA', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => ''],
            ['name' => 'Shelf 1', 'type' => 'shelf', 'parent_name' => 'Room B', 'repository_code' => 'NRA', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => ''],
        ],
        loc_columnMap(),
        $u->id,
    );

    expect(loc_failures($import))->toBe([]);
    expect(Location::where('name', 'Shelf 1')->count())->toBe(2);
    $underA = Location::where('name', 'Shelf 1')->where('parent_id', $roomA->id)->first();
    $underB = Location::where('name', 'Shelf 1')->where('parent_id', $roomB->id)->first();
    expect($underA)->not->toBeNull()
        ->and($underB)->not->toBeNull()
        ->and($underA->id)->not->toBe($underB->id);
});

test('skip_duplicates against an existing live location surfaces a clear "already exists" skip', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = loc_admin($repo->id);
    $this->actingAs($u);

    Location::create(['name' => 'Already Here', 'type' => 'room', 'repository_id' => $repo->id, 'is_active' => true]);

    $import = loc_run(
        [['name' => 'Already Here', 'type' => 'room', 'parent_name' => '', 'repository_code' => 'NRA', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => '']],
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

    $csv = array_map('str_getcsv', file(LOC_PROD_CSV));
    $headers = array_map(fn ($h) => trim((string) $h), $csv[0]);
    $dataRows = array_slice($csv, 1);

    $rows = [];
    foreach ($dataRows as $row) {
        $keyed = [];
        foreach ($headers as $i => $header) {
            $keyed[$header] = $row[$i] ?? '';
        }
        $rows[] = $keyed;
    }

    $columnMap = array_combine($headers, $headers);

    $import = loc_run($rows, $columnMap, $u->id);

    expect(loc_failures($import))->toBe([]);
    expect(Location::count())->toBe(count($dataRows));
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
        fn (array $r): bool => count(array_filter($r, fn ($c) => $c !== null && $c !== '')) > 0,
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

    $import = loc_run(
        [['name' => 'Default Active Room', 'type' => 'room', 'parent_name' => '', 'repository_code' => '', 'code' => '', 'notes' => '', 'sort_order' => '', 'is_active' => '']],
        loc_columnMap(),
        $u->id,
    );

    expect(loc_failures($import))->toBe([]);
    expect(Location::where('name', 'Default Active Room')->value('is_active'))->toBeTrue();
});

// ─── Numeric Excel cells (sort_order arriving as int) ────────────────────────

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
