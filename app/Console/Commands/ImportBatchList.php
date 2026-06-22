<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Accession;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Series;
use App\Support\Import\BatchListColumnMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Header-driven importer for the NAF "New_BATCH_LIST" single-workbook export.
 *
 * Unlike the legacy {@see ImportSampleData} (which read fixed column positions
 * and silently mis-mapped when the NAF added columns), this resolves every
 * column by HEADER NAME via {@see BatchListColumnMap} — the same alias source
 * the Filament Import Wizard uses — so the two import paths stay in sync and a
 * future column shift cannot corrupt the import.
 *
 * Memory-safe: the BATCH_LIST sheet (26k+ rows) is read in row windows via a
 * read filter instead of materialising the whole workbook.
 */
class ImportBatchList extends Command
{
    private const WINDOW = 2000;

    /**
     * Tables emptied by --truncate-data. EXCLUDES users, roles, permissions,
     * model_has_roles, the lookup vocabularies, repositories and custom-field
     * DEFINITIONS — re-importing data must never disturb accounts or config.
     * Order is irrelevant (FK checks are disabled around the truncate).
     *
     * @var array<int, string>
     */
    private const DATA_TABLES = [
        'document_authority', 'accession_batch', 'box_movements',
        'document_location_history', 'document_barcode_history',
        'document_identifier_history', 'box_barcode_history', 'box_seal_number_history',
        'custom_field_values',
        'documents', 'boxes', 'accessions', 'batches', 'series', 'authorities',
    ];

    protected $signature = 'nra:import-batch-list
        {--file= : Path to the New_BATCH_LIST xlsx}
        {--sheet=BATCH_LIST : Worksheet name}
        {--limit=0 : Import at most N data rows (0 = all)}
        {--dry-run : Roll everything back at the end and print a field-level spot check}
        {--truncate-data : Empty ONLY the data tables (documents/boxes/batches/accessions/series/authorities + pivots/history) before importing — NEVER touches users, roles, permissions or lookups}
        {--repo=NRA : Repository code to attach rows to}';

    protected $description = 'Header-driven import of the NAF New_BATCH_LIST workbook (Wizard-consistent column mapping).';

    /** @var array<string, int> */
    private array $idx = [];

    /** @var array<string, array<int, int>> */
    private array $idxAll = [];

    public function handle(): int
    {
        @ini_set('memory_limit', '3G');

        // Bulk import: do not push every row to the search index synchronously
        // (Scout's per-model save hook would dominate the runtime for 26k rows).
        // The CLI process exits afterwards, so disabling for its lifetime is safe;
        // run `scout:import` separately to (re)build the index when needed.
        Document::disableSearchSyncing();
        Authority::disableSearchSyncing();

        $file = $this->option('file')
            ?: base_path('nra/inbox/2026-06-22_NAF_New_BATCH_LIST_04_06_26_sample.xlsx');
        if (! is_file($file)) {
            $this->error("File not found: $file");

            return self::FAILURE;
        }

        $repo = Repository::where('code', $this->option('repo'))->first();
        if ($repo === null) {
            $this->error("Repository '{$this->option('repo')}' not found — seed it first.");

            return self::FAILURE;
        }

        $sheetName = (string) $this->option('sheet');
        $header = $this->readHeader($file, $sheetName);
        $this->idx = BatchListColumnMap::resolve($header);
        $this->idxAll = BatchListColumnMap::resolveAll($header);

        $missing = array_diff(array_keys(BatchListColumnMap::FIELDS), array_keys($this->idx));
        $this->info('Resolved ' . count($this->idx) . '/' . count(BatchListColumnMap::FIELDS) . ' columns by header.');
        if ($missing !== []) {
            $this->warn('Columns not present in this file (ignored): ' . implode(', ', $missing));
        }

        $limit = (int) $this->option('limit');
        $dry = (bool) $this->option('dry-run');

        // Optional selective wipe of the DATA tables only. Never in dry-run.
        // Guarded by a confirmation unless --no-interaction (CI/automation).
        if ($this->option('truncate-data') && ! $dry) {
            $this->warn('--truncate-data will EMPTY: ' . implode(', ', self::DATA_TABLES));
            $this->info('It will NOT touch: users, roles, permissions, lookups, repositories.');
            if ($this->input->isInteractive() && ! $this->confirm('Proceed with truncating the data tables?', false)) {
                $this->info('Aborted — nothing truncated, nothing imported.');

                return self::SUCCESS;
            }
            $this->truncateDataTables();
            $this->info('Data tables truncated (accounts and lookups preserved).');
        }

        $batches = [];
        $series = [];
        $boxes = [];
        $authorities = [];
        $count = 0;
        $skipped = 0;
        $failed = 0;
        $errSamples = [];
        $spot = [];

        DB::disableQueryLog();
        // Real import runs in autocommit so one bad row can't roll back the rest;
        // dry-run wraps everything in a single transaction it rolls back at the end.
        if ($dry) {
            DB::beginTransaction();
        }

        try {
            foreach ($this->rowWindows($file, $sheetName, $limit) as $row) {
                try {
                    $r = $this->mapRow($row);

                    $seriesCode = $this->seriesCode($r['series']);
                    $identifier = $this->numLike($r['identifier']);
                    if ($seriesCode === '' && $identifier === '' && $this->str($r['catalogue_identifier']) === '') {
                        $skipped++;

                        continue;
                    }

                    // Series (required, NOT NULL on documents).
                    if ($seriesCode === '') {
                        $skipped++;

                        continue;
                    }
                    $seriesModel = $series[$seriesCode] ??= Series::firstOrCreate(
                        ['code' => $seriesCode],
                        ['title' => $seriesCode, 'is_wills_series' => str_contains(strtolower($seriesCode), 'wl'), 'is_active' => true, 'repository_id' => $repo->id],
                    );

                    // Batch.
                    $batch = null;
                    $batchNo = $this->parseInt($r['batch_number']);
                    if ($batchNo !== null && ! in_array($batchNo, Batch::FORBIDDEN_NUMBERS, true)) {
                        $batch = $batches[$batchNo] ??= Batch::firstOrCreate(
                            ['batch_number' => $batchNo],
                            [
                                'description' => "Imported batch $batchNo",
                                'type' => $batchNo >= 30 ? 'NOTARY_ACCESSION' : 'MAIN_COLLECTION',
                                'repository_id' => $repo->id,
                            ],
                        );
                    }

                    // Box. A box's physical identity is its (globally-unique) barcode,
                    // so when a barcode is present we dedup on it — the same barcode on
                    // several document rows is ONE box. Only barcode-less rows fall back
                    // to (batch, box_number). BATCH_LIST boxes are structurally RAS.
                    $box = null;
                    $boxNo = $this->numLike($r['box_number']);
                    $barcode = $this->firstBarcode($row);
                    if ($barcode !== '') {
                        $box = $boxes['bc|' . $barcode] ??= Box::firstOrCreate(
                            ['barcode' => $barcode],
                            [
                                'box_number' => $boxNo ?: '1',
                                'batch_id' => $batch?->id,
                                'box_type' => 'RAS',
                                'barcode_status' => 'IN',
                                'seal_number' => $this->str($r['seal_number']) ?: null,
                            ],
                        );
                    } elseif ($batch !== null && $boxNo !== '') {
                        $box = $boxes["bb|{$batch->id}|{$boxNo}"] ??= Box::firstOrCreate(
                            ['box_number' => $boxNo, 'batch_id' => $batch->id],
                            [
                                'box_type' => 'RAS',
                                'barcode_status' => 'IN',
                                'seal_number' => $this->str($r['seal_number']) ?: null,
                            ],
                        );
                    }

                    // Accession (rare in this file, but supported).
                    $accession = null;
                    $accCode = $this->str($r['accession']);
                    if ($accCode !== '') {
                        $accession = Accession::firstOrCreate(['code' => $accCode], ['repository_id' => $repo->id]);
                        if ($batch !== null) {
                            $accession->batches()->syncWithoutDetaching([$batch->id]);
                        }
                    }

                    [$ys, $ye] = $this->parseYearRange($this->str($r['dates']));
                    $disinfest = $this->firstDisinfestation($row);

                    $doc = new Document([
                        'identifier' => $identifier ?: ($this->str($r['catalogue_identifier']) ?: 'AUTO-' . ($count + 2)),
                        'document_type' => $this->str($r['document_type']) ?: null,
                        'series_id' => $seriesModel->id,
                        'accession_id' => $accession?->id,
                        'current_box_id' => $box?->id,
                        'batch_id' => $batch?->id,
                        'repository_id' => $repo->id,
                        'volume_number' => $this->numLike($r['volume_number']) ?: null,
                        'part_number' => $this->str($r['part_number']) ?: null,
                        'dates' => $this->str($r['dates']) ?: null,
                        'dates_year_start' => $ys,
                        'dates_year_end' => $ye,
                        'deeds' => $this->str($r['deeds']) ?: null,
                        'practice' => $this->str($r['practice']) ?: null,
                        'current_box_type' => $this->normaliseBoxType($this->str($r['current_box_type'])),
                        'disinfestation_date' => $disinfest,
                        'catalogue_identifier' => $this->str($r['catalogue_identifier']) ?: null,
                        'nra_location' => $this->str($r['nra_location']) ?: null,
                        'museum_location' => $this->str($r['museum_location']) ?: null,
                        'digitised' => $this->normaliseDigitised($this->str($r['digitised'])),
                        'torre' => $this->str($r['torre']) !== '',
                        'object_reference_number' => $this->str($r['object_reference_number']) ?: null,
                        'tracking' => $this->str($r['tracking']) ?: null,
                        'museum_reference' => $this->str($r['museum_reference']) ?: null,
                        'notes' => $this->str($r['note']) ?: null,
                        'extra' => [
                            'legacy_creator_text' => $this->str($r['creator']),
                            'accession_type' => $this->str($r['accession_type']),       // NAF "Type"
                            'prev_identifier' => $this->str($r['prev_identifier']),
                            'prev_volume' => $this->str($r['prev_volume']),
                            'citation_reference' => $this->str($r['citation_reference']),
                        ],
                    ]);
                    $doc->save();

                    // Authority: the legacy "Actual Identifier" number maps 1:1 to
                    // "R<num>". Create it from the Creator name when it does not yet
                    // exist (this workbook has no separate Authorities sheet), so the
                    // notary link is populated end-to-end.
                    if ($identifier !== '' && ctype_digit($identifier)) {
                        $authId = 'R' . $identifier;
                        $authority = $authorities[$authId] ??= $this->resolveAuthority($authId, $this->str($r['creator']));
                        $doc->authorities()->syncWithoutDetaching([$authority->id => ['is_primary' => true]]);
                    }

                    if ($count < 8) {
                        $spot[] = sprintf(
                            'id=%s series=%s docType=%s vol=%s batch=%s box=%s notary=%s',
                            $doc->identifier,
                            $seriesCode,
                            $doc->document_type ?? '—',
                            $doc->volume_number ?? '—',
                            $batchNo ?? '—',
                            $boxNo ?: '—',
                            $this->str($r['creator']) ?: '—',
                        );
                    }

                    $count++;
                    if ($count % 1000 === 0) {
                        $this->info("  … {$count} documents");
                    }
                } catch (\Throwable $rowErr) {
                    // Real NAF data is messy — skip the bad row, keep going.
                    $failed++;
                    if (count($errSamples) < 8) {
                        $errSamples[] = substr($rowErr->getMessage(), 0, 160);
                    }
                }
            }

            if ($dry) {
                DB::rollBack();
                $this->warn('DRY RUN — all changes rolled back.');
            }
        } catch (\Throwable $e) {
            if ($dry) {
                DB::rollBack();
            }
            $this->error('Import aborted: ' . $e->getMessage());
            $this->line($e->getFile() . ':' . $e->getLine());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info("Imported $count documents, skipped $skipped (empty), failed $failed (row errors)." . ($dry ? ' (rolled back)' : ''));
        if ($errSamples !== []) {
            $this->newLine();
            $this->warn('Sample row errors:');
            foreach ($errSamples as $s) {
                $this->line('  • ' . $s);
            }
        }
        $this->newLine();
        $this->line('Field-level spot check (first rows):');
        foreach ($spot as $s) {
            $this->line('  ' . $s);
        }

        return self::SUCCESS;
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
            $header = $fh ? fgetcsv($fh) : [];
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
     * Yield data rows (0-based arrays) in memory-safe windows.
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
            fgetcsv($fh); // skip header
            $emitted = 0;
            while (($row = fgetcsv($fh)) !== false) {
                if ($this->isBlank($row)) {
                    continue;
                }
                yield $row;
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

            foreach ($rows as $row) {
                if ($this->isBlank($row)) {
                    continue;
                }
                yield $row;
                $emitted++;
                if ($limit > 0 && $emitted >= $limit) {
                    return;
                }
            }

            $start = $end + 1;
        }
    }

    /* ── Row mapping ─────────────────────────────────────────────────────── */

    /**
     * @param array<int, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $out = [];
        foreach (BatchListColumnMap::FIELDS as $field => $_) {
            $i = $this->idx[$field] ?? null;
            $out[$field] = $i !== null ? ($row[$i] ?? null) : null;
        }

        return $out;
    }

    /** @param array<int, mixed> $row */
    private function firstBarcode(array $row): string
    {
        foreach ($this->idxAll['barcode_in'] ?? [] as $i) {
            $v = $this->str($row[$i] ?? null);
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }

    /** @param array<int, mixed> $row */
    private function firstDisinfestation(array $row): ?string
    {
        foreach ($this->idxAll['disinfestation_date'] ?? [] as $i) {
            $d = $this->parseDate($row[$i] ?? null);
            if ($d !== null) {
                return $d;
            }
        }

        return null;
    }

    /* ── Value helpers ───────────────────────────────────────────────────── */

    private function str(mixed $v): string
    {
        return trim((string) ($v ?? ''));
    }

    /** Strip Excel float artefacts ("574.0" → "574") while keeping real text. */
    private function numLike(mixed $v): string
    {
        $s = $this->str($v);
        if ($s !== '' && preg_match('/^(\d+)\.0+$/', $s, $m)) {
            return $m[1];
        }

        return $s;
    }

    /**
     * Find or create the notary Authority for an "R<num>" identifier, naming it
     * from the free-text Creator ("Edwina Brincat" → given "Edwina", surname
     * "Brincat"). Default entity type Notary (NAF spec). "Unknown" → no name.
     */
    private function resolveAuthority(string $identifier, string $creator): Authority
    {
        $given = null;
        $surname = $identifier;
        $name = trim($creator);
        if ($name !== '' && strcasecmp($name, 'Unknown') !== 0) {
            $parts = preg_split('/\s+/', $name) ?: [];
            if (count($parts) === 1) {
                $surname = $parts[0];
            } else {
                $given = array_shift($parts);
                $surname = implode(' ', $parts);
            }
        }

        return Authority::firstOrCreate(
            ['identifier' => $identifier],
            ['surname' => $surname, 'given_names' => $given, 'entity_type' => 'Notary'],
        );
    }

    private function seriesCode(mixed $v): string
    {
        $s = $this->str($v);
        if ($s === '') {
            return '';
        }

        return substr(trim(explode(':', $s, 2)[0]), 0, 16);
    }

    private function normaliseBoxType(string $v): ?string
    {
        if ($v === '') {
            return null;
        }

        return str_contains(strtolower($v), 'ras') ? 'RAS Box'
            : (str_contains(strtolower($v), 'big') ? 'Big Brown Box'
                : (str_contains(strtolower($v), 'small') ? 'Small Brown Box' : null));
    }

    private function normaliseDigitised(string $v): ?string
    {
        $v = strtolower($v);
        if ($v === '') {
            return null;
        }

        return str_contains($v, 'vhmml') ? 'VHMML' : (str_contains($v, 'nra') ? 'NRA' : 'none');
    }

    /** @param array<int, mixed> $row */
    private function isBlank(array $row): bool
    {
        foreach ($row as $c) {
            if ($c !== null && trim((string) $c) !== '') {
                return false;
            }
        }

        return true;
    }

    private function parseInt(mixed $v): ?int
    {
        $s = $this->str($v);
        if ($s === '') {
            return null;
        }
        if (is_numeric($s)) {
            return (int) $s;
        }

        return preg_match('/^\s*(\d+)/', $s, $m) ? (int) $m[1] : null;
    }

    private function parseDate(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $v)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }
        $ts = strtotime((string) $v);

        return $ts ? date('Y-m-d', $ts) : null;
    }

    /** @return array{0: int|null, 1: int|null} */
    private function parseYearRange(?string $s): array
    {
        if (! $s) {
            return [null, null];
        }
        if (preg_match('/(\d{4})\s*[-–]\s*(\d{4})/', $s, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        if (preg_match('/(\d{4})/', $s, $m)) {
            return [(int) $m[1], (int) $m[1]];
        }

        return [null, null];
    }
}
