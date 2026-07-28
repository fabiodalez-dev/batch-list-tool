<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use App\Support\BulkImport\Jobs\DeduplicatingImportExcel;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    (new Xlsx($spreadsheet))->save($path);
    $spreadsheet->disconnectWorksheets();

    return $path;
}

/**
 * Run ONE chunk of the REAL streaming job over the file: a fresh
 * DeduplicatingImportExcel with the given startRow/endRow (so it reads that row
 * range straight from the sheet, building its own importer — exactly one chunk).
 */
function chunkaid_runChunk(Import $import, string $columnMapExcelHeader, int $startRow, int $endRow): void
{
    EntityResolver::flushMemo();
    $job = new DeduplicatingImportExcel(
        importId: $import->getKey(),
        rows: null,
        startRow: $startRow,
        endRow: $endRow,
        columnMap: ['series' => $columnMapExcelHeader],
        options: ['headerOffset' => 0, 'activeSheet' => 0],
    );
    $job->handle();
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
    $path = chunkaid_xlsx([
        'REG: Registers Private Practice', // row 2  — chunk 1, local 0
        'REG: filler alpha',               // row 3  — chunk 1, local 1
        'REG: Registers Private Practice', // row 4  — chunk 2, local 0  (identical to row 2)
        'REG: filler beta',                // row 5  — chunk 2, local 1
    ]);

    $import = chunkaid_import($path, $u->id);

    // TWO REAL CHUNKS, each a fresh importer instance — the production shape.
    chunkaid_runChunk($import, 'Series', 2, 3); // chunk 1: rows 2-3
    chunkaid_runChunk($import, 'Series', 4, 5); // chunk 2: rows 4-5

    // No over-merge: all four distinct source rows survive as four documents.
    // (Pre-fix, rows 2 and 4 collapsed into ONE — the second UPDATED the first.)
    $docs = Document::withoutGlobalScope(RepositoryScope::class)->get();
    expect($docs)->toHaveCount(4);
    $ids = $docs->pluck('identifier');
    expect($ids->unique()->values())->toHaveCount(4)
        ->and($ids->every(fn ($i): bool => filled($i) && $i !== 'AUTO-'))->toBeTrue();

    // nosemgrep: php.lang.security.unlink-use.unlink-use -- test-controlled temp path.
    @unlink($path);
});

test('Bug #22 (hardening): re-importing the SAME multi-chunk sequence keeps the document count stable (idempotent across chunks)', function () {
    $repo = Repository::factory()->create(['code' => 'CHK2']);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true]);
    $u = chunkaid_admin($repo->id);
    $this->actingAs($u);

    $path = chunkaid_xlsx([
        'REG: Registers Private Practice',
        'REG: filler alpha',
        'REG: Registers Private Practice',
        'REG: filler beta',
    ]);

    $import = chunkaid_import($path, $u->id);

    // First full pass (both chunks).
    chunkaid_runChunk($import, 'Series', 2, 3);
    chunkaid_runChunk($import, 'Series', 4, 5);
    $afterFirst = Document::withoutGlobalScope(RepositoryScope::class)->count();
    expect($afterFirst)->toBe(4);

    // Second full pass over the SAME file: every row replays at the SAME
    // absolute position with the SAME content → same deterministic auto id →
    // each row MATCHES its existing document and UPDATES in place. Count stable.
    chunkaid_runChunk($import, 'Series', 2, 3);
    chunkaid_runChunk($import, 'Series', 4, 5);
    expect(Document::withoutGlobalScope(RepositoryScope::class)->count())->toBe($afterFirst);

    // nosemgrep: php.lang.security.unlink-use.unlink-use -- test-controlled temp path.
    @unlink($path);
});
