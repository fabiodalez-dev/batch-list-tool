<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Role;

/**
 * Bug #22 (hardening) — the blank-identifier auto identifier must key on the
 * ABSOLUTE source-row position, not a chunk-local counter.
 *
 * The client's real ~5MB batch list is imported through the streaming job
 * ({@see DeduplicatingImportExcel}), which chunks the file at 100 rows and
 * builds a FRESH importer per chunk. That importer's own row counter therefore
 * restarts at 0 in every chunk. ~92% of the client's rows carry NEITHER an
 * Identifier NOR a Catalogue Identifier, so two genuinely distinct rows whose
 * mapped content is byte-identical and whose absolute positions differ by an
 * exact multiple of the chunk size used to land at the SAME chunk-local
 * position → the SAME deterministic auto id → the second silently UPDATED the
 * first, merging two distinct documents into one (data loss).
 *
 * These tests drive the REAL streaming read path (`readExcelRowsFromFile` via
 * `handle()`) over a crafted .xlsx, running the job TWICE with DIFFERENT
 * startRow/endRow ranges — i.e. two real chunks, each with its own importer
 * instance — exactly as production does for a >1 MB file.
 */
uses(RefreshDatabase::class);

// DocumentImporter::beforeSave() opens a per-row savepoint that afterSave()
// closes; a failing saveRecord() can leave the depth incremented. Resync after
// each test so one test's failure never cascades (mirrors DocumentsTest).
afterEach(function (): void {
    while (DB::transactionLevel() > 1) {
        DB::rollBack();
    }
});

function chunkaid_admin(int $repoId): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repoId]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * Write a tiny .xlsx with a single "Series" column and the given data rows
 * (row 1 is the header). Returns the temp file path.
 *
 * @param array<int, string> $seriesCells one cell value per data row, in order
 */
function chunkaid_xlsx(array $seriesCells): string
{
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValue('A1', 'Series');
    $rowNo = 2;
    foreach ($seriesCells as $value) {
        $sheet->setCellValue('A' . $rowNo, $value);
        $rowNo++;
    }

    $path = tempnam(sys_get_temp_dir(), 'chunkaid_') . '.xlsx';
    new Xlsx($spreadsheet)->save($path);
    $spreadsheet->disconnectWorksheets();

    return $path;
}

/**
 * Run the rows through the wizard's real dispatch, which is the only import
 * path since 2026-09-17: it injects the ABSOLUTE pre-chunk row index, chunks,
 * and hands each chunk to Filament's ImportCsv. The queue is sync under test,
 * so the jobs execute here rather than being faked — the assertion is about
 * what ends up in the database, not about what was dispatched.
 *
 * @param list<string> $seriesCells one mapped cell per row
 */
function chunkaid_runViaWizard(array $seriesCells, User $actor): void
{
    EntityResolver::flushMemo();

    // Filament's ImportCsv ends with auth()->forgetGuards() — correct for a
    // queued job, which runs isolated, but under the sync queue it tears down
    // the test's own authentication. Re-establish it before each dispatch so a
    // second pass can create its Import row; this is an artefact of running the
    // queue inline, not something the application does between real imports.
    auth()->setUser($actor);

    $rows = array_map(static fn (string $cell): array => ['Series' => $cell], $seriesCells);

    $wizard = new ImportWizard;
    $dispatch = new ReflectionMethod($wizard, 'dispatchImportBatch');
    $dispatch->invoke($wizard, DocumentImporter::class, 'chunked.csv', '/tmp/chunked.csv', $rows, ['series' => 'Series'], []);
}

function chunkaid_import(string $filePath, int $userId): Import
{
    return Import::query()->create([
        'completed_at' => null,
        'file_name' => 'chunked.xlsx',
        'file_path' => $filePath,
        'importer' => DocumentImporter::class,
        'processed_rows' => 0,
        'total_rows' => 4,
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);
}

test('Bug #22 (hardening): two DISTINCT blank-identifier rows at the SAME chunk-local position in DIFFERENT chunks stay TWO documents (no over-merge across real chunks)', function () {
    $repo = Repository::factory()->create(['code' => 'CHK1']);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true]);
    $u = chunkaid_admin($repo->id);
    $this->actingAs($u);

    // Four blank-identifier rows (neither Identifier nor Catalogue Identifier).
    // Rows 2 and 4 are BYTE-IDENTICAL in mapped content and both sit at
    // chunk-local index 0 of their respective 2-row chunks — the exact collision
    // the chunk-local counter could not tell apart. Rows 3 and 5 are distinct
    // fillers so the chunks are genuinely 2 rows wide.
    // Two rows byte-identical in mapped content, far enough apart to land in
    // different chunks. The absolute index the wizard injects is what keeps
    // them apart; a chunk-local counter could not tell them from each other.
    $cells = array_merge(
        ['REG: Registers Private Practice'],
        array_map(static fn (int $i): string => "REG: filler {$i}", range(1, 99)),
        ['REG: Registers Private Practice'],
        ['REG: filler beta'],
    );

    chunkaid_runViaWizard($cells, $u);

    // No over-merge: every distinct source row survives as its own document.
    // Pre-fix the two identical rows collapsed into one, the second updating
    // the first.
    $docs = Document::withoutGlobalScope(RepositoryScope::class)->get();
    expect($docs)->toHaveCount(102);
    $ids = $docs->pluck('identifier');
    expect($ids->unique()->values())->toHaveCount(102)
        ->and($ids->every(fn ($i): bool => filled($i) && $i !== 'AUTO-'))->toBeTrue();
});

test('Bug #22 (hardening): the WIZARD CSV path injects an ABSOLUTE __source_row across the 100-row chunk boundary', function () {
    // The wizard (ImportWizard::dispatchImportBatch) chunks via array_chunk(100)
    // and dispatches one ImportCsv per chunk — a fresh importer each — so a
    // chunk-LOCAL counter would restart at 0 in chunk 2. This proves the wizard
    // injects the ABSOLUTE pre-chunk index instead, so the same collision the
    // streaming test guards against cannot happen on the wizard path either.
    $repo = Repository::factory()->create(['code' => 'CHK3']);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true]);
    $u = chunkaid_admin($repo->id);
    $this->actingAs($u);

    Bus::fake();

    // 102 identical rows → two ImportCsv chunks (absolute 0-99 and 100-101).
    $rows = array_fill(0, 102, ['Series' => 'REG: Registers Private Practice']);

    $wizard = new ImportWizard;
    $dispatch = new ReflectionMethod($wizard, 'dispatchImportBatch');
    $dispatch->invoke($wizard, DocumentImporter::class, 'w.csv', '/tmp/w.csv', $rows, ['series' => 'Series'], []);

    // Collect every row's injected __source_row across ALL batched ImportCsv jobs.
    $srcKeys = [];
    Bus::assertBatched(function ($batch) use (&$srcKeys): bool {
        foreach (collect($batch->jobs)->flatten() as $job) {
            $rowsProp = new ReflectionProperty($job, 'rows')->getValue($job);
            /** @var array<int, array<string, mixed>> $decoded */
            // nosemgrep: php.lang.security.unserialize-use.unserialize-use -- $rowsProp is the ImportCsv job payload this test just built via serialize(), never user input.
            $decoded = unserialize(base64_decode($rowsProp));
            foreach ($decoded as $row) {
                $srcKeys[] = (int) $row[DocumentImporter::SOURCE_ROW_KEY];
            }
        }

        return true;
    });

    sort($srcKeys);
    // Contiguous 0..101 across BOTH chunks — never a chunk-local reset to 0.
    expect($srcKeys)->toBe(range(0, 101));
});

test('Bug #22 (hardening): re-importing the SAME multi-chunk sequence keeps the document count stable (idempotent across chunks)', function () {
    $repo = Repository::factory()->create(['code' => 'CHK2']);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true]);
    $u = chunkaid_admin($repo->id);
    $this->actingAs($u);

    $cells = array_merge(
        ['REG: Registers Private Practice'],
        array_map(static fn (int $i): string => "REG: filler {$i}", range(1, 99)),
        ['REG: Registers Private Practice'],
        ['REG: filler beta'],
    );

    chunkaid_runViaWizard($cells, $u);
    $afterFirst = Document::withoutGlobalScope(RepositoryScope::class)->count();
    expect($afterFirst)->toBe(102);

    // Second pass over the SAME sheet: every row replays at the SAME absolute
    // position with the SAME content, so it derives the same auto identifier,
    // matches its existing document and updates in place. Count stable.
    chunkaid_runViaWizard($cells, $u);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->count())->toBe($afterFirst);
});
