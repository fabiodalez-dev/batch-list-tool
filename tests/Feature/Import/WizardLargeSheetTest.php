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
