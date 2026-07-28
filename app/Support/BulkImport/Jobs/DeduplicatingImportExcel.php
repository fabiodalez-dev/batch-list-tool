<?php

declare(strict_types=1);

namespace App\Support\BulkImport\Jobs;

use App\Filament\Imports\DocumentImporter;
use App\Support\BulkImport\SpreadsheetHeaders;
use HayderHatem\FilamentExcelImport\Actions\Imports\Jobs\ImportExcel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * Bug #4 — the streaming "Import Excel / CSV" job, de-duplicating.
 *
 * The stock vendor job ({@see ImportExcel}) reads each data row as
 * `$rowData[$headers[$columnIndex] ?? $columnIndex] = $value` (line 341 of the
 * vendor class). When the sheet repeats a header string — as the legacy NAf
 * batch-list layout deliberately does for "Barcode (IN)", "Status 1" and
 * "Disinfestation Date" — the last physical column overwrites the earlier
 * ones, and the earlier column is the one carrying real data. The result:
 * barcode_in / status_1 / disinfestation_date reach {@see DocumentImporter}
 * blank on every row of the client's own file.
 *
 * The vendor class is app-external and must not be edited, so we subclass it
 * and override ONLY the row reader, routing header assembly through
 * {@see SpreadsheetHeaders::dedupe()} so each physical column keeps a distinct
 * key. The first occurrence of every header keeps its exact name (existing
 * column-map entries still resolve to the data-bearing column); later
 * occurrences get a "` (n)`" suffix and are simply left unmapped.
 *
 * Everything else — chunking, per-row transactions, S3 handling, error
 * parsing — is inherited unchanged. This job is wired in via
 * `FullImportAction::make()->job(self::class)` on the resource pages whose
 * templates carry duplicated headers (Documents).
 *
 * The read below mirrors the vendor's {@see ImportExcel::readExcelRowsFromFile()}
 * faithfully (same memory optimisations, same read filter, same empty-row
 * skipping); the ONE behavioural change is that the header list is de-duplicated
 * by position before it is used to key each row.
 */
class DeduplicatingImportExcel extends ImportExcel
{
    /**
     * Read a range of rows from the sheet, de-duplicating repeated headers by
     * physical position so no column silently overwrites another.
     *
     * @return array<int, array<string|int, mixed>>
     */
    protected function readExcelRowsFromFile(string $filePath, int $startRow, int $endRow): array
    {
        if (! $this->fileExists($filePath)) {
            throw new \Exception("Import file not found: {$filePath}");
        }

        $tempFile = null;

        try {
            if ($this->isS3FilePath($filePath)) {
                $tempFile = $this->downloadS3FileTemporarily($filePath);
                $actualFilePath = $tempFile;
            } else {
                $actualFilePath = $filePath;
            }

            $reader = IOFactory::createReaderForFile($actualFilePath);
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);

            $headerOffset = (int) ($this->options['headerOffset'] ?? 0);
            $headerRowNumber = $headerOffset + 1;

            $reader->setReadFilter(new class($startRow, $endRow, $headerRowNumber) implements IReadFilter
            {
                public function __construct(
                    private int $startRow,
                    private int $endRow,
                    private int $headerRowNumber,
                ) {}

                public function readCell($columnAddress, $row, $worksheetName = ''): bool
                {
                    return $row === $this->headerRowNumber
                        || ($row >= $this->startRow && $row <= $this->endRow);
                }
            });

            $spreadsheet = $reader->load($actualFilePath);

            $activeSheetIndex = (int) ($this->options['activeSheet'] ?? 0);
            $worksheet = $spreadsheet->getSheet($activeSheetIndex);

            $highestRow = $worksheet->getHighestDataRow();

            if ($startRow > $highestRow) {
                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet, $worksheet, $reader);

                return [];
            }

            $adjustedEndRow = min($endRow, $highestRow);

            // Header row (positional, may contain repeated strings).
            $rawHeaders = [];
            foreach ($worksheet->getRowIterator($headerRowNumber, $headerRowNumber) as $row) {
                $cellIterator = $row->getCellIterator();
                $cellIterator->setIterateOnlyExistingCells(false);
                foreach ($cellIterator as $cell) {
                    $rawHeaders[] = $cell->getValue();
                }
            }

            // Bug #4 — de-duplicate repeated headers by position so each
            // physical column keeps a distinct key instead of overwriting the
            // earlier (data-bearing) one.
            $headerKeys = SpreadsheetHeaders::dedupe($rawHeaders);

            // Bug #22 (hardening) — thread the row's ABSOLUTE sheet position to
            // the importer so its blank-identifier auto id keys on the absolute
            // (not chunk-local) position. A fresh importer is built per chunk, so
            // its own counter restarts at 0 in every chunk and cannot tell two
            // distinct rows apart when their content is identical and their
            // absolute positions differ by an exact multiple of the chunk size.
            //
            // The value is injected under DocumentImporter::SOURCE_ROW_KEY. The
            // vendor handle() remaps each row through $this->columnMap BEFORE the
            // importer sees it (dropping any key not in the map), so we also add a
            // pass-through map entry for the reserved key. handle() calls this
            // method AFTER constructing the importer, so mutating the job's
            // columnMap here affects only the vendor remap loop, not the
            // already-built importer's own column map. Empty (data-less) rows are
            // still skipped: $hasData is decided from the sheet cells alone.
            $this->columnMap[DocumentImporter::SOURCE_ROW_KEY] = DocumentImporter::SOURCE_ROW_KEY;

            $rows = [];
            $highestColumn = $worksheet->getHighestDataColumn();

            for ($rowIndex = $startRow; $rowIndex <= $adjustedEndRow; $rowIndex++) {
                $rowData = [];
                $hasData = false;

                foreach ($worksheet->getRowIterator($rowIndex, $rowIndex) as $row) {
                    $cellIterator = $row->getCellIterator('A', $highestColumn);
                    $cellIterator->setIterateOnlyExistingCells(false);
                    $columnIndex = 0;

                    foreach ($cellIterator as $cell) {
                        $value = $cell->getValue();
                        if ($value !== null) {
                            $hasData = true;
                        }
                        $rowData[$headerKeys[$columnIndex] ?? $columnIndex] = $value;
                        $columnIndex++;
                    }
                }

                if ($hasData) {
                    $rowData[DocumentImporter::SOURCE_ROW_KEY] = (string) $rowIndex;
                    $rows[] = $rowData;
                }
            }

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $worksheet, $reader);

            return $rows;
        } catch (ReaderException $e) {
            throw new \Exception('Error reading Excel file: ' . $e->getMessage());
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'memory') !== false || strpos($e->getMessage(), 'Memory') !== false) {
                throw new \Exception('File chunk too large to process. The file may be corrupted or contains extremely wide rows.');
            }

            throw $e;
        } finally {
            if ($tempFile && file_exists($tempFile)) {
                // nosemgrep: php.lang.security.unlink-use.unlink-use -- $tempFile is our own downloadS3FileTemporarily() temp path, never user input (mirrors the vendor job's own cleanup).
                @unlink($tempFile);
            }
        }
    }
}
