<?php

declare(strict_types=1);

namespace App\Support\BulkImport;

use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Reads an .xlsx sheet row by row instead of loading the whole workbook.
 *
 * Client 2026-10-12: a 26,867-row documents sheet (4.5 MB) failed to upload
 * with "Error while loading page". The wizard loaded the whole workbook with
 * PhpSpreadsheet to show a 10-row preview and to write the CSV: 648 MB of
 * memory, past both PHP's limit and the host's, so the request died. Read
 * as a stream the same sheet needs about 14 MB.
 *
 * Cell values come out the way PhpSpreadsheet's read-data-only mode gave
 * them, so what reaches the importers does not change: a date cell is its
 * Excel serial number ("45143"), a boolean is "1" or "", numbers keep their
 * digits.
 */
final class XlsxStream
{
    /**
     * Header row, the first $max data rows, and the number of data rows.
     *
     * @return array{headers: array<int, string>, rows: array<int, array<int, mixed>>, totalRows: int}
     */
    public static function preview(string $path, int $sheetIndex, int $max = 10): array
    {
        $headers = [];
        $rows = [];
        $count = 0;
        $width = 0;

        foreach (self::rows($path, $sheetIndex) as $i => $cells) {
            if ($i === 0) {
                $headers = array_map(static fn ($v): string => (string) ($v ?? ''), self::trimTrailingBlanks($cells));
                $width = count($headers);

                continue;
            }
            if (self::isBlank($cells)) {
                continue;
            }
            $count++;
            if (count($rows) < $max) {
                $rows[] = array_pad(array_slice($cells, 0, max($width, 1)), $width, null);
            }
        }

        return ['headers' => $headers, 'rows' => $rows, 'totalRows' => $count];
    }

    /**
     * Write the sheet to $csvPath, one row at a time.
     */
    public static function toCsv(string $path, int $sheetIndex, string $csvPath): void
    {
        $out = fopen($csvPath, 'wb');
        if ($out === false) {
            throw new \RuntimeException("Cannot write {$csvPath}");
        }

        try {
            $width = 0;
            foreach (self::rows($path, $sheetIndex) as $i => $cells) {
                if ($i === 0) {
                    $width = count(self::trimTrailingBlanks($cells));
                }
                if ($i > 0 && self::isBlank($cells)) {
                    continue;
                }
                $line = array_pad(array_slice($cells, 0, max($width, 1)), $width, null);
                fputcsv($out, array_map(static fn ($v): string => (string) ($v ?? ''), $line), ',', '"', '');
            }
        } finally {
            fclose($out);
        }
    }

    /**
     * @return \Generator<int, array<int, mixed>>
     */
    private static function rows(string $path, int $sheetIndex): \Generator
    {
        $reader = new XlsxReader;
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $n => $sheet) {
                // OpenSpout numbers sheets from 1.
                if ($n - 1 !== $sheetIndex) {
                    continue;
                }
                $i = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    yield $i++ => array_map(self::cell(...), $row->toArray());
                }

                return;
            }
        } finally {
            $reader->close();
        }
    }

    private static function cell(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            $serial = ExcelDate::PHPToExcel($value);
            // Excel counts a 29 February 1900 that never existed; OpenSpout
            // does not, so its dates before 1 March 1900 come out one day
            // early. Real case: a "2" in the Dates column read back as 1.
            if (is_numeric($serial) && $serial < 60) {
                $serial += 1;
            }

            return is_float($serial) && floor($serial) === $serial ? (int) $serial : $serial;
        }
        if ($value instanceof \DateInterval) {
            return $value->format('%H:%I:%S');
        }
        if (is_bool($value)) {
            return $value ? 1 : '';
        }

        return $value;
    }

    /**
     * @param array<int, mixed> $cells
     * @return array<int, mixed>
     */
    private static function trimTrailingBlanks(array $cells): array
    {
        while ($cells !== [] && in_array(end($cells), [null, ''], true)) {
            array_pop($cells);
        }

        return $cells;
    }

    /** @param array<int, mixed> $cells */
    private static function isBlank(array $cells): bool
    {
        foreach ($cells as $c) {
            if ($c !== null && $c !== '') {
                return false;
            }
        }

        return true;
    }
}
