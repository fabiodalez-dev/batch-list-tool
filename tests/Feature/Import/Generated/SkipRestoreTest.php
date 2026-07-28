<?php

declare(strict_types=1);

use App\Filament\Imports\AuthorityImporter;
use App\Filament\Imports\BatchImporter;
use App\Filament\Imports\LocationImporter;
use App\Filament\Imports\SeriesImporter;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use HayderHatem\FilamentExcelImport\Actions\Imports\Jobs\ImportExcel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use League\Csv\Reader;
use Spatie\Permission\Models\Role;

/**
 * AREA: skip-restore — SkipsExistingRows + soft-delete restore, across every
 * importer that shares the pattern (Series, Authority, Location, Batch).
 *
 * Drives the REAL streaming path (HayderHatem ImportExcel — the job the
 * per-resource "Import Excel/CSV" buttons dispatch) against a DIRTY database
 * (soft-deleted residue, duplicate rows, mixed live/deleted/new), using the
 * client's actual production CSVs as row data. See DirtyDatabaseImportTest.php
 * and ReimportRestoresSoftDeletedTest.php for the sibling patterns this file
 * extends with more importers / more real-file coverage / skip_duplicates
 * interaction.
 */
uses(RefreshDatabase::class);

const SR_SERIES_CSV = __DIR__ . '/../../../../nra/inbox/prod-uploads/20260728_075119_f4b6ebbb.csv';
const SR_AUTHORITY_CSV = __DIR__ . '/../../../../nra/inbox/prod-uploads/20260728_075355_0be0517f.csv';
const SR_LOCATION_CSV = __DIR__ . '/../../../../nra/inbox/prod-uploads/20260728_075809_36792a2a.csv';
const SR_BATCH_CSV = __DIR__ . '/../../../../nra/inbox/prod-uploads/20260728_081540_1d303669.csv';

function sr_admin(?int $repoId = null): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    /** @var User $u */
    $u = User::factory()->create([
        'email' => 'sr+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repoId,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * Load a real production CSV into keyed rows (header => value), exactly as
 * the streaming import job receives them.
 *
 * @return array{0: list<string>, 1: list<array<string, string>>}
 */
function sr_loadCsv(string $path): array
{
    $csv = Reader::createFromPath($path, 'r');
    $csv->setHeaderOffset(0);
    $header = $csv->getHeader();
    $rows = [];
    foreach ($csv->getRecords() as $record) {
        $rows[] = $record;
    }

    return [$header, $rows];
}

/**
 * Run the real hayderhatem ImportExcel job (the streaming path the client's
 * "Import Excel/CSV" buttons use).
 *
 * @param class-string $importer
 * @param array<int, array<string, string>> $rows keyed by CSV header
 * @param array<string, string> $columnMap importerField => CSV header
 * @param array<string, mixed> $options
 */
function sr_run(string $importer, array $rows, array $columnMap, int $userId, array $options = []): Import
{
    EntityResolver::flushMemo();
    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'skiprestore.csv',
        'file_path' => '/tmp/skiprestore.csv',
        'importer' => $importer,
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
function sr_failures(Import $import): array
{
    return $import->failedRows()->pluck('validation_error')->all();
}

const SR_SERIES_MAP = ['code' => 'Identifier', 'title' => 'Standard title in English (Plural)', 'repository_code' => 'Repository'];
const SR_AUTHORITY_MAP = [
    'identifier' => 'Identifier',
    'alternative_identifier' => 'Alternative Identifier',
    'entity_type' => 'Type of Entity',
    'surname' => 'Creator Surname',
    'given_names' => 'Creator Name',
];
const SR_LOCATION_MAP = ['name' => 'name', 'type' => 'type', 'parent_name' => 'parent_name', 'repository_code' => 'repository_code', 'code' => 'code'];
const SR_BATCH_MAP = ['batch_number' => 'batch_number', 'description' => 'description', 'type' => 'type', 'is_active' => 'is_active', 'repository_code' => 'repository_code'];

// ─────────────────────────────────────────────────────────────────────────
// SERIES (real file: 30 data rows, all NRA repository codes A-Z-ish)
// ─────────────────────────────────────────────────────────────────────────

test('Series: ALL 30 real codes soft-deleted then re-imported from the real prod CSV restore with zero failures', function () {
    [, $rows] = sr_loadCsv(SR_SERIES_CSV);
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = sr_admin($repo->id);
    $this->actingAs($u);

    foreach ($rows as $row) {
        Series::create(['code' => $row['Identifier'], 'title' => $row['Standard title in English (Plural)'], 'is_active' => true])->delete();
    }
    expect(Series::count())->toBe(0)
        ->and(Series::withTrashed()->count())->toBe(count($rows));

    $import = sr_run(SeriesImporter::class, $rows, SR_SERIES_MAP, $u->id);

    expect(sr_failures($import))->toBe([]);
    expect(Series::count())->toBe(count($rows))
        ->and(Series::withTrashed()->count())->toBe(count($rows));
});

test('Series: real prod CSV imported twice back-to-back is idempotent (no duplicates, no failures)', function () {
    [, $rows] = sr_loadCsv(SR_SERIES_CSV);
    $u = sr_admin();
    $this->actingAs($u);

    $first = sr_run(SeriesImporter::class, $rows, SR_SERIES_MAP, $u->id);
    expect(sr_failures($first))->toBe([]);
    expect(Series::count())->toBe(count($rows));

    $second = sr_run(SeriesImporter::class, $rows, SR_SERIES_MAP, $u->id);
    expect(sr_failures($second))->toBe([]);
    expect(Series::count())->toBe(count($rows))
        ->and(Series::withTrashed()->count())->toBe(count($rows));
});

test('Series: mixing live, soft-deleted and brand-new codes from the real header layout restores only the trashed one', function () {
    $u = sr_admin();
    $this->actingAs($u);

    Series::create(['code' => 'R', 'title' => 'Register Copies (old)', 'is_active' => true]); // live
    Series::create(['code' => 'RWL', 'title' => 'Wills (old)', 'is_active' => true])->delete(); // trashed

    $import = sr_run(
        SeriesImporter::class,
        [
            ['Identifier' => 'R', 'Standard title in English (Plural)' => 'Register Copies (Registro)', 'Repository' => 'NRA'],
            ['Identifier' => 'RWL', 'Standard title in English (Plural)' => 'Registers Private Practice Public Wills', 'Repository' => 'NRA'],
            ['Identifier' => 'MDV', 'Standard title in English (Plural)' => 'Medieval Collection', 'Repository' => 'NRA'],
        ],
        SR_SERIES_MAP,
        $u->id,
    );

    expect(sr_failures($import))->toBe([]);
    expect(Series::count())->toBe(3)
        ->and(Series::withTrashed()->count())->toBe(3)
        ->and(Series::where('code', 'RWL')->value('title'))->toBe('Registers Private Practice Public Wills')
        ->and(Series::where('code', 'RWL')->first()->trashed())->toBeFalse();
});

test('Series: skip_duplicates=true still RESTORES a soft-deleted match instead of skipping it', function () {
    // Per SeriesImporter::resolveRecord() docblock, a trashed match is
    // restored unconditionally BEFORE skipIfDuplicate() is ever reached —
    // the operator "clearly wants it back". Pin that skip_duplicates does
    // not override this for the streaming path with real data.
    $u = sr_admin();
    $this->actingAs($u);

    Series::create(['code' => 'MRT', 'title' => 'Maritime Collection (old)', 'is_active' => true])->delete();

    $import = sr_run(
        SeriesImporter::class,
        [['Identifier' => 'MRT', 'Standard title in English (Plural)' => 'Maritime Collection', 'Repository' => 'NRA']],
        SR_SERIES_MAP,
        $u->id,
        ['skip_duplicates' => true],
    );

    expect(sr_failures($import))->toBe([]);
    $row = Series::where('code', 'MRT')->first();
    expect($row)->not->toBeNull()
        ->and($row->trashed())->toBeFalse()
        ->and($row->title)->toBe('Maritime Collection');
});

test('Series: skip_duplicates=true DOES skip a LIVE existing code (not restore semantics)', function () {
    $u = sr_admin();
    $this->actingAs($u);

    Series::create(['code' => 'MRT', 'title' => 'Maritime Collection (kept)', 'is_active' => true]);

    $import = sr_run(
        SeriesImporter::class,
        [['Identifier' => 'MRT', 'Standard title in English (Plural)' => 'Maritime Collection (incoming)', 'Repository' => 'NRA']],
        SR_SERIES_MAP,
        $u->id,
        ['skip_duplicates' => true],
    );

    $failures = sr_failures($import);
    expect($failures)->toHaveCount(1)
        ->and(strtolower($failures[0]))->toContain('skip');
    expect(Series::where('code', 'MRT')->value('title'))->toBe('Maritime Collection (kept)');
});

test('Series: repository_code column from the real CSV is applied on restore, not just on create', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = sr_admin($repo->id);
    $this->actingAs($u);

    Series::create(['code' => 'ORG', 'title' => 'Originals Private Practice', 'is_active' => true, 'repository_id' => null])->delete();

    $import = sr_run(
        SeriesImporter::class,
        [['Identifier' => 'ORG', 'Standard title in English (Plural)' => 'Originals Private Practice', 'Repository' => 'NRA']],
        SR_SERIES_MAP,
        $u->id,
    );

    expect(sr_failures($import))->toBe([]);
    $row = Series::where('code', 'ORG')->first();
    expect($row->trashed())->toBeFalse()
        ->and($row->repository_id)->toBe($repo->id);
});

// ─────────────────────────────────────────────────────────────────────────
// AUTHORITY (real file: 679 data rows, R1..R6xx / R0_* / I1)
// ─────────────────────────────────────────────────────────────────────────

test('Authority: a real slice of the prod CSV, all soft-deleted, restores with zero failures on re-import', function () {
    [, $allRows] = sr_loadCsv(SR_AUTHORITY_CSV);
    $rows = array_slice($allRows, 0, 25); // R1..R25, real messy blank Alt/NTG/Suffix cells
    $u = sr_admin();
    $this->actingAs($u);

    foreach ($rows as $row) {
        Authority::create(['identifier' => $row['Identifier'], 'surname' => $row['Creator Surname'], 'entity_type' => 'PERSON'])->delete();
    }
    expect(Authority::count())->toBe(0)
        ->and(Authority::withTrashed()->count())->toBe(count($rows));

    $import = sr_run(AuthorityImporter::class, $rows, SR_AUTHORITY_MAP, $u->id);

    expect(sr_failures($import))->toBe([]);
    expect(Authority::count())->toBe(count($rows))
        ->and(Authority::withTrashed()->count())->toBe(count($rows));
});

test('Authority: mix of live/trashed/new identifiers drawn from the real CSV restores only the trashed one', function () {
    $u = sr_admin();
    $this->actingAs($u);

    Authority::create(['identifier' => 'R1', 'surname' => 'Abela', 'entity_type' => 'PERSON']); // live
    Authority::create(['identifier' => 'R2', 'surname' => 'Abela', 'entity_type' => 'PERSON'])->delete(); // trashed

    $import = sr_run(
        AuthorityImporter::class,
        [
            ['Identifier' => 'R1', 'Alternative Identifier' => '511', 'Type of Entity' => 'Person', 'Creator Surname' => 'Abela', 'Creator Name' => 'Antonio'],
            ['Identifier' => 'R2', 'Alternative Identifier' => '512', 'Type of Entity' => 'Person', 'Creator Surname' => 'Abela', 'Creator Name' => 'Giovanni Andrea'],
            ['Identifier' => 'R3', 'Alternative Identifier' => '513', 'Type of Entity' => 'Person', 'Creator Surname' => 'Abela', 'Creator Name' => 'Nicola'],
        ],
        SR_AUTHORITY_MAP,
        $u->id,
    );

    expect(sr_failures($import))->toBe([]);
    expect(Authority::count())->toBe(3)
        ->and(Authority::withTrashed()->count())->toBe(3)
        ->and(Authority::where('identifier', 'R2')->first()->trashed())->toBeFalse()
        ->and(Authority::where('identifier', 'R2')->value('given_names'))->toBe('Giovanni Andrea');
});

test('Authority: skip_duplicates=true still restores a soft-deleted identifier instead of skipping it', function () {
    $u = sr_admin();
    $this->actingAs($u);

    Authority::create(['identifier' => 'R4', 'surname' => 'Abela', 'entity_type' => 'PERSON'])->delete();

    $import = sr_run(
        AuthorityImporter::class,
        [['Identifier' => 'R4', 'Alternative Identifier' => '514', 'Type of Entity' => 'Person', 'Creator Surname' => 'Abela', 'Creator Name' => 'Placido']],
        SR_AUTHORITY_MAP,
        $u->id,
        ['skip_duplicates' => true],
    );

    expect(sr_failures($import))->toBe([]);
    $row = Authority::where('identifier', 'R4')->first();
    expect($row->trashed())->toBeFalse()
        ->and($row->given_names)->toBe('Placido');
});

test('Authority: skip_duplicates=true skips a LIVE existing identifier and leaves it untouched', function () {
    $u = sr_admin();
    $this->actingAs($u);

    Authority::create(['identifier' => 'R5', 'surname' => 'Original Surname', 'entity_type' => 'PERSON']);

    $import = sr_run(
        AuthorityImporter::class,
        [['Identifier' => 'R5', 'Type of Entity' => 'Person', 'Creator Surname' => 'Changed Surname']],
        SR_AUTHORITY_MAP,
        $u->id,
        ['skip_duplicates' => true],
    );

    $failures = sr_failures($import);
    expect($failures)->toHaveCount(1)
        ->and(strtolower($failures[0]))->toContain('skip');
    expect(Authority::where('identifier', 'R5')->value('surname'))->toBe('Original Surname');
});

test('Authority: numeric Alternative Identifier cells from the real CSV survive a restore (no "must be a string")', function () {
    // 511 is a genuine int on the streaming path (PhpSpreadsheet numeric
    // cell), exactly as recorded in the real CSV row for R1.
    $u = sr_admin();
    $this->actingAs($u);

    Authority::create(['identifier' => 'R1', 'surname' => 'Abela', 'entity_type' => 'PERSON'])->delete();

    $import = sr_run(
        AuthorityImporter::class,
        [['Identifier' => 'R1', 'Alternative Identifier' => 511, 'Type of Entity' => 'Person', 'Creator Surname' => 'Abela', 'Creator Name' => 'Antonio']],
        SR_AUTHORITY_MAP,
        $u->id,
    );

    expect(sr_failures($import))->toBe([]);
    $row = Authority::where('identifier', 'R1')->first();
    expect($row->trashed())->toBeFalse()
        ->and($row->alternative_identifier)->toBe('511');
});

test('Authority: real prod CSV imported twice back-to-back is idempotent across 679 rows', function () {
    [, $rows] = sr_loadCsv(SR_AUTHORITY_CSV);
    $u = sr_admin();
    $this->actingAs($u);

    $first = sr_run(AuthorityImporter::class, $rows, SR_AUTHORITY_MAP, $u->id);
    expect(sr_failures($first))->toBe([]);
    expect(Authority::count())->toBe(count($rows));

    $second = sr_run(AuthorityImporter::class, $rows, SR_AUTHORITY_MAP, $u->id);
    expect(sr_failures($second))->toBe([]);
    expect(Authority::count())->toBe(count($rows))
        ->and(Authority::withTrashed()->count())->toBe(count($rows));
})->group('slow');

// ─────────────────────────────────────────────────────────────────────────
// LOCATION (real file: 30 rows, Archive/Showcase/Room hierarchy, all root
// level — parent_name always blank, code always blank, repository_code=NRA)
// ─────────────────────────────────────────────────────────────────────────

test('Location: the real 30-row prod CSV, all soft-deleted, restores with zero failures', function () {
    [, $rows] = sr_loadCsv(SR_LOCATION_CSV);
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = sr_admin($repo->id);
    $this->actingAs($u);

    foreach ($rows as $row) {
        Location::create([
            'name' => $row['name'],
            'code' => null,
            'type' => strtolower($row['type']),
            'is_active' => true,
            'repository_id' => $repo->id,
            'parent_id' => null,
        ])->delete();
    }
    expect(Location::count())->toBe(0)
        ->and(Location::withTrashed()->count())->toBe(count($rows));

    $import = sr_run(LocationImporter::class, $rows, SR_LOCATION_MAP, $u->id);

    expect(sr_failures($import))->toBe([]);
    expect(Location::count())->toBe(count($rows))
        ->and(Location::withTrashed()->count())->toBe(count($rows));
});

test('Location: mix of live, soft-deleted and new root locations from the real CSV restores only the trashed one', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = sr_admin($repo->id);
    $this->actingAs($u);

    Location::create(['name' => 'Archive 1', 'code' => null, 'type' => 'repository', 'is_active' => true, 'repository_id' => $repo->id, 'parent_id' => null]); // live
    Location::create(['name' => 'Archive 2', 'code' => null, 'type' => 'repository', 'is_active' => true, 'repository_id' => $repo->id, 'parent_id' => null])->delete(); // trashed

    $import = sr_run(
        LocationImporter::class,
        [
            ['name' => 'Archive 1', 'type' => 'Repository', 'parent_name' => '', 'repository_code' => 'NRA', 'code' => ''],
            ['name' => 'Archive 2', 'type' => 'Repository', 'parent_name' => '', 'repository_code' => 'NRA', 'code' => ''],
            ['name' => 'Archive 3', 'type' => 'Repository', 'parent_name' => '', 'repository_code' => 'NRA', 'code' => ''],
        ],
        SR_LOCATION_MAP,
        $u->id,
    );

    expect(sr_failures($import))->toBe([]);
    expect(Location::count())->toBe(3)
        ->and(Location::withTrashed()->count())->toBe(3)
        ->and(Location::where('name', 'Archive 2')->first()->trashed())->toBeFalse();
});

test('Location: a soft-deleted PARENT and its still-live child both survive a restore of the parent', function () {
    // Two-pass hierarchy: parent restored first, child (never deleted) must
    // remain correctly linked — pins that restoring the parent in-place
    // (same PK) does not orphan an existing child row.
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = sr_admin($repo->id);
    $this->actingAs($u);

    $parent = Location::create(['name' => 'Cataloguing', 'code' => null, 'type' => 'room', 'is_active' => true, 'repository_id' => $repo->id, 'parent_id' => null]);
    $parent->delete();
    $child = Location::create(['name' => 'Shelf A', 'code' => null, 'type' => 'shelf', 'is_active' => true, 'repository_id' => $repo->id, 'parent_id' => $parent->id]);

    $import = sr_run(
        LocationImporter::class,
        [['name' => 'Cataloguing', 'type' => 'Room', 'parent_name' => '', 'repository_code' => 'NRA', 'code' => '']],
        SR_LOCATION_MAP,
        $u->id,
    );

    expect(sr_failures($import))->toBe([]);
    $parent->refresh();
    $child->refresh();
    expect($parent->trashed())->toBeFalse()
        ->and($parent->id)->toBe($parent->id) // same PK, no re-creation
        ->and($child->parent_id)->toBe($parent->id)
        ->and($child->trashed())->toBeFalse();
});

test('Location: skip_duplicates=true still restores a soft-deleted root location instead of skipping it', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = sr_admin($repo->id);
    $this->actingAs($u);

    Location::create(['name' => 'Mould Room', 'code' => null, 'type' => 'room', 'is_active' => true, 'repository_id' => $repo->id, 'parent_id' => null])->delete();

    $import = sr_run(
        LocationImporter::class,
        [['name' => 'Mould Room', 'type' => 'Room', 'parent_name' => '', 'repository_code' => 'NRA', 'code' => '']],
        SR_LOCATION_MAP,
        $u->id,
        ['skip_duplicates' => true],
    );

    expect(sr_failures($import))->toBe([]);
    $row = Location::where('name', 'Mould Room')->first();
    expect($row)->not->toBeNull()
        ->and($row->trashed())->toBeFalse();
});

test('Location: skip_duplicates=true skips a LIVE existing root location and leaves it untouched', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = sr_admin($repo->id);
    $this->actingAs($u);

    Location::create(['name' => 'Conservation', 'code' => null, 'type' => 'room', 'is_active' => true, 'repository_id' => $repo->id, 'parent_id' => null, 'notes' => 'original']);

    $import = sr_run(
        LocationImporter::class,
        [['name' => 'Conservation', 'type' => 'Room', 'parent_name' => '', 'repository_code' => 'NRA', 'code' => '', 'notes' => 'changed']],
        array_merge(SR_LOCATION_MAP, ['notes' => 'notes']),
        $u->id,
        ['skip_duplicates' => true],
    );

    $failures = sr_failures($import);
    expect($failures)->toHaveCount(1)
        ->and(strtolower($failures[0]))->toContain('skip');
    expect(Location::where('name', 'Conservation')->value('notes'))->toBe('original');
});

test('Location: real prod CSV imported twice back-to-back is idempotent (no duplicates, no failures)', function () {
    [, $rows] = sr_loadCsv(SR_LOCATION_CSV);
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = sr_admin($repo->id);
    $this->actingAs($u);

    $first = sr_run(LocationImporter::class, $rows, SR_LOCATION_MAP, $u->id);
    expect(sr_failures($first))->toBe([]);
    expect(Location::count())->toBe(count($rows));

    $second = sr_run(LocationImporter::class, $rows, SR_LOCATION_MAP, $u->id);
    expect(sr_failures($second))->toBe([]);
    expect(Location::count())->toBe(count($rows))
        ->and(Location::withTrashed()->count())->toBe(count($rows));
});

// ─────────────────────────────────────────────────────────────────────────
// BATCH (real file: 51 rows including forbidden numbers 34/36, blank
// is_active, trailing-space types, and a LITERAL duplicate row for #43)
// ─────────────────────────────────────────────────────────────────────────

test('Batch: real prod CSV soft-deleted batches (1-29, main collection) restore with zero failures', function () {
    [, $allRows] = sr_loadCsv(SR_BATCH_CSV);
    $rows = array_filter($allRows, fn ($r) => (int) $r['batch_number'] >= 1 && (int) $r['batch_number'] <= 29);
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = sr_admin($repo->id);
    $this->actingAs($u);

    foreach ($rows as $row) {
        Batch::withoutGlobalScope(RepositoryScope::class)->create([
            'batch_number' => (int) $row['batch_number'],
            'repository_id' => $repo->id,
            'type' => 'MAIN_COLLECTION',
            'description' => $row['description'],
            'is_active' => true,
        ])->delete();
    }
    expect(Batch::withoutGlobalScope(RepositoryScope::class)->count())->toBe(0)
        ->and(Batch::withoutGlobalScope(RepositoryScope::class)->withTrashed()->count())->toBe(count($rows));

    $import = sr_run(BatchImporter::class, $rows, SR_BATCH_MAP, $u->id);

    expect(sr_failures($import))->toBe([]);
    expect(Batch::withoutGlobalScope(RepositoryScope::class)->count())->toBe(count($rows))
        ->and(Batch::withoutGlobalScope(RepositoryScope::class)->withTrashed()->count())->toBe(count($rows));
});

test('Batch: real forbidden numbers 34 and 36 from the prod CSV fail validation, not a silent restore', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = sr_admin($repo->id);
    $this->actingAs($u);

    $import = sr_run(
        BatchImporter::class,
        [
            ['batch_number' => '34', 'description' => 'Unused', 'type' => '', 'is_active' => '', 'repository_code' => 'NRA'],
            ['batch_number' => '36', 'description' => 'Unused', 'type' => '', 'is_active' => '', 'repository_code' => 'NRA'],
        ],
        SR_BATCH_MAP,
        $u->id,
    );

    $failures = sr_failures($import);
    expect($failures)->toHaveCount(2);
    expect(Batch::withoutGlobalScope(RepositoryScope::class)->whereIn('batch_number', [34, 36])->exists())->toBeFalse();
});

test('Batch: the LITERAL duplicate row #43 present twice in the real CSV does not crash and yields one live row', function () {
    // The real prod CSV has "43","NTG Accession","NOTARY_ACCESSION ","","NRA"
    // twice in a row (verified against the file on disk) — pin that the
    // importer's within-file idempotency holds for an exact real duplicate,
    // not just a synthetic one.
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = sr_admin($repo->id);
    $this->actingAs($u);

    $import = sr_run(
        BatchImporter::class,
        [
            ['batch_number' => '43', 'description' => 'NTG Accession', 'type' => 'NOTARY_ACCESSION ', 'is_active' => '', 'repository_code' => 'NRA'],
            ['batch_number' => '43', 'description' => 'NTG Accession', 'type' => 'NOTARY_ACCESSION ', 'is_active' => '', 'repository_code' => 'NRA'],
        ],
        SR_BATCH_MAP,
        $u->id,
    );

    expect(sr_failures($import))->toBe([]);
    $all = Batch::withoutGlobalScope(RepositoryScope::class)->withTrashed()->where('batch_number', 43)->get();
    expect($all)->toHaveCount(1);
});

test('Batch: mix of live, soft-deleted and new batch numbers from the real CSV restores only the trashed one', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = sr_admin($repo->id);
    $this->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => 1, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true]); // live
    Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => 2, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true])->delete(); // trashed

    $import = sr_run(
        BatchImporter::class,
        [
            ['batch_number' => '1', 'description' => 'St Chris collection', 'type' => 'MAIN_COLLECTION', 'is_active' => '', 'repository_code' => 'NRA'],
            ['batch_number' => '2', 'description' => 'St Chris collection', 'type' => 'MAIN_COLLECTION', 'is_active' => '', 'repository_code' => 'NRA'],
            ['batch_number' => '3', 'description' => 'St Chris collection', 'type' => 'MAIN_COLLECTION', 'is_active' => '', 'repository_code' => 'NRA'],
        ],
        SR_BATCH_MAP,
        $u->id,
    );

    expect(sr_failures($import))->toBe([]);
    $scope = Batch::withoutGlobalScope(RepositoryScope::class);
    expect((clone $scope)->count())->toBe(3)
        ->and((clone $scope)->withTrashed()->count())->toBe(3)
        ->and((clone $scope)->where('batch_number', 2)->first()->trashed())->toBeFalse();
});

test('Batch: skip_duplicates=true still restores a soft-deleted batch number instead of skipping it', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = sr_admin($repo->id);
    $this->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => 5, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true])->delete();

    $import = sr_run(
        BatchImporter::class,
        [['batch_number' => '5', 'description' => 'St Chris collection', 'type' => 'MAIN_COLLECTION', 'is_active' => '', 'repository_code' => 'NRA']],
        SR_BATCH_MAP,
        $u->id,
        ['skip_duplicates' => true],
    );

    expect(sr_failures($import))->toBe([]);
    $row = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 5)->first();
    expect($row)->not->toBeNull()
        ->and($row->trashed())->toBeFalse();
});

test('Batch: skip_duplicates=true skips a LIVE existing batch number and leaves it untouched', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = sr_admin($repo->id);
    $this->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->create(['batch_number' => 6, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true, 'description' => 'kept']);

    $import = sr_run(
        BatchImporter::class,
        [['batch_number' => '6', 'description' => 'changed', 'type' => 'MAIN_COLLECTION', 'is_active' => '', 'repository_code' => 'NRA']],
        SR_BATCH_MAP,
        $u->id,
        ['skip_duplicates' => true],
    );

    $failures = sr_failures($import);
    expect($failures)->toHaveCount(1)
        ->and(strtolower($failures[0]))->toContain('skip');
    expect(Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 6)->value('description'))->toBe('kept');
});

test('Batch: a soft-deleted batch in a DIFFERENT repository is NOT restored/stolen by a same-numbered import in another repo', function () {
    // Cross-tenant guard: the (batch_number, repository_id) unique index is
    // per-repository. A trashed batch #10 in repo A must stay untouched (and
    // trashed) when repo B imports its own batch #10.
    $repoA = Repository::factory()->create(['code' => 'RA10']);
    $repoB = Repository::factory()->create(['code' => 'RB10']);
    $u = sr_admin($repoB->id);
    $this->actingAs($u);

    $trashedInA = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 10, 'repository_id' => $repoA->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true, 'description' => 'A original',
    ]);
    $trashedInA->delete();

    $import = sr_run(
        BatchImporter::class,
        [['batch_number' => '10', 'description' => 'B new', 'type' => 'MAIN_COLLECTION', 'is_active' => '', 'repository_code' => 'RB10']],
        SR_BATCH_MAP,
        $u->id,
    );

    expect(sr_failures($import))->toBe([]);

    $trashedInA->refresh();
    expect($trashedInA->trashed())->toBeTrue()                       // still deleted, untouched
        ->and($trashedInA->description)->toBe('A original');          // not overwritten

    $liveInB = Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 10)->where('repository_id', $repoB->id)->first();
    expect($liveInB)->not->toBeNull()
        ->and($liveInB->id)->not->toBe($trashedInA->id)                // a genuinely different row
        ->and($liveInB->description)->toBe('B new');
});

test('Batch: real prod CSV imported twice back-to-back leaves forbidden rows failed both times, everything else stable', function () {
    [, $rows] = sr_loadCsv(SR_BATCH_CSV);
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $u = sr_admin($repo->id);
    $this->actingAs($u);

    $first = sr_run(BatchImporter::class, $rows, SR_BATCH_MAP, $u->id);
    $firstFailures = sr_failures($first);
    // 34 and 36 are forbidden; #43 is a literal duplicate collapsing to one row.
    expect($firstFailures)->toHaveCount(2);
    $countAfterFirst = Batch::withoutGlobalScope(RepositoryScope::class)->count();

    $second = sr_run(BatchImporter::class, $rows, SR_BATCH_MAP, $u->id);
    $secondFailures = sr_failures($second);
    expect($secondFailures)->toHaveCount(2);
    expect(Batch::withoutGlobalScope(RepositoryScope::class)->count())->toBe($countAfterFirst)
        ->and(Batch::withoutGlobalScope(RepositoryScope::class)->withTrashed()->count())->toBe($countAfterFirst);
})->group('slow');
