<?php

declare(strict_types=1);

use App\Filament\Imports\AuthorityImporter;
use App\Filament\Imports\BatchImporter;
use App\Filament\Imports\BoxImporter;
use App\Filament\Imports\LocationImporter;
use App\Filament\Imports\SeriesImporter;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use HayderHatem\FilamentExcelImport\Actions\Imports\Jobs\ImportExcel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * The REAL client path, against a DIRTY database.
 *
 * Earlier tests ran the standard Filament importer against a CLEAN table — which
 * hid every bug the client actually hit, because her failures were STATEFUL
 * (soft-deleted residue, duplicate keys) and ran through the hayderhatem
 * STREAMING importer (ImportExcel), NOT the standard path. These tests drive the
 * exact ImportExcel job the "Import Excel / CSV" button dispatches, over a
 * database seeded into the messy states that broke in production, and assert:
 *   - soft-deleted rows are restored on re-import (no unique collision);
 *   - a genuine DB rejection surfaces the REAL humanised reason in the failed
 *     rows (never the opaque "generic_validation").
 */
uses(RefreshDatabase::class);

function ddi_admin(?int $repoId = null): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repoId]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * Run the actual hayderhatem ImportExcel job (the streaming path the client
 * uses) for the given rows, and return the completed Import with its failed rows.
 *
 * @param class-string $importer
 * @param array<int, array<string, string>> $rows keyed by Excel header
 * @param array<string, string> $columnMap importerField => Excel header
 * @param array<string, mixed> $options
 */
function ddi_run(string $importer, array $rows, array $columnMap, int $userId, array $options = []): Import
{
    EntityResolver::flushMemo();
    /** @var Import $import */
    $import = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'dirty.xlsx',
        'file_path' => '/tmp/dirty.xlsx',
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

/** @return array<int, string> the human validation_error messages of the failed rows */
function ddi_failures(Import $import): array
{
    return $import->failedRows()->pluck('validation_error')->all();
}

// ─── Series: all codes soft-deleted, re-import (THE production incident) ──────

test('streaming re-import of ALL soft-deleted series restores them, zero failures', function () {
    $u = ddi_admin();
    $this->actingAs($u);

    // Dirty state: the codes already exist but are soft-deleted (what the client had).
    foreach (['R', 'REG', 'RWL'] as $code) {
        Series::create(['code' => $code, 'title' => 'old', 'is_active' => true])->delete();
    }
    expect(Series::count())->toBe(0)
        ->and(Series::withTrashed()->count())->toBe(3);

    $import = ddi_run(
        SeriesImporter::class,
        [
            ['Identifier' => 'R', 'Title' => 'Register Copies'],
            ['Identifier' => 'REG', 'Title' => 'Registers'],
            ['Identifier' => 'RWL', 'Title' => 'Wills'],
        ],
        ['code' => 'Identifier', 'title' => 'Title'],
        $u->id,
    );

    expect(ddi_failures($import))->toBe([]);              // no masked "generic_validation"
    expect(Series::count())->toBe(3)                       // restored + live
        ->and(Series::withTrashed()->count())->toBe(3)     // NOT duplicated
        ->and(Series::where('code', 'RWL')->value('title'))->toBe('Wills'); // and updated
});

// ─── Series: the messy mix — some live, some trashed, some brand new ─────────

test('streaming import handles a mix of live, soft-deleted and new series in one file', function () {
    $u = ddi_admin();
    $this->actingAs($u);

    Series::create(['code' => 'LIVE', 'title' => 'already here', 'is_active' => true]); // live
    Series::create(['code' => 'GONE', 'title' => 'deleted', 'is_active' => true])->delete(); // trashed

    $import = ddi_run(
        SeriesImporter::class,
        [
            ['Identifier' => 'LIVE', 'Title' => 'updated'],   // update existing live
            ['Identifier' => 'GONE', 'Title' => 'restored'],  // restore trashed
            ['Identifier' => 'NEW', 'Title' => 'fresh'],      // create new
        ],
        ['code' => 'Identifier', 'title' => 'Title'],
        $u->id,
    );

    expect(ddi_failures($import))->toBe([]);
    expect(Series::count())->toBe(3)                       // LIVE, GONE(restored), NEW
        ->and(Series::withTrashed()->count())->toBe(3)     // no duplicates
        ->and(Series::where('code', 'GONE')->value('title'))->toBe('restored')
        ->and(Series::where('code', 'LIVE')->value('title'))->toBe('updated');
});

// ─── A GENUINE DB rejection surfaces the REAL reason (not generic_validation) ─

test('a genuine unique-constraint collision surfaces the real humanised error, not generic_validation', function () {
    // A real repository (NOT null) so the (repository_id, code) unique index
    // actually bites — MySQL treats NULLs in a unique key as distinct.
    $repo = Repository::factory()->create(['code' => 'DDIR']);
    $u = ddi_admin($repo->id);
    $this->actingAs($u);

    // Dirty state: a LIVE location in this repo already owns code DUP.
    Location::create(['name' => 'Existing room', 'code' => 'DUP', 'type' => 'room', 'is_active' => true, 'repository_id' => $repo->id, 'parent_id' => null]);

    // Import a DIFFERENTLY-named location with the SAME code. LocationImporter
    // matches by name, so it won't find the existing one → tries to INSERT →
    // collides on the (repository_id, code) unique index at saveRecord().
    $import = ddi_run(
        LocationImporter::class,
        [['Location name' => 'Brand new room', 'Code' => 'DUP', 'Type' => 'room', 'Repository' => 'DDIR']],
        ['name' => 'Location name', 'code' => 'Code', 'type' => 'Type', 'repository_code' => 'Repository'],
        $u->id,
    );

    $failures = ddi_failures($import);
    expect($failures)->toHaveCount(1);
    // The REAL reason — NOT the opaque generic message, and no raw SQLSTATE.
    expect($failures[0])->not->toContain('generic_validation')
        ->and($failures[0])->not->toContain('SQLSTATE')
        ->and(strtolower($failures[0]))->toContain('already exists');
});

// ─── Authority: soft-deleted re-import through the streaming path ────────────

test('streaming re-import of a soft-deleted authority restores it', function () {
    $u = ddi_admin();
    $this->actingAs($u);

    Authority::create(['identifier' => 'R646', 'surname' => 'Farrugia', 'entity_type' => 'Notary'])->delete();

    $import = ddi_run(
        AuthorityImporter::class,
        [['Identifier' => 'R646', 'Type of Entity' => 'Notary', 'Creator Surname' => 'Farrugia']],
        ['identifier' => 'Identifier', 'entity_type' => 'Type of Entity', 'surname' => 'Creator Surname'],
        $u->id,
    );

    expect(ddi_failures($import))->toBe([]);
    expect(Authority::where('identifier', 'R646')->count())->toBe(1)
        ->and(Authority::withTrashed()->where('identifier', 'R646')->count())->toBe(1);
});

// ─── skip_duplicates against a dirty (already-populated) table ───────────────

test('skip_duplicates surfaces a clear "already exists" skip, not a generic error', function () {
    $u = ddi_admin();
    $this->actingAs($u);

    Series::create(['code' => 'DUP', 'title' => 'here', 'is_active' => true]);

    $import = ddi_run(
        SeriesImporter::class,
        [['Identifier' => 'DUP', 'Title' => 'again']],
        ['code' => 'Identifier', 'title' => 'Title'],
        $u->id,
        ['skip_duplicates' => true],
    );

    $failures = ddi_failures($import);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->not->toContain('generic_validation')
        ->and(strtolower($failures[0]))->toContain('skip');
});

// ─── Duplicate rows within the SAME file (idempotent, no crash/duplicate) ────

test('duplicate rows for the same code within one file do not crash or duplicate', function () {
    $u = ddi_admin();
    $this->actingAs($u);

    $import = ddi_run(
        SeriesImporter::class,
        [
            ['Identifier' => 'DUP', 'Title' => 'first'],
            ['Identifier' => 'DUP', 'Title' => 'second'],  // same code again
        ],
        ['code' => 'Identifier', 'title' => 'Title'],
        $u->id,
    );

    expect(ddi_failures($import))->toBe([]);
    expect(Series::where('code', 'DUP')->count())->toBe(1)   // exactly one row
        ->and(Series::where('code', 'DUP')->value('title'))->toBe('second'); // last wins
});

// ─── Case / whitespace variants must match the existing (soft-deleted) row ───

test('re-importing a code in different case/whitespace restores the same soft-deleted row', function () {
    $u = ddi_admin();
    $this->actingAs($u);

    Series::create(['code' => 'RWL', 'title' => 'old', 'is_active' => true])->delete();

    $import = ddi_run(
        SeriesImporter::class,
        [['Identifier' => '  rwl  ', 'Title' => 'trimmed & lowercased']], // messy input
        ['code' => 'Identifier', 'title' => 'Title'],
        $u->id,
    );

    expect(ddi_failures($import))->toBe([]);
    // Must resolve to the SAME row (trim + case-insensitive) and restore it —
    // NOT create a second row. (The code takes the operator's typed value.)
    expect(Series::withTrashed()->count())->toBe(1)  // no duplicate row created
        ->and(Series::count())->toBe(1);              // the single row is live (restored)
});

// ─── Missing required value surfaces a clear error (not generic) ─────────────

test('a row missing a required value surfaces a clear reason, not generic_validation', function () {
    $u = ddi_admin();
    $this->actingAs($u);

    // Series 'title' is required for NEW records; omit it for a brand-new code.
    $import = ddi_run(
        SeriesImporter::class,
        [['Identifier' => 'NOCODE', 'Title' => '']],
        ['code' => 'Identifier', 'title' => 'Title'],
        $u->id,
    );

    $failures = ddi_failures($import);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->not->toContain('generic_validation')
        ->and(strtolower($failures[0]))->toContain('title');
    expect(Series::where('code', 'NOCODE')->exists())->toBeFalse();
});

// ─── An over-long value surfaces a clear error (not generic) ─────────────────

test('an over-long value is reported clearly, not as generic_validation', function () {
    $u = ddi_admin();
    $this->actingAs($u);

    $import = ddi_run(
        SeriesImporter::class,
        [['Identifier' => 'X', 'Title' => str_repeat('z', 300)]], // title max:255
        ['code' => 'Identifier', 'title' => 'Title'],
        $u->id,
    );

    $failures = ddi_failures($import);
    expect($failures)->toHaveCount(1)
        ->and($failures[0])->not->toContain('generic_validation')
        ->and($failures[0])->not->toContain('SQLSTATE');
});

// ─── Batch: soft-deleted re-import through the streaming path (dirty) ─────────

test('streaming re-import of a soft-deleted batch restores it (dirty DB)', function () {
    $repo = Repository::factory()->create(['code' => 'DDB']);
    $u = ddi_admin($repo->id);
    $this->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 50, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ])->delete();

    $import = ddi_run(
        BatchImporter::class,
        [['Batch Number' => '50', 'Type' => 'MAIN_COLLECTION']],
        ['batch_number' => 'Batch Number', 'type' => 'Type'],
        $u->id,
    );

    expect(ddi_failures($import))->toBe([]);
    $all = Batch::withoutGlobalScope(RepositoryScope::class)->withTrashed()->where('batch_number', 50)->get();
    expect($all)->toHaveCount(1)
        ->and($all->first()->trashed())->toBeFalse();
});

// ─── Box: soft-deleted re-import by barcode through the streaming path ────────

test('streaming re-import of a soft-deleted box (by barcode) restores it (dirty DB)', function () {
    $repo = Repository::factory()->create(['code' => 'DDBX']);
    $u = ddi_admin($repo->id);
    $this->actingAs($u);

    $batch = Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 9, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);
    Box::factory()->create(['batch_id' => $batch->id, 'barcode' => 'BC-DDI-9', 'box_number' => 'BOX-DDI-9', 'box_type' => 'RAS'])->delete();

    $import = ddi_run(
        BoxImporter::class,
        [['Box No' => 'BOX-DDI-9', 'Box Type' => 'RAS', 'Batch Number' => '9', 'Barcode' => 'BC-DDI-9']],
        ['box_number' => 'Box No', 'box_type' => 'Box Type', 'batch_number' => 'Batch Number', 'barcode' => 'Barcode'],
        $u->id,
    );

    expect(ddi_failures($import))->toBe([]);
    $all = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->withTrashed()->where('barcode', 'BC-DDI-9')->get();
    expect($all)->toHaveCount(1)
        ->and($all->first()->trashed())->toBeFalse();
});

// ─── Numeric Excel cells in text columns (the 533/678 authority failures) ────

test('a numeric cell in a string column (alternative_identifier = 511) imports, not "must be a string"', function () {
    $u = ddi_admin();
    $this->actingAs($u);

    // 511 is a genuine INT, exactly as PhpSpreadsheet hands a numeric cell to
    // the streaming importer — the client's file had numeric alternative ids.
    $import = ddi_run(
        AuthorityImporter::class,
        [['Identifier' => 'R1', 'Alt' => 511, 'Type of Entity' => 'Person', 'Creator Surname' => 'Abela']],
        ['identifier' => 'Identifier', 'alternative_identifier' => 'Alt', 'entity_type' => 'Type of Entity', 'surname' => 'Creator Surname'],
        $u->id,
    );

    expect(ddi_failures($import))->toBe([]);   // NOT "must be a string"
    expect(Authority::where('identifier', 'R1')->value('alternative_identifier'))->toBe('511');
});

test('numeric box_number / barcode cells import cleanly (streaming, dirty DB)', function () {
    $repo = Repository::factory()->create(['code' => 'DDN']);
    $u = ddi_admin($repo->id);
    $this->actingAs($u);

    Batch::withoutGlobalScope(RepositoryScope::class)->create([
        'batch_number' => 3, 'repository_id' => $repo->id, 'type' => 'MAIN_COLLECTION', 'is_active' => true,
    ]);

    // box_number and barcode arrive as numbers from Excel.
    $import = ddi_run(
        BoxImporter::class,
        [['Box No' => 42, 'Box Type' => 'RAS', 'Batch Number' => 3, 'Barcode' => 99887766]],
        ['box_number' => 'Box No', 'box_type' => 'Box Type', 'batch_number' => 'Batch Number', 'barcode' => 'Barcode'],
        $u->id,
    );

    expect(ddi_failures($import))->toBe([]);
    expect(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', '99887766')->exists())->toBeTrue();
});
