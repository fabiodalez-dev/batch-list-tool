<?php

declare(strict_types=1);

use App\Filament\Pages\ImportWizard;
use App\Jobs\ImportCsvSlice;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\XlsxStream;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Role;

/**
 * Client, 2026-10-12: a 26,867-row documents sheet (4.5 MB) failed on upload
 * with "Error while loading page". The wizard loaded the whole workbook to
 * show a 10-row preview (648 MB) and then held every row in memory and in the
 * queued jobs (204 MB), past what the web process may use. The sheet is now
 * read as a stream, and each job reads its own rows from the CSV on disk.
 *
 * These drive the real entry point: upload, Start import, rows in the DB.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    bl_seedShieldPermissions();
    $this->repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    $this->user = User::factory()->create(['is_active' => true, 'default_repository_id' => $this->repo->id]);
    $this->user->assignRole('super_admin');
    $this->actingAs($this->user);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true, 'is_wills_series' => false]);
});

/**
 * A documents sheet of $n rows with no Document Identifier (so every row gets
 * an auto identifier from its position in the file), a date column, and a
 * repeated header, like the client's.
 */
function wls_sheet(int $n): string
{
    $book = new Spreadsheet;
    $ws = $book->getActiveSheet();
    $ws->fromArray(['Subseries', 'Volume', 'Dates', 'Disinfestation Date', 'Past Reference Code', 'Past Reference Code'], null, 'A1');
    for ($i = 1; $i <= $n; $i++) {
        $ws->fromArray(['REG', (string) $i, 'Jan 1984 - Mar 1985', ExcelDate::PHPToExcel(new DateTime('2023-08-05')), 'P' . $i, ''], null, 'A' . ($i + 1));
        $ws->getStyle('D' . ($i + 1))->getNumberFormat()->setFormatCode('dd/mm/yyyy');
    }
    $path = tempnam(sys_get_temp_dir(), 'wls') . '.xlsx';
    (new Xlsx($book))->save($path);

    return $path;
}

it('imports every row of a sheet larger than one chunk, from the Start import button', function (): void {
    $path = wls_sheet(250);

    Livewire::test(ImportWizard::class)
        ->set('data.import_type', 'documents')
        ->set('data.file', UploadedFile::fake()->createWithContent('documents.xlsx', file_get_contents($path)))
        ->set('data.confirm', true)
        ->call('startImport')
        ->assertNotified('Import started');

    $import = Import::query()->latest('id')->firstOrFail();
    $docs = Document::withoutGlobalScope(RepositoryScope::class);

    expect($import->total_rows)->toBe(250)
        ->and($import->successful_rows)->toBe(250)
        // One document per row: the auto identifiers come from the absolute
        // row position, so rows 100 apart in different chunks do not merge.
        ->and($docs->count())->toBe(250)
        ->and($docs->distinct()->count('identifier'))->toBe(250)
        ->and(Document::withoutGlobalScope(RepositoryScope::class)->where('volume_number', '250')->value('disinfestation_date')?->format('Y-m-d'))->toBe('2023-08-05');
});

it('queues jobs that carry where their rows are, not the rows', function (): void {
    Bus::fake();
    $path = wls_sheet(250);

    Livewire::test(ImportWizard::class)
        ->set('data.import_type', 'documents')
        ->set('data.file', UploadedFile::fake()->createWithContent('documents.xlsx', file_get_contents($path)))
        ->set('data.confirm', true)
        ->call('startImport');

    Bus::assertBatched(function (PendingBatch $batch): bool {
        $jobs = $batch->jobs->all();

        return count($jobs) === 3
            && collect($jobs)->every(fn ($j): bool => $j instanceof ImportCsvSlice && strlen(serialize($j)) < 8000);
    });
});

it('previews a sheet from the stream: headers, first rows and the row count', function (): void {
    $preview = XlsxStream::preview(wls_sheet(250), 0);

    expect($preview['headers'])->toBe(['Subseries', 'Volume', 'Dates', 'Disinfestation Date', 'Past Reference Code', 'Past Reference Code'])
        ->and($preview['rows'])->toHaveCount(10)
        ->and($preview['totalRows'])->toBe(250)
        // A date cell comes out as the Excel serial number, as before.
        ->and($preview['rows'][0][3])->toBe(45143);
});

it('imports a cell Excel turned into a date as the text it shows, and keeps whole dates as dates', function (): void {
    // Client 2026-10-19: "1-6" in Volume, "Sep 2000" in Dates and "1-2" in
    // Part Number had become dates in Excel and were imported as "45663".
    $book = new Spreadsheet;
    $ws = $book->getActiveSheet();
    $ws->fromArray(['Subseries', 'Volume', 'Dates', 'Part Number', 'Disinfestation Date', 'Disinfestation Date'], null, 'A1');
    $ws->fromArray(['REG', ExcelDate::PHPToExcel(new DateTime('2025-01-06')), ExcelDate::PHPToExcel(new DateTime('2000-09-01')), ExcelDate::PHPToExcel(new DateTime('2025-01-02')), ExcelDate::PHPToExcel(new DateTime('2023-08-05')), ExcelDate::PHPToExcel(new DateTime('2023-05-08'))], null, 'A2');
    $ws->getStyle('B2')->getNumberFormat()->setFormatCode('m\-d');
    $ws->getStyle('C2')->getNumberFormat()->setFormatCode('mmm\ yyyy');
    $ws->getStyle('D2')->getNumberFormat()->setFormatCode('m\-d');
    $ws->getStyle('E2')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
    // Excel's own short date (format 14) is shown in the computer's order;
    // the stored date is what counts.
    $ws->getStyle('F2')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_XLSX14);
    $path = tempnam(sys_get_temp_dir(), 'wls') . '.xlsx';
    (new Xlsx($book))->save($path);

    Livewire::test(ImportWizard::class)
        ->set('data.import_type', 'documents')
        ->set('data.file', UploadedFile::fake()->createWithContent('documents.xlsx', file_get_contents($path)))
        ->set('data.confirm', true)
        ->call('startImport')
        ->assertNotified('Import started');

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->firstOrFail();
    expect($doc->volume_number)->toBe('1-6')
        ->and($doc->dates)->toBe('Sep 2000')
        ->and($doc->part_number)->toBe('1-2')
        ->and($doc->disinfestation_date_1?->format('Y-m-d'))->toBe('2023-08-05')
        ->and($doc->disinfestation_date_2?->format('Y-m-d'))->toBe('2023-05-08');
});
