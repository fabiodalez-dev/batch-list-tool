<?php

namespace App\Console\Commands;

use App\Models\Accession;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Series;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class ImportSampleData extends Command
{
    protected $signature = 'nra:import-samples {--path=/Users/fabio/Desktop/Batch_List_Tool/samples : Folder containing the 3 sample xlsx files} {--fresh : Run migrate:fresh before importing}';

    protected $description = 'Import the 3 RFQ-2026-06 sample spreadsheets (Series, Authorities, Batch List) into the new Laravel schema.';

    public function handle(): int
    {
        $path = rtrim($this->option('path'), '/');
        if (! is_dir($path)) {
            $this->error("Sample folder not found: $path");
            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->warn('Running migrate:fresh --seed before import ...');
            $this->call('migrate:fresh', ['--force' => true, '--seed' => true]);
        }

        $repo = Repository::where('code', 'NRA')->firstOrFail();

        $this->importSeries("$path/Series_Sample.xlsx");
        $this->importAuthorities("$path/Authorities_Sample.xlsx");
        $this->importBatchList("$path/Batch_List_Sample.xlsx", $repo);

        $this->info('');
        $this->info('═══════════════════════════════════════════════════');
        $this->info(' Final row counts:');
        $this->info('   Series:      ' . Series::count());
        $this->info('   Authorities: ' . Authority::count());
        $this->info('   Batches:     ' . Batch::count());
        $this->info('   Boxes:       ' . Box::count());
        $this->info('   Accessions:  ' . Accession::count());
        $this->info('   Documents:   ' . Document::count());
        $this->info('═══════════════════════════════════════════════════');

        return self::SUCCESS;
    }

    private function loadSheet(string $file)
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        return $reader->load($file)->getActiveSheet();
    }

    private function importSeries(string $file): void
    {
        $this->info("Importing Series from {$file} ...");
        $sheet = $this->loadSheet($file);
        $rows  = $sheet->toArray(null, false, false, false);
        $count = 0;

        DB::beginTransaction();
        try {
            foreach (array_slice($rows, 1) as $row) {
                // Headers: A blank label, B Identifier, C Title, D Level
                $level      = $row[3] ?? null;
                $identifier = trim((string) ($row[1] ?? ''));
                $title      = trim((string) ($row[2] ?? ''));
                if ($identifier === '' || stripos((string) $level, 'series') === false) {
                    continue;
                }
                Series::updateOrCreate(
                    ['code' => $identifier],
                    [
                        'title'           => $title ?: $identifier,
                        'is_wills_series' => str_contains(strtolower($identifier), 'wl') || str_contains(strtolower($title), 'will'),
                        'is_active'       => true,
                    ]
                );
                $count++;
            }
            DB::commit();
            $this->info("  → $count Series imported.");
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function importAuthorities(string $file): void
    {
        $this->info("Importing Authorities from {$file} ...");
        $sheet = $this->loadSheet($file);
        $rows  = $sheet->toArray(null, false, false, false);
        $count = 0;

        DB::beginTransaction();
        try {
            foreach (array_slice($rows, 1) as $row) {
                // A Identifier, B Alt Id, C Type, D Practice Dates, H Surname, I Name
                $identifier = trim((string) ($row[0] ?? ''));
                if ($identifier === '') {
                    continue;
                }
                $surname = trim((string) ($row[7] ?? ''));
                $given   = trim((string) ($row[8] ?? ''));
                if ($surname === '' && $given === '') {
                    $surname = $identifier;
                }

                [$start, $end] = $this->parseYearRange((string) ($row[3] ?? ''));

                Authority::updateOrCreate(
                    ['identifier' => $identifier],
                    [
                        'alternative_identifier' => trim((string) ($row[1] ?? '')) ?: null,
                        'surname'                => $surname,
                        'given_names'            => $given ?: null,
                        'entity_type'            => strtoupper(trim((string) ($row[2] ?? ''))) === 'PERSON' ? 'PERSON' : 'INSTITUTION',
                        'practice_dates_start'   => $start,
                        'practice_dates_end'     => $end,
                    ]
                );
                $count++;
            }
            DB::commit();
            $this->info("  → $count Authorities imported.");
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function importBatchList(string $file, Repository $repo): void
    {
        $this->info("Importing Batch List from {$file} ...");
        $sheet = $this->loadSheet($file);
        $rows  = $sheet->toArray(null, false, false, false);
        $count = 0;
        $skipped = 0;

        $batches = [];
        $boxes = [];
        $accessions = [];

        DB::disableQueryLog();
        DB::beginTransaction();
        try {
            foreach (array_slice($rows, 1) as $rowIdx => $row) {
                $batch1Num  = $this->parseInt($row[0] ?? null);
                $box1Num    = trim((string) ($row[1] ?? ''));
                $batch2Num  = $this->parseInt($row[2] ?? null);
                $box2Num    = trim((string) ($row[3] ?? ''));
                $inSitu1    = trim((string) ($row[4] ?? ''));
                $barcodeIn  = trim((string) ($row[12] ?? ''));
                $disinfest1 = $this->parseDate($row[27] ?? null);
                $identifier = trim((string) ($row[27] ?? '') ?: ($row[28] ?? ''));
                // Column "Identifier" is index "b" → 27 (0-based), so use 27 if non-numeric date, else 28
                $identifier = trim((string) ($row[27] ?? ''));
                // Actually based on headers given: column index for "Identifier" header was 27 (= column 'b' 0-based 27)
                // Headers were: A=RAS Batch 1 (0), B=RAS Box 1 (1), C=RAS Batch 2 (2), D=RAS Box 2 (3),
                // E=In Situ Box 1 (4), F=In Situ Box 2 (5), G=In Situ Box 3 (6), H=RAS 1 Destroyed (7),
                // I=RAS 2 Destroyed (8), J=In Situ 1 Destroyed (9), K=In Situ 2 Destroyed (10), L=In Situ 3 Destroyed (11),
                // M=Barcode (IN) (12), N=Barcode RAS 1 (13), O=Status 1 (14), P=Barcode RAS 2 (15), Q=Status 2 (16),
                // R=Barcode RAS 3 (17), S=Status 3 (18), T=Barcode RAS 4 (19), U=Status 4 (20),
                // V=Barcode (IN) (21), W=Barcode RAS 2 (22), X=Status 1 (23), Y=Barcode RAS 2 (24), Z=Status 2 (25),
                // [=Seal Number (26), \=Disinfestation Date (27), ]=Disinfestation Date (28), ^=Disinfestation Date (29),
                // _=Catalogue Identifier (30), `=NRA Location (31), a=Museum Location (32), b=Identifier (33),
                // c=Practice (34), d=Volume (35), e=Creator (36), f=Dates (37), g=Deeds (38), h=Document Type (39),
                // i=Series (40), j=Current Box (41), k=Note (42), l=Digitised (43), m=Torre (44),
                // n=Accession (45), o=Object Reference Number (46), p=Tracking (47), q=Museum Reference (48)

                $identifier  = trim((string) ($row[33] ?? ''));
                $volume      = trim((string) ($row[35] ?? ''));
                $creatorStr  = trim((string) ($row[36] ?? ''));
                $datesStr    = trim((string) ($row[37] ?? ''));
                $docType     = trim((string) ($row[39] ?? ''));
                $seriesCodeRaw = trim((string) ($row[40] ?? ''));
                // Batch List spreadsheet uses full title format "REG: Registers Private Practice".
                // Extract just the code before ":" (max 16 chars to match schema).
                $seriesCode  = substr(trim(explode(':', $seriesCodeRaw, 2)[0]), 0, 16);
                $currentBox  = trim((string) ($row[41] ?? ''));
                $note        = trim((string) ($row[42] ?? ''));
                $accessionCd = trim((string) ($row[45] ?? ''));
                $catalogueId = trim((string) ($row[30] ?? ''));
                $disinfestDate = $this->parseDate($row[27] ?? null) ?? $this->parseDate($row[28] ?? null);

                if ($identifier === '' && $catalogueId === '' && $seriesCode === '') {
                    $skipped++;
                    continue;
                }

                // Batch lookup/create
                $batch = null;
                if ($batch1Num !== null && ! in_array($batch1Num, Batch::FORBIDDEN_NUMBERS, true)) {
                    $batch = $batches[$batch1Num] ??= Batch::firstOrCreate(
                        ['batch_number' => $batch1Num],
                        [
                            'description'   => "Imported batch $batch1Num",
                            'type'          => $batch1Num >= 30 ? 'NOTARY_ACCESSION' : 'MAIN_COLLECTION',
                            'repository_id' => $repo->id,
                        ]
                    );
                }

                // Series lookup
                $series = null;
                if ($seriesCode !== '') {
                    $series = Series::firstOrCreate(
                        ['code' => $seriesCode],
                        [
                            'title'           => $seriesCode,
                            'is_wills_series' => str_contains(strtolower($seriesCode), 'wl'),
                            'is_active'       => true,
                        ]
                    );
                }
                if (! $series) {
                    // Without a series we'd violate NOT NULL — skip
                    $skipped++;
                    continue;
                }

                // Box lookup/create (RAS box 1 if present)
                $box = null;
                if ($batch !== null && $box1Num !== '') {
                    $boxKey = "{$batch->id}|{$box1Num}|RAS";
                    $box = $boxes[$boxKey] ??= Box::firstOrCreate(
                        ['box_number' => $box1Num, 'batch_id' => $batch->id, 'box_type' => 'RAS'],
                        [
                            'barcode'        => $barcodeIn ?: null,
                            'barcode_status' => 'IN',
                        ]
                    );
                }

                // Accession lookup/create
                $accession = null;
                if ($accessionCd !== '') {
                    $accession = $accessions[$accessionCd] ??= Accession::firstOrCreate(
                        ['code' => $accessionCd],
                        ['repository_id' => $repo->id, 'batch_id' => $batch?->id]
                    );
                }

                [$yearStart, $yearEnd] = $this->parseYearRange($datesStr);

                Document::create([
                    // Normalised
                    'identifier'        => $identifier ?: $catalogueId ?: 'AUTO-' . ($rowIdx + 2),
                    'document_type'     => $docType ?: null,
                    'series_id'         => $series->id,
                    'accession_id'      => $accession?->id,
                    'current_box_id'    => $box?->id,
                    'batch_id'          => $batch?->id,
                    'repository_id'     => $repo->id,
                    'volume_label'      => $volume ?: null,
                    'dates_year_start'  => $yearStart,
                    'dates_year_end'    => $yearEnd,
                    'disinfestation_date' => $disinfestDate,
                    'notes'             => $note ?: null,
                    'extra'             => [
                        'legacy_creator_text' => $creatorStr,
                    ],
                    // Legacy POC columns (1:1 with raw PHP schema)
                    'ras_batch_1'             => $row[0] !== null ? (string) $row[0] : null,
                    'ras_box_1'               => $box1Num ?: null,
                    'ras_batch_2'             => $row[2] !== null ? (string) $row[2] : null,
                    'ras_box_2'               => $box2Num ?: null,
                    'in_situ_box_1'           => $inSitu1 ?: null,
                    'in_situ_box_2'           => trim((string) ($row[5] ?? '')) ?: null,
                    'in_situ_box_3'           => trim((string) ($row[6] ?? '')) ?: null,
                    'ras_1_box_destroyed'     => trim((string) ($row[7] ?? '')) ?: null,
                    'ras_2_box_destroyed'     => trim((string) ($row[8] ?? '')) ?: null,
                    'in_situ_box_1_destroyed' => trim((string) ($row[9] ?? '')) ?: null,
                    'in_situ_box_2_destroyed' => trim((string) ($row[10] ?? '')) ?: null,
                    'in_situ_box_3_destroyed' => trim((string) ($row[11] ?? '')) ?: null,
                    'barcode_in'              => $barcodeIn ?: null,
                    'barcode_ras_1'           => trim((string) ($row[13] ?? '')) ?: null,
                    'status_1'                => trim((string) ($row[14] ?? '')) ?: null,
                    'barcode_ras_2'           => trim((string) ($row[15] ?? '')) ?: null,
                    'status_2'                => trim((string) ($row[16] ?? '')) ?: null,
                    'barcode_ras_3'           => trim((string) ($row[17] ?? '')) ?: null,
                    'status_3'                => trim((string) ($row[18] ?? '')) ?: null,
                    'barcode_ras_4'           => trim((string) ($row[19] ?? '')) ?: null,
                    'status_4'                => trim((string) ($row[20] ?? '')) ?: null,
                    'barcode_in_2'            => trim((string) ($row[21] ?? '')) ?: null,
                    'barcode_ras_2_alt'       => trim((string) ($row[22] ?? '')) ?: null,
                    'status_1_alt'            => trim((string) ($row[23] ?? '')) ?: null,
                    'barcode_ras_2_alt2'      => trim((string) ($row[24] ?? '')) ?: null,
                    'status_2_alt'            => trim((string) ($row[25] ?? '')) ?: null,
                    'seal_number'             => trim((string) ($row[26] ?? '')) ?: null,
                    'disinfestation_date_1'   => $this->parseDate($row[27] ?? null),
                    'disinfestation_date_2'   => $this->parseDate($row[28] ?? null),
                    'disinfestation_date_3'   => $this->parseDate($row[29] ?? null),
                    'catalogue_identifier'    => $catalogueId ?: null,
                    'nra_location'            => trim((string) ($row[31] ?? '')) ?: null,
                    'museum_location'         => trim((string) ($row[32] ?? '')) ?: null,
                    'practice'                => trim((string) ($row[34] ?? '')) ?: null,
                    'dates'                   => $datesStr ?: null,
                    'deeds'                   => trim((string) ($row[38] ?? '')) ?: null,
                    'current_box_type'        => null,                                              // not in sample
                    'colour_code'             => null,                                              // not in sample
                    'digitised'               => trim((string) ($row[43] ?? '')) ?: null,
                    'torre'                   => $this->parseBool($row[44] ?? null),
                    'accession_code_legacy'   => $accessionCd ?: null,
                    'object_reference_number' => trim((string) ($row[46] ?? '')) ?: null,
                    'tracking'                => trim((string) ($row[47] ?? '')) ?: null,
                    'museum_reference'        => trim((string) ($row[48] ?? '')) ?: null,
                ]);

                $count++;
                if ($count % 500 === 0) {
                    $this->info("  … {$count} documents imported");
                }
            }
            DB::commit();
            $this->info("  → $count Documents imported, $skipped skipped.");
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function parseBool(mixed $v): bool
    {
        if ($v === null || $v === '') return false;
        $s = strtolower(trim((string) $v));
        return in_array($s, ['1', 'yes', 'y', 'true', 't', 'si', 'sì'], true);
    }

    private function parseInt(mixed $v): ?int
    {
        if ($v === null || $v === '') return null;
        if (is_numeric($v)) return (int) $v;
        if (preg_match('/^\s*(\d+)/', (string) $v, $m)) return (int) $m[1];
        return null;
    }

    private function parseDate(mixed $v): ?string
    {
        if ($v === null || $v === '') return null;
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

    private function parseYearRange(?string $s): array
    {
        if (! $s) return [null, null];
        if (preg_match('/(\d{4})\s*[-–]\s*(\d{4})/', $s, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        if (preg_match('/(\d{4})/', $s, $m)) {
            return [(int) $m[1], (int) $m[1]];
        }
        return [null, null];
    }
}
