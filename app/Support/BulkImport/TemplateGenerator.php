<?php

declare(strict_types=1);

namespace App\Support\BulkImport;

use App\Filament\Imports\BatchImporter;
use App\Filament\Imports\BoxImporter;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RFQ §3.1.3 — Generate blank .xlsx templates whose **column headers match
 * the legacy sample files** byte-for-byte (strings, ordering, and duplicates).
 *
 * Why this lives next to {@see EntityResolver}:
 *
 * The operator's brief was unambiguous — the spreadsheet column headers in
 * the official samples (`Authorities_Sample.xlsx`, `Series_Sample.xlsx`,
 * `Batch_List_Sample.xlsx`) are the contract. Operators have spent years
 * copy/pasting into those exact columns; the template MUST preserve them
 * verbatim so existing muscle memory keeps working.
 *
 * Two awkward edge cases the legacy schema imposes on us:
 *
 *   1. `Series_Sample.xlsx` has 26 columns in its header row but only the
 *      first 6 are populated — the rest are stray NULL cells dragged in by
 *      whoever first saved the file in Excel. We trim trailing nulls so the
 *      generated template doesn't ship 20 empty column slots.
 *
 *   2. `Batch_List_Sample.xlsx` has 57 columns of which 49 are populated,
 *      and several header strings are **legitimately duplicated** at the
 *      same byte position — multi-step provenance tracking:
 *
 *          [13] "Barcode (IN)"        [22] "Barcode (IN)"
 *          [15] "Status 1"            [24] "Status 1"
 *          [16] "Barcode RAS 2"       [23] "Barcode RAS 2"   [25] "Barcode RAS 2"
 *          [17] "Status 2"            [26] "Status 2"
 *          [28] "Disinfestation Date" [29] "Disinfestation Date" [30] "Disinfestation Date"
 *
 *      Those duplicates are part of the legacy schema — they encode
 *      "first barcode at acquisition" vs. "barcode after first RAS move" vs.
 *      "barcode after second move", and the operator's eye reads them by
 *      column position, not by name. The template MUST preserve them at the
 *      same positions. PhpSpreadsheet has no problem writing duplicate
 *      header strings; we just write them straight through.
 *
 * Batch and Box have no dedicated legacy sample (those concepts were buried
 * inside Batch_List_Sample as repeated columns). For those two we
 * synthesise a header row that matches the corresponding Filament Importer
 * 1:1 — so an operator can download → fill → re-upload without any column
 * remapping in the import modal.
 */
final class TemplateGenerator
{
    /**
     * Where the legacy sample files live. Read-only — never write here.
     * Kept as a class constant so tests can introspect without parsing
     * the source.
     */
    public const SAMPLES_DIR = '/Users/fabio/Desktop/Batch_List_Tool/samples';

    /**
     * Generator version — embedded in the hidden metadata sheet so that
     * if we ever break the contract (rename columns, reorder, etc.) we
     * can detect a stale template at re-upload time and warn the operator.
     * Bump on any change to the header contract.
     */
    public const GENERATOR_VERSION = '1.0.0';

    /**
     * Per-entity descriptor table. `source` is the legacy sample whose
     * row-1 we copy verbatim; `synthesise` is a callable returning the
     * header list when no sample exists for the entity (Batch / Box).
     *
     * Kept public so the wizard page and tests can read the same source
     * of truth without duplicating literals.
     *
     * @var array<string, array{
     *     source?: string,
     *     sheet?: int,
     *     header_row?: int,
     *     synthesise?: callable():array<int, string>,
     * }>
     */
    public const TEMPLATES = [
        'authority' => [
            'source' => 'Authorities_Sample.xlsx',
            'sheet' => 0,
            'header_row' => 1,
        ],
        'series' => [
            'source' => 'Series_Sample.xlsx',
            'sheet' => 0,
            'header_row' => 1,
        ],
        'batch' => [
            // No legacy sample; synthesised from BatchImporter columns + RFQ.
        ],
        'box' => [
            // No legacy sample; synthesised from BoxImporter columns + Box::TYPES.
        ],
        'location' => [
            // No legacy sample; synthesised from LocationImporter columns + Location::TYPES.
        ],
        'document' => [
            'source' => 'Batch_List_Sample.xlsx',
            'sheet' => 0,
            'header_row' => 1,
        ],
    ];

    /**
     * Entry point — used by Filament Action `->action(fn () => TemplateGenerator::download('authority'))`.
     *
     * Streams the binary xlsx back to the browser with the correct
     * Content-Type and Content-Disposition. Never buffers in memory beyond
     * the single workbook (≤50 KB at the largest — the Document template
     * with 49 header cells is well under 20 KB).
     *
     * @throws \InvalidArgumentException when $entity is not one of the keys in {@see TEMPLATES}.
     */
    public static function download(string $entity): StreamedResponse
    {
        if (! array_key_exists($entity, self::TEMPLATES)) {
            throw new \InvalidArgumentException("Unknown template entity: {$entity}");
        }

        $headers = self::headersFor($entity);
        $spreadsheet = self::buildSpreadsheet($entity, $headers);

        $filename = sprintf('%s_template_%s.xlsx', $entity, now()->format('Y-m-d'));

        return new StreamedResponse(
            function () use ($spreadsheet): void {
                // Stream the .xlsx straight to PHP's output buffer so even
                // a 50k-row template (rare for headers-only, but possible
                // if a future caller pre-fills sample rows) is memory-safe.
                $writer = new XlsxWriter($spreadsheet);
                $writer->save('php://output');
            },
            200,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    /**
     * Return the ordered header list for an entity. Public so tests and the
     * wizard can inspect without round-tripping through xlsx.
     *
     * @return array<int, string>
     */
    public static function headersFor(string $entity): array
    {
        if (! array_key_exists($entity, self::TEMPLATES)) {
            throw new \InvalidArgumentException("Unknown template entity: {$entity}");
        }

        return match ($entity) {
            'authority', 'series', 'document' => self::extractHeadersFromSample(
                self::SAMPLES_DIR . '/' . self::TEMPLATES[$entity]['source'],
                self::TEMPLATES[$entity]['sheet'],
                self::TEMPLATES[$entity]['header_row'],
            ),
            'batch' => self::synthesiseBatchHeaders(),
            'box' => self::synthesiseBoxHeaders(),
            'location' => self::synthesiseLocationHeaders(),
        };
        // Note: the `array_key_exists` guard above narrows $entity to the
        // exact key set covered by the match arms, so PHPStan correctly
        // flags any `default` here as unreachable.
    }

    /**
     * Read row N of sheet K from a sample xlsx and return the populated
     * header cells in order.
     *
     * Trailing-NULL handling: `Series_Sample.xlsx` declares 26 columns but
     * only the first 6 are populated — Excel records `getHighestColumn()`
     * as `Z` because some empty cells were touched at save time. We trim
     * trailing NULLs so the generated template doesn't ship phantom empty
     * column slots. Interior NULLs (very rare in our samples) are kept as
     * empty strings to preserve column count and alignment, since
     * downstream code may rely on column positions.
     *
     * @return array<int, string>
     */
    private static function extractHeadersFromSample(string $samplePath, int $sheet, int $headerRow): array
    {
        if (! is_readable($samplePath)) {
            throw new \RuntimeException("Sample file not readable: {$samplePath}");
        }

        $reader = IOFactory::createReaderForFile($samplePath);
        $reader->setReadDataOnly(true);
        // Only read the header row — `Batch_List_Sample.xlsx` is 3,000+ rows
        // wide and the default reader holds the whole grid in memory (~120 MB
        // for that one file). A read filter keeps memory flat regardless of
        // sample size.
        $reader->setReadFilter(new class($headerRow) implements IReadFilter
        {
            public function __construct(private readonly int $headerRow) {}

            // Loose signature to stay compatible with the interface contract
            // shipped by phpoffice/phpspreadsheet (no scalar typehints on the
            // interface method).
            public function readCell($columnAddress, $row, $worksheetName = '')
            {
                return $row === $this->headerRow;
            }
        });
        $spreadsheet = $reader->load($samplePath);
        $worksheet = $spreadsheet->getSheet($sheet);

        $highestColumn = $worksheet->getHighestColumn();
        $highestIdx = Coordinate::columnIndexFromString($highestColumn);

        $row = [];
        for ($c = 1; $c <= $highestIdx; $c++) {
            $coord = Coordinate::stringFromColumnIndex($c) . $headerRow;
            $value = $worksheet->getCell($coord)->getValue();
            $row[] = $value;
        }

        // Trim trailing NULL / empty cells. We do NOT touch interior holes
        // (those would break column position contracts for the Document
        // template).
        while (count($row) > 0) {
            $last = end($row);
            if ($last === null || $last === '') {
                array_pop($row);

                continue;
            }
            break;
        }

        // Map interior NULL → empty string so the writer doesn't choke
        // and `is_string` checks downstream are clean.
        return array_map(static fn ($v) => (string) ($v ?? ''), $row);
    }

    /**
     * Synthetic Batch headers — mirrors {@see BatchImporter::getColumns()}
     * exactly, including order. The names match the labels operators see
     * inside the Filament Import modal (column-mapping dropdown), so a
     * "download → fill → re-upload" cycle requires zero remapping.
     *
     * Reflects RFQ Appendix-2 §4 (Batch is the root tenancy unit).
     *
     * @return array<int, string>
     */
    private static function synthesiseBatchHeaders(): array
    {
        return [
            'batch_number',
            'description',
            'type',           // MAIN_COLLECTION | NOTARY_ACCESSION
            'is_active',
            'repository_code',
        ];
    }

    /**
     * Synthetic Box headers — mirrors {@see BoxImporter::getColumns()}
     * exactly. Order is significant: `batch_number` precedes `parent_box_number`
     * so an operator filling top-to-bottom understands the parent must
     * already exist in the destination batch.
     *
     * @return array<int, string>
     */
    private static function synthesiseBoxHeaders(): array
    {
        return [
            'box_type',          // RAS | IN_SITU | NRA | MAV | STVC
            'box_number',
            'batch_number',
            'parent_box_number', // barcode of the parent RAS box (IN_SITU/NRA require it — RFQ #3)
            'barcode',
            'barcode_status',    // IN | OUT | PERM_OUT  (PERM_OUT requires disinfestation_date)
            'disinfestation_date',
            'is_legacy',
            'notes',
        ];
    }

    /**
     * Location template — driven by LocationImporter columns + Location::TYPES.
     * Operators describe their tree top-down: roots first (parent_name blank),
     * then children. Re-runs are safe; missing-parent rows fail but the next
     * run picks them up once the parent exists.
     *
     * @return array<int, string>
     */
    private static function synthesiseLocationHeaders(): array
    {
        return [
            'name',
            'type',             // repository | room | work_area | shelf | museum | showcase | conservation | temp_holding | other
            'parent_name',      // blank for root rows
            'repository_code',  // e.g. NRA
            'code',
            'notes',
            'sort_order',
            'is_active',
        ];
    }

    /**
     * Build the Spreadsheet with a single data sheet (headers in row 1,
     * everything below blank) plus a hidden metadata sheet that lets us
     * detect tampering / staleness at re-upload time.
     *
     * @param array<int, string> $headers
     */
    private static function buildSpreadsheet(string $entity, array $headers): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;

        // Sheet 0: the actual template the operator fills in.
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::sheetTitleFor($entity));

        foreach ($headers as $i => $header) {
            // i is 0-based; xlsx columns are 1-based (A=1).
            $colLetter = Coordinate::stringFromColumnIndex($i + 1);
            $cell = $sheet->getCell($colLetter . '1');
            $cell->setValueExplicit(
                $header,
                DataType::TYPE_STRING
            );
        }

        // Light styling — bold header, freeze top row, autosize.
        if (count($headers) > 0) {
            $lastColLetter = Coordinate::stringFromColumnIndex(count($headers));
            $headerRange = "A1:{$lastColLetter}1";
            $sheet->getStyle($headerRange)->getFont()->setBold(true);
            $sheet->getStyle($headerRange)
                ->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->freezePane('A2');

            foreach (range(1, count($headers)) as $colIdx) {
                $letter = Coordinate::stringFromColumnIndex($colIdx);
                $sheet->getColumnDimension($letter)->setAutoSize(true);
            }
        }

        // Sheet 1: hidden metadata. The operator never sees this; we use it
        // to detect tampered or stale templates at upload time.
        $meta = $spreadsheet->createSheet();
        $meta->setTitle('_template_meta');
        $meta->fromArray(
            [
                ['key', 'value'],
                ['generated_at', now()->toIso8601String()],
                ['entity', $entity],
                ['expected_column_count', (string) count($headers)],
                ['generator_version', self::GENERATOR_VERSION],
            ],
            null,
            'A1'
        );
        $meta->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        // Restore active sheet to index 0 (the data sheet) — otherwise the
        // hidden meta would be the default tab in Excel.
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private static function sheetTitleFor(string $entity): string
    {
        // Excel sheet titles cap at 31 chars and forbid `:\/?*[]`. Our
        // entity keys are clean ASCII; just title-case for the user.
        return match ($entity) {
            'authority' => 'Authorities',
            'series' => 'Series',
            'batch' => 'Batches',
            'box' => 'Boxes',
            'document' => 'Documents',
            default => 'Template',
        };
    }
}
