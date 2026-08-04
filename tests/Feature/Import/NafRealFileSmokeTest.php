<?php

declare(strict_types=1);

use App\Filament\Imports\BoxImporter;
use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Batch;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;

/**
 * Local-only smoke over the REAL NAF client files (client feedback 2026-08-04).
 *
 * The PII source files live under nra/ (gitignored) and are absent in CI, so
 * every test here skips unless the file exists. Two kinds of check:
 *
 *   1. Column mapping — ImportWizard::guessColumnMap over the client's ACTUAL
 *      headers resolves the new columns to the right importer columns (the box
 *      source's plain 'Tracking' header, the document batch list's 'Tracking').
 *      Pure, deterministic, no reference data needed.
 *
 *   2. No-crash import — a handful of real rows go through the real importer
 *      entry point without a fatal error. Real rows reference series / batches /
 *      parent boxes / locations that a clean test DB lacks, so most rows fail
 *      validation (expected); we assert only that the import doesn't blow up and
 *      leaves the DB queryable. The full-file import against a seeded DB is
 *      covered by BoxRealFileImportSmokeTest.
 */
uses(RefreshDatabase::class);

const NAF_BOX_SOURCE = 'nra/inbox/2026-08-01_NAF_Boxes_1_Blue_source.xlsx';
const NAF_DOC_CSV = 'nra/inbox/2026-06-22_NAF_New_BATCH_LIST_04_06_26_sample.csv';

/** @return list<string> */
function nafsmoke_boxHeaders(): array
{
    $reader = IOFactory::createReaderForFile(base_path(NAF_BOX_SOURCE));
    $reader->setReadDataOnly(true);
    $grid = $reader->load(base_path(NAF_BOX_SOURCE))->getActiveSheet()->rangeToArray('A1:BZ1', null, true, false);

    return array_values(array_filter(
        array_map(fn ($h): string => (string) $h, $grid[0]),
        fn (string $h): bool => trim($h) !== '',
    ));
}

/** @return array{0: list<string>, 1: list<array<int, mixed>>} */
function nafsmoke_docRows(int $limit): array
{
    $fh = fopen(base_path(NAF_DOC_CSV), 'r');
    $headers = array_map(fn ($h): string => (string) $h, fgetcsv($fh) ?: []);
    $rows = [];
    for ($i = 0; $i < $limit && ($row = fgetcsv($fh)) !== false; $i++) {
        $rows[] = $row;
    }
    fclose($fh);

    return [$headers, $rows];
}

function nafsmoke_admin(): int
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    /** @var User $u */
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id]);
    $u->assignRole('super_admin');
    test()->actingAs($u);

    return $u->id;
}

/**
 * Run the real streaming importer over header + rows, mirroring the chunk job.
 * Real rows often fail validation; each failure is swallowed AND any savepoint
 * it left open is rolled back so it can't poison the next test (RefreshDatabase
 * wraps every test in a transaction).
 *
 * @param list<string> $headers
 * @param list<array<int, mixed>> $rows
 */
function nafsmoke_run(string $importer, array $headers, array $rows, int $userId): void
{
    $map = ImportWizard::guessColumnMap($importer, $headers);
    $baseLevel = DB::transactionLevel();
    foreach ($rows as $row) {
        if (count(array_filter($row, fn ($c): bool => $c !== null && trim((string) $c) !== '')) === 0) {
            continue;
        }
        $data = [];
        foreach ($headers as $i => $h) {
            $data[$h] = $row[$i] ?? null;
        }
        EntityResolver::flushMemo();
        /** @var Import $imp */
        $imp = Import::query()->create([
            'completed_at' => null, 'file_name' => 'real.xlsx', 'file_path' => '/tmp/real.xlsx',
            'importer' => $importer, 'processed_rows' => 0, 'total_rows' => 1,
            'successful_rows' => 0, 'user_id' => $userId,
        ]);

        try {
            (new $importer($imp, $map, []))($data);
        } catch (ValidationException|RowImportFailedException) {
            // The ONLY two failures a real row is expected to hit on a clean DB
            // (unseeded series / batch / parent box / location). Any other
            // throwable — a TypeError, an SQL error, an importer regression —
            // propagates and fails the test, instead of being silently hidden.
        }
        // Roll back any savepoint a failed row left open (importer beforeSave
        // opens one and afterSave commits; a throw between them leaks it).
        while (DB::transactionLevel() > $baseLevel) {
            DB::rollBack();
        }
    }
}

it('maps the real BOX source "Tracking" header onto tracking_note (and Note onto notes)', function () {
    $headers = nafsmoke_boxHeaders();
    $map = ImportWizard::guessColumnMap(BoxImporter::class, $headers);

    expect($headers)->toContain('Tracking')
        ->and($map['tracking_note'])->toBe('Tracking')
        ->and($map['notes'])->toBe('Note')
        ->and($map['seal_number'])->toBe('Seal Number');
})->skip(fn (): bool => ! file_exists(base_path(NAF_BOX_SOURCE)), 'real NAF box source absent (PII, not in CI)');

it('maps the real DOCUMENT batch list "Tracking" header onto the document tracking column', function () {
    [$headers] = nafsmoke_docRows(0);
    $map = ImportWizard::guessColumnMap(DocumentImporter::class, $headers);

    expect($headers)->toContain('Tracking')
        ->and($map['tracking'])->toBe('Tracking');
})->skip(fn (): bool => ! file_exists(base_path(NAF_DOC_CSV)), 'real NAF document batch list absent (PII, not in CI)');

it('the real DOCUMENT batch list has NO code Location column yet (only free-text NRA/Museum)', function () {
    // The client's current sheet carries 'NRA Location' / 'Museum Location'
    // (free text), not the new code-resolved 'Location'. So the document
    // Location import column stays unmapped until they add it — documented here
    // so the expectation is explicit.
    [$headers] = nafsmoke_docRows(0);
    $map = ImportWizard::guessColumnMap(DocumentImporter::class, $headers);

    expect($map['location'])->toBeNull()
        ->and($map['nra_location'])->toBe('NRA Location')
        ->and($map['museum_location'])->toBe('Museum Location');
})->skip(fn (): bool => ! file_exists(base_path(NAF_DOC_CSV)), 'real NAF document batch list absent (PII, not in CI)');

it('runs the first rows of the real BOX source through the importer without a fatal error', function () {
    $userId = nafsmoke_admin();
    Batch::withoutGlobalScope(RepositoryScope::class)->firstOrCreate(['batch_number' => '1', 'repository_id' => Repository::firstWhere('code', 'NRA')->id]);

    $reader = IOFactory::createReaderForFile(base_path(NAF_BOX_SOURCE));
    $reader->setReadDataOnly(true);
    $grid = $reader->load(base_path(NAF_BOX_SOURCE))->getActiveSheet()->toArray(null, true, false, false);
    $headers = array_map(fn ($h): string => (string) $h, $grid[0]);
    $rows = array_slice($grid, 1, 30);

    nafsmoke_run(BoxImporter::class, $headers, $rows, $userId);

    // The import must leave the DB queryable and no transaction leaked.
    expect(DB::transactionLevel())->toBeGreaterThan(0) // still inside RefreshDatabase's wrapper
        ->and(Document::withoutGlobalScope(RepositoryScope::class)->count())->toBeGreaterThanOrEqual(0);
})->skip(fn (): bool => ! file_exists(base_path(NAF_BOX_SOURCE)), 'real NAF box source absent (PII, not in CI)');

it('runs the first rows of the real DOCUMENT batch list through the importer without a fatal error', function () {
    $userId = nafsmoke_admin();
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Reg', 'is_active' => true, 'is_wills_series' => false]);

    [$headers, $rows] = nafsmoke_docRows(30);
    nafsmoke_run(DocumentImporter::class, $headers, $rows, $userId);

    expect(Document::withoutGlobalScope(RepositoryScope::class)->count())->toBeGreaterThanOrEqual(0);
})->skip(fn (): bool => ! file_exists(base_path(NAF_DOC_CSV)), 'real NAF document batch list absent (PII, not in CI)');
