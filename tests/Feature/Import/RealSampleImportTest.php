<?php

declare(strict_types=1);

use App\Filament\Imports\AccessionRowImporter;
use App\Models\Accession;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;

/**
 * End-to-end import of the REAL client sample (Sam Abela accession, sheet
 * "Batch list format") through the same AccessionRowImporter the Import
 * Wizard uses — the exact flow NAF will run themselves after the data reset.
 *
 * The sample lives in nra/inbox/ which is NOT tracked by git, so the test
 * skips cleanly where the file is absent (CI).
 */
uses(RefreshDatabase::class);

const RSI_SAMPLE = __DIR__ . '/../../../nra/inbox/2026-06-23_NAF_Sam_Abela_Accession_sample.xlsx';

function rsi_admin(int $repoId): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repoId]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * Map the sheet's real headers onto importer columns the same way the wizard
 * guesses them: normalised name/label/guess matching.
 *
 * @param list<string> $headers
 * @return array<string, string> importer column name => sheet header
 */
function rsi_columnMap(array $headers): array
{
    $normalise = fn (string $s): string => strtolower(trim(preg_replace('/[^a-z0-9]+/i', '_', $s) ?? '', '_'));

    $map = [];
    foreach (AccessionRowImporter::getColumns() as $column) {
        $candidates = array_map($normalise, array_filter([
            $column->getName(),
            $column->getLabel(),
            ...$column->getGuesses(),
        ]));
        foreach ($headers as $header) {
            if ($header !== '' && in_array($normalise($header), $candidates, true)) {
                $map[$column->getName()] = $header;
                break;
            }
        }
    }

    return $map;
}

it('imports the real Sam Abela sample sheet end-to-end like the wizard will', function (): void {
    // Production has the repository with the exact code 'NRA' — the sample's
    // Repository column references it literally.
    $repo = Repository::factory()->create(['code' => 'NRA']);
    $user = rsi_admin($repo->id);
    $this->actingAs($user);
    // The one hard prerequisite, as configured on production: the sample uses
    // the REG (165 rows) and RWL (35 rows) series.
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Registers', 'is_active' => true]);
    // is_wills_series matters: the sample's RWL rows sit in Batch 50 (the
    // wills reserve) and the App.1 #2 guard rejects non-wills series there.
    // (Production's RWL row was missing this flag — fixed 2026-07-21.)
    Series::firstOrCreate(['code' => 'RWL'], ['title' => 'Registers of Wills', 'is_wills_series' => true, 'is_active' => true]);

    $reader = IOFactory::createReaderForFile(RSI_SAMPLE);
    $reader->setReadDataOnly(true);
    $sheet = $reader->load(RSI_SAMPLE)->getSheetByName('Batch list format');
    $rows = array_values(array_filter(
        $sheet->toArray(null, true, false, false),
        fn (array $r): bool => count(array_filter($r, fn ($c) => $c !== null && $c !== '')) > 0,
    ));

    $headers = array_map(fn ($h): string => (string) $h, $rows[0]);
    $columnMap = rsi_columnMap($headers);

    // The essential columns must all be recognised by the wizard's guessing.
    foreach (['batch_number', 'box_number', 'authority_identifier', 'series', 'accession_number'] as $required) {
        expect($columnMap)->toHaveKey($required);
    }

    $headerIndex = array_flip($headers);
    $imp = Import::query()->create([
        'completed_at' => null,
        'file_name' => basename(RSI_SAMPLE),
        'file_path' => RSI_SAMPLE,
        'importer' => AccessionRowImporter::class,
        'processed_rows' => 0,
        'total_rows' => count($rows) - 1,
        'successful_rows' => 0,
        'user_id' => $user->id,
    ]);

    EntityResolver::flushMemo();
    $ok = 0;
    $failures = [];
    foreach (array_slice($rows, 1) as $i => $row) {
        $data = [];
        foreach ($columnMap as $columnName => $header) {
            $value = $row[$headerIndex[$header]] ?? null;
            $data[$columnName] = $value === null ? null : (string) $value;
        }

        try {
            (new AccessionRowImporter($imp, $columnMap, []))($data);
            $ok++;
        } catch (ValidationException $e) {
            $failures[$i + 2] = implode(' | ', array_map(fn ($m) => implode(', ', $m), $e->errors()));
        }
    }

    // 198 of the 200 rows import cleanly. The remaining two carry REAL data
    // errors in the client's own sample, and the importer must flag exactly
    // those (the Q8 discrepancy check Charlene asked for):
    //  - row 35: identifier 640 paired with another notary's name (Vincenzo
    //    Caruana is 642; 640 is Francesco Catania)
    //  - row 86: the full name typed into the Surname column
    expect(array_keys($failures))->toBe([35, 86])
        ->and($failures[35])->toContain("does not match record 'Francesco'")
        ->and($failures[86])->toContain('does not match record')
        ->and($ok)->toBe(198)
        // Spot-check the cascade against known values from the sheet.
        ->and(Document::withoutGlobalScope(RepositoryScope::class)->count())->toBeGreaterThan(0)
        ->and(Batch::withoutGlobalScope(RepositoryScope::class)->where('batch_number', 46)->exists())->toBeTrue()
        ->and(Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->where('barcode', 'AC54609')->exists())->toBeTrue()
        ->and(Authority::withoutGlobalScopes()->where('identifier', '642')->where('surname', 'Caruana')->exists())->toBeTrue()
        ->and(Accession::withoutGlobalScopes()->count())->toBeGreaterThan(0);
})->skip(fn (): bool => ! is_file(RSI_SAMPLE), 'client sample not present (nra/inbox is untracked)');
