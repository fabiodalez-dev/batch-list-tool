<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Filament\Imports\SeriesImporter;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Jobs\ImportCsv;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * RFQ §3.1.3 — the Import Wizard exposes a "Skip rows that already exist"
 * checkbox (`skip_duplicates`). When enabled, an importer must SKIP a row
 * that matches an existing record (reporting it as a failed/skipped row via
 * {@see RowImportFailedException}) instead of silently upserting it. When the
 * option is absent or false, the historical upsert behaviour is preserved.
 *
 * These tests drive the real importer pipeline (the same path
 * {@see ImportCsv} runs per row). A skipped
 * row surfaces as a thrown {@see RowImportFailedException}, which the job
 * catches and records in the "download failed rows" CSV.
 */
uses(RefreshDatabase::class);

function sd_seedRoles(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function sd_makeAdmin(?int $repoId = null): User
{
    sd_seedRoles();
    /** @var User $u */
    $u = User::factory()->create([
        'email' => 'sd-admin+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repoId,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function sd_importModel(string $importerClass, int $userId): Import
{
    /** @var Import $row */
    $row = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'test.xlsx',
        'file_path' => '/tmp/test.xlsx',
        'importer' => $importerClass,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);

    return $row;
}

/**
 * @param array<string, mixed> $data
 * @param array<string, mixed> $options
 */
function sd_runImporter(string $importerClass, array $data, int $userId, array $options): object
{
    EntityResolver::flushMemo();
    $row = sd_importModel($importerClass, $userId);
    $columnMap = array_combine(array_keys($data), array_keys($data));

    $importer = new $importerClass($row, $columnMap, $options);
    $importer($data);

    return $importer;
}

test('skip_duplicates=true skips an existing Series row instead of updating it', function () {
    $u = sd_makeAdmin();
    $this->actingAs($u);

    $existing = Series::create([
        'code' => 'REG',
        'title' => 'Original title',
    ]);

    $threw = false;

    try {
        sd_runImporter(SeriesImporter::class, [
            'code' => 'REG',
            'title' => 'CHANGED title',
        ], $u->id, ['skip_duplicates' => true]);
    } catch (RowImportFailedException) {
        $threw = true;
    }

    expect($threw)->toBeTrue();

    // The existing record must be untouched.
    $existing->refresh();
    expect($existing->title)->toBe('Original title');
    expect(Series::query()->where('code', 'REG')->count())->toBe(1);
});

test('skip_duplicates absent/false updates the existing Series row (upsert preserved)', function () {
    $u = sd_makeAdmin();
    $this->actingAs($u);

    $existing = Series::create([
        'code' => 'REG',
        'title' => 'Original title',
    ]);

    sd_runImporter(SeriesImporter::class, [
        'code' => 'REG',
        'title' => 'CHANGED title',
    ], $u->id, ['skip_duplicates' => false]);

    $existing->refresh();
    expect($existing->title)->toBe('CHANGED title');
    expect(Series::query()->where('code', 'REG')->count())->toBe(1);
});

test('skip_duplicates=true skips an existing Document row instead of updating it', function () {
    $repo = Repository::create(['code' => 'SD', 'name' => 'Skip Dup Repo']);
    $u = sd_makeAdmin($repo->id);
    $this->actingAs($u);

    $series = Series::create(['code' => 'REG', 'title' => 'Registers']);

    $existing = Document::create([
        'identifier' => 'DOC-SKIP-1',
        'series_id' => $series->id,
        'repository_id' => $repo->id,
        'notes' => 'original note',
    ]);

    $threw = false;

    try {
        sd_runImporter(DocumentImporter::class, [
            'identifier' => 'DOC-SKIP-1',
            'series' => 'REG',
            'notes' => 'CHANGED note',
        ], $u->id, ['skip_duplicates' => true]);
    } catch (RowImportFailedException) {
        $threw = true;
    }

    expect($threw)->toBeTrue();

    $existing->refresh();
    expect($existing->notes)->toBe('original note');
});

test('skip_duplicates=false updates the existing Document row (upsert preserved)', function () {
    $repo = Repository::create(['code' => 'SD2', 'name' => 'Skip Dup Repo 2']);
    $u = sd_makeAdmin($repo->id);
    $this->actingAs($u);

    $series = Series::create(['code' => 'REG', 'title' => 'Registers']);

    $existing = Document::create([
        'identifier' => 'DOC-SKIP-2',
        'series_id' => $series->id,
        'repository_id' => $repo->id,
        'notes' => 'original note',
    ]);

    sd_runImporter(DocumentImporter::class, [
        'identifier' => 'DOC-SKIP-2',
        'series' => 'REG',
        'notes' => 'CHANGED note',
    ], $u->id, ['skip_duplicates' => false]);

    $existing->refresh();
    expect($existing->notes)->toBe('CHANGED note');
});
