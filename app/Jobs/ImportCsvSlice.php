<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use Filament\Actions\Imports\Jobs\ImportCsv;
use Filament\Actions\Imports\Models\Import;

/**
 * One chunk of a wizard import, read from the CSV on disk when the job runs.
 *
 * Client 2026-10-12: a 26,867-row documents sheet could not start, because
 * the wizard read every row into memory (204 MB) and serialized all of them
 * into the queued jobs. Each job now carries only where its rows start in
 * the CSV and how many to read, so starting an import needs a few MB
 * whatever the size of the file. The rows reach the importer exactly as
 * before: keyed by the de-duplicated headers, with the export formula guard
 * undone, and, for documents, with their absolute position in the file.
 */
class ImportCsvSlice extends ImportCsv
{
    /**
     * @param array<string, string> $columnMap
     * @param array<string, mixed> $options
     */
    public function __construct(
        Import $import,
        protected string $csvPath,
        protected int $byteOffset,
        protected int $firstRow,
        protected int $length,
        array $columnMap,
        array $options = [],
    ) {
        parent::__construct($import, [], $columnMap, $options);
    }

    public function handle(): void
    {
        $headers = ImportWizard::csvHeaderKeys($this->csvPath);
        $withSourceRow = $this->import->importer === DocumentImporter::class;

        $rows = [];
        $i = $this->firstRow;
        foreach (ImportWizard::csvRows($this->csvPath, $headers, $this->byteOffset) as $row) {
            if (count($rows) >= $this->length) {
                break;
            }
            if ($withSourceRow) {
                $row[DocumentImporter::SOURCE_ROW_KEY] = (string) $i;
            }
            $rows[] = $row;
            $i++;
        }

        $this->rows = $rows;

        parent::handle();
    }
}
