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
use Laravel\Telescope\Telescope;
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

        // BLOCKER 2 (dominant leak) — Telescope is registered in the local env
        // and queues every query, event and log entry in memory for the whole
        // command. Over 26k rows that alone grows to gigabytes. Stop recording
        // for the duration of the import (no-op when Telescope isn't installed,
        // e.g. a --no-dev production build).
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }

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

        // FAILURE VISIBILITY — opened inside the try (below), closed in finally.
        // Declared here so finally can close it even if the run aborts mid-way.
        $failCsv = false;

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

            // FAILURE VISIBILITY — stream every failed row to a CSV (O(1) memory)
            // and keep a BOUNDED grouped counter in memory (keyed by exception
            // class + a digit/quote-masked message template) for the summary.
            $failCsvPath = storage_path('logs/import-batch-list-failures-' . date('YmdHis') . '.csv');
            $failCsv = @fopen($failCsvPath, 'w');
            if ($failCsv !== false) {
                fputcsv($failCsv, ['abs_row', 'identifier', 'exception_class', 'message'], escape: '\\');
            }
            $errGroups = [];

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
                    $message = $this->rowErrorMessage($rowErr);
                    if ($failCsv !== false) {
                        fputcsv(
                            $failCsv,
                            [$absRow, $this->rowIdentifierCell($rowArray), $rowErr::class, $message],
                            escape: '\\',
                        );
                    }
                    $this->recordErrorGroup($errGroups, $rowErr::class, $message);

                    // Unwind any half-open per-row savepoint back to the base
                    // level so the next row starts clean.
                    while (DB::transactionLevel() > $baseTx) {
                        DB::rollBack();
                    }

                    // BLOCKER 2 — a failed row never reaches persistRowSideEffects()
                    // to drain its per-row stashes, and spl_object_id reuse would
                    // then leak them onto a LATER row (a correctness hazard, not
                    // just a leak). The rollback above can also destroy a batch/box
                    // that a resolver just created AND memoised, leaving a stale id
                    // for later rows. Flush both so the next row starts truly clean.
                    DocumentImporter::flushRowStashes();
                    EntityResolver::flushMemo();
                }

                if (($ok + $failed) % 1000 === 0) {
                    $this->info(
                        '  … ' . ($ok + $failed) . " rows ({$ok} ok, {$failed} failed)"
                        . ' — mem ' . $this->formatMb(memory_get_usage(true))
                    );
                }

                // BLOCKER 2 — reclaim per-window memory: drop the FK-resolution
                // memo (every create path is lookup-first + firstOrCreate, so a
                // post-flush re-lookup finds the earlier-created row in the DB)
                // and run the cycle collector. WINDOW-aligned so it costs O(1) per
                // window, not per row.
                if (($ok + $failed) % self::WINDOW === 0) {
                    EntityResolver::flushMemo();
                    gc_collect_cycles();
                }
            }

            if ($dry) {
                DB::rollBack();
                $this->warn('DRY RUN — all changes rolled back.');
            }

            $this->printSummary($ok, $failed, $errGroups, $failCsvPath, $dry);

            return self::SUCCESS;
        } catch (\Throwable $throwable) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->error('Import aborted: ' . $throwable->getMessage());
            $this->line($throwable->getFile() . ':' . $throwable->getLine());

            return self::FAILURE;
        } finally {
            if ($failCsv !== false) {
                fclose($failCsv);
            }
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

    /**
     * Identifier cell for the failure CSV: the 'Identifier' or, failing that,
     * the 'Catalogue Identifier' header cell of the raw row, or '' when neither
     * carries a value.
     *
     * @param array<string, mixed> $rowArray dedupedHeader => cell
     */
    private function rowIdentifierCell(array $rowArray): string
    {
        foreach (['Identifier', 'Catalogue Identifier'] as $header) {
            $value = trim((string) ($rowArray[$header] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Fold a raw error message into a stable TEMPLATE: quoted substrings and
     * runs of digits are replaced with '*' so rows that differ only by an
     * id/value/quoted-value collapse into one group.
     */
    private function errorTemplate(string $message): string
    {
        $template = preg_replace('/([\'"]).*?\1/s', '$1*$1', $message) ?? $message;
        $template = preg_replace('/\d+/', '*', $template) ?? $template;

        return mb_substr(trim($template), 0, 200);
    }

    /**
     * Increment the bounded grouped failure counter (keyed by exception class +
     * message template), keeping the first raw sample per group. Bounded: once
     * a safety cap of distinct templates is reached, further novel templates are
     * folded into a single overflow bucket so memory stays O(1) even on a
     * pathological input with unique messages per row.
     *
     * @param array<string, array{count:int, class:string, template:string, sample:string}> $groups
     */
    private function recordErrorGroup(array &$groups, string $class, string $message): void
    {
        $template = $this->errorTemplate($message);
        $key = $class . '|' . $template;

        if (! isset($groups[$key]) && count($groups) >= 500) {
            $key = $class . '|(other)';
            $template = '(other — distinct-template cap reached)';
        }

        if (! isset($groups[$key])) {
            $groups[$key] = ['count' => 0, 'class' => $class, 'template' => $template, 'sample' => $message];
        }
        $groups[$key]['count']++;
    }

    /** Human-readable "N.N MB" for a byte count. */
    private function formatMb(int $bytes): string
    {
        return number_format($bytes / 1048576, 1) . ' MB';
    }

    /**
     * @param array<string, array{count:int, class:string, template:string, sample:string}> $errGroups
     */
    private function printSummary(int $ok, int $failed, array $errGroups, ?string $failCsvPath, bool $dry): void
    {
        $this->newLine();
        $this->info("Imported {$ok} rows, failed {$failed}." . ($dry ? ' (rolled back)' : ''));

        if ($errGroups !== []) {
            uasort($errGroups, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);
            $this->newLine();
            $this->warn('Failure groups (count × error template, most frequent first):');
            foreach ($errGroups as $group) {
                $this->line('  ' . $group['count'] . ' × ' . $group['class'] . ': ' . $group['template']);
            }
        }

        if ($failCsvPath !== null) {
            $this->newLine();
            $this->line('Per-row failure detail (abs_row, identifier, class, message): ' . $failCsvPath);
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
            $spreadsheet = $reader->load($file);
            $ws = $spreadsheet->getSheetByName($sheet);
            $rows = $ws->rangeToArray('A' . $start . ':BC' . $end, null, false, false, false);
            // BLOCKER 2 — free PhpSpreadsheet's whole cell graph for this window
            // before moving on; rangeToArray has already materialised the data.
            $spreadsheet->disconnectWorksheets();
            unset($ws, $spreadsheet);

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
