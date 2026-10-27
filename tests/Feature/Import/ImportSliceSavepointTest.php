<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Jobs\ImportCsvSlice;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Client 2026-10-27: a row whose save fails (a blank Subseries hits the NOT
 * NULL on series_id) after the per-row savepoint was opened left that
 * savepoint open when it was the last row of a slice. Filament's chunk
 * transaction then only stepped down a level and never committed, so the
 * whole slice (documents, counters and failed rows) vanished when the
 * worker exited.
 */
uses(RefreshDatabase::class);

function iss_run(array $rows): Import
{
    $repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true, 'repository_id' => $repo->id]);
    Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
    $user = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id]);
    $user->assignRole('super_admin');
    test()->actingAs($user);

    $csv = tempnam(sys_get_temp_dir(), 'iss') . '.csv';
    $f = fopen($csv, 'w');
    fputcsv($f, ['Document Identifier', 'Subseries']);
    $offset = ftell($f);
    foreach ($rows as $row) {
        fputcsv($f, $row);
    }
    fclose($f);

    $headers = ImportWizard::csvHeaderKeys($csv);
    $map = array_filter(ImportWizard::guessColumnMap(DocumentImporter::class, $headers));
    $import = Import::query()->create(['file_name' => 'd.csv', 'file_path' => $csv, 'importer' => DocumentImporter::class, 'total_rows' => count($rows), 'user_id' => $user->id]);

    (new ImportCsvSlice($import, $csv, $offset, 1, count($rows), $map, ['repository_id' => $repo->id]))->handle();

    return $import->refresh();
}

it('commits the slice when its last row fails on save', function (): void {
    $base = DB::transactionLevel();

    $import = iss_run([['ISS-1', 'REG'], ['ISS-2', '']]);

    expect(DB::transactionLevel())->toBe($base)
        ->and(Document::withoutGlobalScopes()->where('identifier', 'ISS-1')->exists())->toBeTrue()
        ->and(Document::withoutGlobalScopes()->where('identifier', 'ISS-2')->exists())->toBeFalse()
        ->and($import->processed_rows)->toBe(2)
        ->and($import->successful_rows)->toBe(1)
        ->and($import->failedRows()->count())->toBe(1);
});

it('keeps the rows after a row that fails on save in the middle of a slice', function (): void {
    $base = DB::transactionLevel();

    $import = iss_run([['ISS-1', 'REG'], ['ISS-2', ''], ['ISS-3', 'REG']]);

    expect(DB::transactionLevel())->toBe($base)
        ->and(Document::withoutGlobalScopes()->whereIn('identifier', ['ISS-1', 'ISS-3'])->count())->toBe(2)
        ->and($import->successful_rows)->toBe(2);
});
