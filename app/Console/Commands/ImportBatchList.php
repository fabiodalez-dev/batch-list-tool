<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Authority;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use App\Support\BulkImport\SpreadsheetHeaders;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;

/**
 * Header-driven bulk importer for the NAF "New_BATCH_LIST" single-workbook
 * export (26k+ rows), delegating EVERY row through {@see DocumentImporter}.
 *
 * Historically this command had its OWN persistence (`new Document()->save()` +
 * `Box::firstOrCreate`) and BYPASSED the Filament importer, so a bulk import did
 * NOT build box_movements, box_barcode_history, the current_box_id repoint,
 * accession/authority pivots or identifier history. It now runs each row through
 * the SAME single-row pipeline the Import Wizard uses ({@see DocumentImporter}),
 * so the bulk path and the wizard path can never drift apart: one row shape, one
 * set of side effects.
 *
 * What the command still owns (things a per-row importer cannot):
 *   - memory-safe streaming of the source workbook in row windows (a 26k-row
 *     xlsx is never materialised whole — that OOMs on shared hosting);
 *   - positional de-duplication of the NAF's repeated headers (via
 *     {@see SpreadsheetHeaders::dedupe()}) so each physical column keeps a
 *     distinct key, exactly as the streaming job does;
 *   - a Series bootstrap pre-pass: the importer resolves Series match-only, so
 *     the distinct Series codes are firstOrCreate'd up front (the console's old
 *     permissive semantics, INCLUDING 'Unknown');
 *   - threading each row's ABSOLUTE sheet position under the importer's reserved
 *     source-row key so a blank-identifier row's auto id stays idempotent;
 *   - per-row isolation: a bad row is caught and rolled back to the base
 *     transaction level, and the run continues.
 *
 * The Scout index is intentionally NOT updated per row (disableSearchSyncing) —
 * run `php artisan scout:import "App\Models\Document"` afterwards to rebuild it.
 */
class ImportBatchList extends Command
{
    private const int WINDOW = 2000;

    /**
     * Tables emptied by --truncate-data. EXCLUDES users, roles, permissions,
     * model_has_roles, the lookup vocabularies, repositories and custom-field
     * DEFINITIONS — re-importing data must never disturb accounts or config.
     *
     * `authorities` and `series` are NOT truncated: the aligned path never
     * re-creates authorities (it links match-only), and series are
     * firstOrCreate'd by the bootstrap pre-pass — both are master data now.
     * Order is irrelevant (FK checks are disabled around the truncate).
     *
     * @var array<int, string>
     */
    private const array DATA_TABLES = [
        'document_authority', 'accession_batch', 'box_movements',
        'document_location_history', 'document_barcode_history',
        'document_identifier_history', 'box_barcode_history', 'box_seal_number_history',
        'custom_field_values',
        'documents', 'boxes', 'accessions', 'batches',
    ];

    protected $signature = 'nra:import-batch-list
        {--file= : Path to the New_BATCH_LIST xlsx/csv}
        {--sheet=BATCH_LIST : Worksheet name}
        {--limit=0 : Import at most N data rows (0 = all)}
        {--dry-run : Roll everything back at the end (use with --limit)}
        {--truncate-data : Empty ONLY the data tables (documents/boxes/batches/accessions + pivots/history) before importing — NEVER touches users, roles, permissions, lookups, authorities or series}
        {--repo=NRA : Repository code to attach rows to}
        {--user= : Email of the user to attribute the import to (default: first super_admin)}
        {--force : Skip the --truncate-data confirmation prompt (for automation)}';

    protected $description = 'Bulk import of the NAF New_BATCH_LIST workbook — delegates every row through DocumentImporter (no drift with the wizard).';

    public function handle(): int
    {
        @ini_set('memory_limit', '3G');

        // Bulk import: do not push every row to the search index synchronously
        // (Scout's per-model save hook would dominate the runtime for 26k rows).
        // Rebuild the index afterwards with `scout:import`.
        Document::disableSearchSyncing();
        Authority::disableSearchSyncing();

        // Start from a clean FK-resolution memo. A no-op in production (the
        // command is a fresh process), but essential when the process is reused
        // (e.g. the test suite): a stale memo would map a code to an id from a
        // previous DB state.
        EntityResolver::flushMemo();

        $file = $this->option('file')
            ?: base_path('nra/inbox/2026-06-22_NAF_New_BATCH_LIST_04_06_26_sample.xlsx');
        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $repo = Repository::where('code', $this->option('repo'))->first();
        if ($repo === null) {
            $this->error("Repository '{$this->option('repo')}' not found — seed it first.");

            return self::FAILURE;
        }

        $user = $this->resolveUser();
        if ($user === null) {
            $this->error('No user to attribute the import to — pass --user=<email> or seed a super_admin.');

            return self::FAILURE;
        }

        // In-memory ONLY default-repository override so resolveRecord() scopes to
        // the requested repository. NEVER persist a change to a user record.
        if ((int) $user->default_repository_id !== (int) $repo->id) {
            $user->setAttribute('default_repository_id', $repo->id);
        }

        $sheetName = (string) $this->option('sheet');
        $limit = (int) $this->option('limit');
        $dry = (bool) $this->option('dry-run');

        // Header → deduped keys (same scheme the streaming job uses).
        $rawHeader = $this->readHeader($file, $sheetName);
        $dedupedHeaders = SpreadsheetHeaders::dedupe($rawHeader);

        // Authenticate BEFORE building the importer / guessing the column map:
        // getColumns() reads the active repository's custom-field definitions,
        // and resolveRecord() reads the acting user's default_repository_id.
        auth()->setUser($user);

        try {
            $columnMap = array_filter(
                ImportWizard::guessColumnMap(DocumentImporter::class, $dedupedHeaders),
                static fn ($v): bool => $v !== null && $v !== '',
            );
            ImportWizard::logUnrecognisedHeaders(
                DocumentImporter::class,
                $dedupedHeaders,
                $columnMap,
                'nra:import-batch-list',
            );

            $this->info('Mapped ' . count($columnMap) . ' importer columns from ' . count($dedupedHeaders) . ' headers.');

            // Optional selective wipe of the DATA tables only. Never in dry-run.
            if ($this->option('truncate-data') && ! $dry) {
                $this->warn('--truncate-data will EMPTY: ' . implode(', ', self::DATA_TABLES));
                $this->info('It will NOT touch: users, roles, permissions, lookups, repositories, authorities, series.');
                $bypass = (bool) $this->option('force') || ! $this->input->isInteractive();
                if (! $bypass && ! $this->confirm('Proceed with truncating the data tables?', false)) {
                    $this->info('Aborted — nothing truncated, nothing imported.');

                    return self::SUCCESS;
                }
                $this->truncateDataTables();
                $this->info('Data tables truncated (accounts, lookups, authorities and series preserved).');
            }

            // Series bootstrap: the importer resolves Series match-only, so the
            // distinct Series codes must exist before the run. Permissive — the
            // console's original semantics, INCLUDING a literal 'Unknown'.
            $created = $this->bootstrapSeries($file, $sheetName, $dedupedHeaders, $columnMap, (int) $repo->id, $limit);
            if ($created > 0) {
                $this->info("Bootstrapped {$created} new Series.");
            }

            // Persisted Import model — required by LogsImportRows (audit
            // attribution) and by the legacy movement/history builders
            // (user_id = $this->import->user).
            $import = new Import;
            $import->user()->associate($user);
            $import->file_name = basename($file);
            $import->file_path = $file;
            $import->importer = DocumentImporter::class;
            $import->processed_rows = 0;
            $import->successful_rows = 0;
            $import->total_rows = 0;
            $import->save();

            DB::disableQueryLog();

            // Real import runs in autocommit so one bad row can't roll back the
            // rest; dry-run wraps everything in a single transaction it rolls
            // back at the end. $baseTx is the level a failed row unwinds to.
            if ($dry) {
                DB::beginTransaction();
            }
            $baseTx = DB::transactionLevel();

            $ok = 0;
            $failed = 0;
            $errSamples = [];

            foreach ($this->rowWindows($file, $sheetName, $limit) as $absRow => $rawRow) {
                $rowArray = $this->buildRowArray($dedupedHeaders, $rawRow, (int) $absRow);

                try {
                    // Fresh importer PER ROW: the importer keeps per-instance
                    // transactional state (an open per-row savepoint flag). After
                    // a failure we unwind the DB to $baseTx here; a fresh instance
                    // guarantees that stale flag never double-rolls the next row.
                    $importer = $import->getImporter($columnMap, ['skip_duplicates' => false]);
                    $importer($rowArray);
                    $ok++;
                } catch (RowImportFailedException|ValidationException $rowErr) {
                    $failed++;
                    if (count($errSamples) < 10) {
                        $errSamples[] = 'row ' . $absRow . ': ' . substr($this->rowErrorMessage($rowErr), 0, 160);
                    }
                    // Unwind any half-open per-row savepoint back to the base
                    // level so the next row starts clean.
                    while (DB::transactionLevel() > $baseTx) {
                        DB::rollBack();
                    }
                }

                if (($ok + $failed) % 1000 === 0) {
                    $this->info('  … ' . ($ok + $failed) . " rows ({$ok} ok, {$failed} failed)");
                }
            }

            if ($dry) {
                DB::rollBack();
                $this->warn('DRY RUN — all changes rolled back.');
            }

            $this->printSummary($ok, $failed, $errSamples, $dry);

            return self::SUCCESS;
        } catch (\Throwable $throwable) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->error('Import aborted: ' . $throwable->getMessage());
            $this->line($throwable->getFile() . ':' . $throwable->getLine());

            return self::FAILURE;
        } finally {
            auth()->forgetGuards();
        }
    }

    /* ── Attribution ─────────────────────────────────────────────────────── */

    private function resolveUser(): ?User
    {
        $email = $this->option('user');
        if ($email !== null && $email !== '') {
            return User::query()->where('email', $email)->first();
        }

        return User::query()
            ->whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))
            ->orderBy('id')
            ->first();
    }

    /* ── Series bootstrap ────────────────────────────────────────────────── */

    /**
     * First cheap pass: firstOrCreate a Series for every DISTINCT non-empty
     * value in the Series column. Permissive by design (the importer resolves
     * Series match-only and would otherwise fail the row) — 'Unknown' included.
     *
     * @param array<int, string> $dedupedHeaders
     * @param array<string, string> $columnMap importer field => header
     * @return int number of Series created
     */
    private function bootstrapSeries(string $file, string $sheet, array $dedupedHeaders, array $columnMap, int $repositoryId, int $limit): int
    {
        $seriesHeader = $columnMap['series'] ?? null;
        if ($seriesHeader === null) {
            return 0;
        }
        $pos = array_search($seriesHeader, $dedupedHeaders, true);
        if ($pos === false) {
            return 0;
        }

        $codes = [];
        foreach ($this->rowWindows($file, $sheet, $limit) as $rawRow) {
            $code = $this->seriesCode($rawRow[$pos] ?? null);
            if ($code !== '') {
                $codes[$code] = true;
            }
        }

        $created = 0;
        foreach (array_keys($codes) as $code) {
            $series = Series::firstOrCreate(
                ['code' => $code],
                [
                    'title' => $code,
                    'is_wills_series' => str_contains(strtolower($code), 'wl'),
                    'is_active' => true,
                    'repository_id' => $repositoryId,
                ],
            );
            if ($series->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /* ── Row assembly ────────────────────────────────────────────────────── */

    /**
     * Turn a positional raw row into the `[dedupedHeader => cell]` array the
     * importer expects, normalising Excel float artefacts on EVERY cell and
     * injecting the ABSOLUTE source-row key (same key the streaming reader uses,
     * so a blank-identifier row's auto id keys on the absolute position).
     *
     * @param array<int, string> $dedupedHeaders
     * @param array<int, mixed> $rawRow
     * @return array<string, mixed>
     */
    private function buildRowArray(array $dedupedHeaders, array $rawRow, int $absRow): array
    {
        $out = [];
        foreach ($dedupedHeaders as $pos => $key) {
            $out[$key] = $this->normaliseCell($rawRow[$pos] ?? null);
        }
        $out[SpreadsheetHeaders::SOURCE_ROW_KEY] = (string) $absRow;

        return $out;
    }

    /** Strip Excel float artefacts ("607.0" → "607") while keeping real text. */
    private function normaliseCell(mixed $v): mixed
    {
        if ($v === null) {
            return null;
        }
        $s = (string) $v;

        return preg_match('/^(\d+)\.0+$/', $s, $m) === 1 ? $m[1] : $v;
    }

    private function rowErrorMessage(\Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            $first = collect($e->errors())->flatten()->first();

            return is_string($first) ? $first : $e->getMessage();
        }

        return $e->getMessage();
    }

    private function printSummary(int $ok, int $failed, array $errSamples, bool $dry): void
    {
        $this->newLine();
        $this->info("Imported {$ok} rows, failed {$failed}." . ($dry ? ' (rolled back)' : ''));
        if ($errSamples !== []) {
            $this->newLine();
            $this->warn('Sample row errors:');
            foreach ($errSamples as $s) {
                $this->line('  • ' . $s);
            }
        }
        if (! $dry) {
            $this->newLine();
            $this->warn('The search index is STALE after a no-sync import. Rebuild it with:');
            $this->line('  php artisan scout:import "App\\Models\\Document"');
        }
    }

    /* ── Selective wipe ──────────────────────────────────────────────────── */

    private function truncateDataTables(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }
        foreach (self::DATA_TABLES as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::table($table)->truncate();
            }
        }
        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /* ── Reading ─────────────────────────────────────────────────────────── */

    private function isCsv(string $file): bool
    {
        return strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'csv';
    }

    /** @return array<int, string|null> */
    private function readHeader(string $file, string $sheet): array
    {
        // CSV is read by streaming (fgetcsv) — memory-safe regardless of size,
        // unlike PhpSpreadsheet which loads the whole workbook and is killed by
        // shared-host (CloudLinux LVE) memory limits on large xlsx files.
        if ($this->isCsv($file)) {
            $fh = fopen($file, 'r');
            $header = $fh ? fgetcsv($fh, escape: '\\') : [];
            if ($fh) {
                fclose($fh);
            }

            return is_array($header) ? $header : [];
        }

        $reader = new XlsxReader;
        $reader->setReadDataOnly(true);
        $reader->setReadFilter(new class implements IReadFilter
        {
            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return $row === 1;
            }
        });
        $ws = $reader->load($file)->getSheetByName($sheet);

        return $ws->rangeToArray('A1:BC1', null, false, false, false)[0];
    }

    /**
     * Yield data rows (0-based positional arrays) in memory-safe windows, KEYED
     * by their ABSOLUTE 1-based sheet row number (header = row 1, first data =
     * row 2). Blank rows are skipped but still consume a row number.
     *
     * @return \Generator<int, array<int, mixed>>
     */
    private function rowWindows(string $file, string $sheet, int $limit): \Generator
    {
        // CSV: stream row-by-row with fgetcsv (constant memory).
        if ($this->isCsv($file)) {
            $fh = fopen($file, 'r');
            if ($fh === false) {
                return;
            }
            fgetcsv($fh, escape: '\\'); // skip header (row 1)
            $lineNo = 1;
            $emitted = 0;
            while (($row = fgetcsv($fh, escape: '\\')) !== false) {
                $lineNo++;
                if ($this->isBlank($row)) {
                    continue;
                }
                yield $lineNo => $row;
                $emitted++;
                if ($limit > 0 && $emitted >= $limit) {
                    break;
                }
            }
            fclose($fh);

            return;
        }

        // True total row count for the sheet WITHOUT loading the data — a
        // per-window getHighestDataRow() only sees the filtered window and would
        // stop the loop after the first 2000 rows.
        $reader = new XlsxReader;
        $reader->setReadDataOnly(true);
        $highest = 0;
        foreach ($reader->listWorksheetInfo($file) as $info) {
            if (($info['worksheetName'] ?? null) === $sheet) {
                $highest = (int) ($info['totalRows'] ?? 0);
                break;
            }
        }
        if ($highest < 2) {
            return;
        }

        $start = 2;
        $emitted = 0;
        while ($start <= $highest) {
            $end = min($start + self::WINDOW - 1, $highest);
            $reader = new XlsxReader;
            $reader->setReadDataOnly(true);
            $reader->setReadFilter(new class($start, $end) implements IReadFilter
            {
                public function __construct(private int $from, private int $to) {}

                public function readCell($columnAddress, $row, $worksheetName = ''): bool
                {
                    return $row >= $this->from && $row <= $this->to;
                }
            });
            $ws = $reader->load($file)->getSheetByName($sheet);
            $rows = $ws->rangeToArray('A' . $start . ':BC' . $end, null, false, false, false);
            unset($ws);

            $rowNo = $start;
            foreach ($rows as $row) {
                if (! $this->isBlank($row)) {
                    yield $rowNo => $row;
                    $emitted++;
                    if ($limit > 0 && $emitted >= $limit) {
                        return;
                    }
                }
                $rowNo++;
            }

            $start = $end + 1;
        }
    }

    /* ── Value helpers ───────────────────────────────────────────────────── */

    private function str(mixed $v): string
    {
        return trim((string) ($v ?? ''));
    }

    private function seriesCode(mixed $v): string
    {
        $s = $this->str($v);
        if ($s === '') {
            return '';
        }

        return substr(trim(explode(':', $s, 2)[0]), 0, 16);
    }

    /** @param array<int, mixed> $row */
    private function isBlank(array $row): bool
    {
        return array_all($row, fn ($c) => ! ($c !== null && trim((string) $c) !== ''));
    }
}
