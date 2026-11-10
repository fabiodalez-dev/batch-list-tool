<?php

declare(strict_types=1);

namespace App\Support\Reports\RasReconciliation;

use Carbon\CarbonImmutable;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Reads the export RAS sends us (client 2026-11-10, Report 1).
 *
 * The sample is an Excel grid with the headers "Column1".."Column8":
 *   Column1  barcode          AA30928
 *   Column4  RAS reference    ARK/054-4  = box 54 of batch 4
 *   Column6  status           In / Out / Perm Out
 *   Column7  date             01/14/2019 (m/d/Y)
 *   Column8  container type   PREMIUM / XLPREMIUM / CONTAINER
 *
 * A sheet with real headers (Barcode, Reference, Status...) is read too, and so
 * is one with no header row at all. The rows are yielded one at a time: a
 * 12,000-row export never sits in memory as a whole.
 */
final class RasExport
{
    /** Where each field sits in the "Column1..Column8" layout (0-based). */
    private const array POSITIONAL = [
        'barcode' => 0,
        'reference' => 3,
        'status' => 5,
        'date' => 6,
        'container' => 7,
    ];

    private const string NOT_RAS = 'This does not look like the RAS export: no Barcode, Status and RAS reference (ARK/…) columns were found.';

    /** How far down the sheet a header row is looked for. */
    private const int HEADER_SCAN_ROWS = 10;

    /**
     * "ARK/054-4" → ['box' => '54', 'batch' => '4']. The box can carry a
     * letter ("ARK/9A-12"), the prefix any case ("Ark/", "ark/"), and the
     * numbers leading zeros. Anything else gives null.
     *
     * @return array{batch: string, box: string}|null
     */
    public static function parseReference(mixed $reference): ?array
    {
        if (! is_scalar($reference)) {
            return null;
        }

        if (preg_match('~^\s*ARK\s*/\s*0*(\d+)\s*([A-Z]?)\s*-\s*0*(\d+)\s*$~i', (string) $reference, $m) !== 1) {
            return null;
        }

        return [
            'batch' => self::number($m[3]),
            'box' => self::number($m[1]) . strtoupper($m[2]),
        ];
    }

    /**
     * Our box number in the form the RAS reference is compared in: "054" and
     * "54" are the same box, "9a" is "9A". A box number that is not a number
     * (with an optional letter) is only trimmed and upper-cased.
     */
    public static function normaliseBox(mixed $box): ?string
    {
        $value = self::text($box);
        if ($value === null) {
            return null;
        }

        if (preg_match('~^0*(\d+)\s*([A-Z]?)$~i', $value, $m) === 1) {
            return self::number($m[1]) . strtoupper($m[2]);
        }

        return strtoupper($value);
    }

    /** Batch "04" is batch "4". */
    public static function normaliseBatch(mixed $batch): ?string
    {
        $value = self::text($batch);
        if ($value === null) {
            return null;
        }

        return ctype_digit($value) ? self::number($value) : strtoupper($value);
    }

    /**
     * RAS status → our barcode status: "In" → IN, "Out" → OUT,
     * "Perm Out" → PERM_OUT. Null when the text is none of them.
     */
    public static function mapStatus(mixed $status): ?string
    {
        $value = self::text($status);
        if ($value === null) {
            return null;
        }

        $key = preg_replace('~[^A-Z]~', '', strtoupper($value));

        return match ($key) {
            'IN' => 'IN',
            'OUT' => 'OUT',
            'PERMOUT', 'PERMANENTOUT', 'PERMANENTLYOUT' => 'PERM_OUT',
            default => null,
        };
    }

    public static function normaliseBarcode(mixed $barcode): ?string
    {
        $value = self::text($barcode);

        return $value === null ? null : strtoupper($value);
    }

    /**
     * The data rows of the export, keyed by their spreadsheet row number.
     *
     * @return \Generator<int, array{barcode: mixed, reference: mixed, status: mixed, date: mixed, container: mixed}>
     *
     * @throws \RuntimeException when no barcode / status / reference column can be found
     */
    public static function rows(string $path, string $extension): \Generator
    {
        $columns = null;
        $scanned = 0;

        foreach (self::cells($path, strtolower($extension)) as $rowNumber => $cells) {
            if (self::isBlank($cells)) {
                continue;
            }

            if ($columns === null) {
                $scanned++;
                $columns = self::columnsFromHeader($cells);
                if ($columns !== null) {
                    continue; // a header row: the data starts below
                }
                if (self::findReferenceCell($cells) !== null) {
                    // No header at all: the sheet starts with data.
                    $columns = self::POSITIONAL;
                } elseif ($scanned >= self::HEADER_SCAN_ROWS) {
                    throw new \RuntimeException(self::NOT_RAS);
                } else {
                    continue; // a title line above the header
                }
            }

            yield $rowNumber => [
                'barcode' => $cells[$columns['barcode']] ?? null,
                'reference' => $cells[$columns['reference']] ?? null,
                'status' => $cells[$columns['status']] ?? null,
                'date' => isset($columns['date']) ? ($cells[$columns['date']] ?? null) : null,
                'container' => isset($columns['container']) ? ($cells[$columns['container']] ?? null) : null,
            ];
        }

        if ($columns === null) {
            throw new \RuntimeException($scanned === 0 ? 'The file has no rows.' : self::NOT_RAS);
        }
    }

    /** The RAS date (m/d/Y, or a real date cell) as Y-m-d. */
    public static function parseDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)))) {
            $serial = (float) $value;
            if ($serial < 1 || $serial > 2958465) {
                return null;
            }

            return ExcelDate::excelToDateTimeObject($serial)->format('Y-m-d');
        }

        $text = self::text($value);
        if ($text === null) {
            return null;
        }
        // Drop a time part ("1/14/2019 12:00:00 AM").
        $text = preg_replace('~\s+\d{1,2}:\d{2}.*$~', '', $text) ?? $text;

        if (preg_match('~^(\d{1,2})/(\d{1,2})/(\d{4}|\d{2})$~', $text, $m) === 1) {
            [$month, $day, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('~^(\d{4})-(\d{1,2})-(\d{1,2})$~', $text, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        if ($year < 100) {
            $year += 2000;
        }

        return checkdate($month, $day, $year)
            ? CarbonImmutable::create($year, $month, $day)?->format('Y-m-d')
            : null;
    }

    /**
     * @param array<int, mixed> $cells
     * @return array<string, int>|null
     */
    private static function columnsFromHeader(array $cells): ?array
    {
        $names = [];
        foreach ($cells as $i => $cell) {
            $names[$i] = strtolower(trim((string) (is_scalar($cell) ? $cell : '')));
        }

        // The grid RAS exports: Column1..Column8.
        $numbered = [];
        foreach ($names as $i => $name) {
            if (preg_match('~^column\s*(\d+)$~', $name, $m) === 1) {
                $numbered[(int) $m[1]] = $i;
            }
        }
        if (isset($numbered[1], $numbered[4], $numbered[6])) {
            $columns = [];
            foreach (self::POSITIONAL as $field => $position) {
                if (isset($numbered[$position + 1])) {
                    $columns[$field] = $numbered[$position + 1];
                }
            }

            return $columns;
        }

        // Real headers.
        $find = static function (array $patterns) use ($names): ?int {
            foreach ($patterns as $pattern) {
                foreach ($names as $i => $name) {
                    if ($name !== '' && preg_match($pattern, $name) === 1) {
                        return $i;
                    }
                }
            }

            return null;
        };

        $barcode = $find(['~^barcode$~', '~barcode~', '~^item(\s*(no|number|code))?$~']);
        $status = $find(['~^status$~', '~status~']);
        $reference = $find(['~^(ras\s*)?ref(erence)?$~', '~ref(erence)?~', '~ark~', '~description~', '~^box~']);
        if ($barcode === null || $status === null || $reference === null) {
            return null;
        }

        $columns = ['barcode' => $barcode, 'reference' => $reference, 'status' => $status];
        $date = $find(['~^date$~', '~date~']);
        if ($date !== null) {
            $columns['date'] = $date;
        }
        $container = $find(['~container~', '~type~']);
        if ($container !== null && ! in_array($container, $columns, true)) {
            $columns['container'] = $container;
        }

        return $columns;
    }

    /** @param array<int, mixed> $cells */
    private static function findReferenceCell(array $cells): ?int
    {
        foreach ($cells as $i => $cell) {
            if (self::parseReference($cell) !== null) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Raw rows of the first sheet, keyed by spreadsheet row number (1-based).
     *
     * @return \Generator<int, array<int, mixed>>
     */
    private static function cells(string $path, string $extension): \Generator
    {
        if ($extension === 'xlsx') {
            $reader = new XlsxReader(new XlsxOptions);
            $reader->open($path);

            try {
                foreach ($reader->getSheetIterator() as $sheet) {
                    $n = 0;
                    foreach ($sheet->getRowIterator() as $row) {
                        yield ++$n => $row->toArray();
                    }

                    return; // first sheet only
                }
            } finally {
                $reader->close();
            }

            return;
        }

        if ($extension === 'xls') {
            // The old binary format cannot be streamed; it is capped at 65,536
            // rows, so the whole sheet fits.
            $reader = IOFactory::createReader('Xls');
            $reader->setReadDataOnly(true);
            $sheet = $reader->load($path)->getSheet(0);
            foreach ($sheet->getRowIterator() as $row) {
                $cells = [];
                foreach ($row->getCellIterator() as $cell) {
                    $cells[] = $cell->getValue();
                }
                yield $row->getRowIndex() => $cells;
            }

            return;
        }

        yield from self::csvCells($path);
    }

    /** @return \Generator<int, array<int, mixed>> */
    private static function csvCells(string $path): \Generator
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open the file.');
        }

        try {
            $first = (string) fgets($handle);
            $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
            if (substr_count($first, "\t") > max(substr_count($first, ';'), substr_count($first, ','))) {
                $delimiter = "\t";
            }
            rewind($handle);

            $n = 0;
            while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $n++;
                if ($n === 1 && isset($cells[0]) && is_string($cells[0])) {
                    $cells[0] = preg_replace('~^\xEF\xBB\xBF~', '', $cells[0]);
                }
                yield $n => $cells;
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param array<int, mixed> $cells */
    private static function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== null && (! is_string($cell) || trim($cell) !== '')) {
                return false;
            }
        }

        return true;
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }
        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /** "054" → "54", "000" → "0". */
    private static function number(string $digits): string
    {
        $trimmed = ltrim($digits, '0');

        return $trimmed === '' ? '0' : $trimmed;
    }
}
