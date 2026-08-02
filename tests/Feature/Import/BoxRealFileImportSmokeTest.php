<?php

declare(strict_types=1);

use App\Filament\Imports\BoxImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Box;
use App\Models\Location;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Role;

/**
 * Local-only smoke test over Charlene's ACTUAL 7166-row box template
 * (nra/inbox/2026-08-01_NAF_box_template_attempt.xlsx — client PII, gitignored,
 * so this test SKIPS in CI where the file is absent). It proves the 721 real
 * failures from the 2026-08-01 import collapse: none of the loosened rules
 * (PERM_OUT-location, PERM_OUT-disinfestation, barcode-required-for-RAS,
 * box_type-required) block a row any more. The committed, PII-free per-case
 * coverage lives in BoxLegacyRulesImportTest.
 */
uses(RefreshDatabase::class);

$realFile = dirname(__DIR__, 3) . '/nra/inbox/2026-08-01_NAF_box_template_attempt.xlsx';

it('imports the real 7166-row box template with none of the loosened rules blocking', function () use ($realFile) {
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    /** @var User $u */
    $u = User::factory()->create(['is_active' => true, 'default_repository_id' => $repo->id]);
    $u->assignRole('super_admin');
    $this->actingAs($u);

    $reader = IOFactory::createReaderForFile($realFile);
    $reader->setReadDataOnly(true);
    $grid = $reader->load($realFile)->getActiveSheet()->toArray(null, true, false, false);
    $headers = array_map(fn ($h): string => (string) $h, $grid[0]);
    $map = ImportWizard::guessColumnMap(BoxImporter::class, $headers);

    // Seed the locations the file references so the import mirrors production
    // (where Charlene has already loaded the location hierarchy). Without this,
    // rows carrying a Location code fail with "Unknown location code" — a
    // legitimate failure unrelated to the rules under test.
    $locIdx = array_search('Location', $headers, true);
    if ($locIdx !== false) {
        $codes = collect(array_slice($grid, 1))
            ->map(fn ($row): string => trim((string) ($row[$locIdx] ?? '')))
            ->filter(fn (string $c): bool => $c !== '')
            ->unique();
        foreach ($codes as $code) {
            Location::withoutGlobalScope(RepositoryScope::class)->firstOrCreate(
                ['code' => $code, 'repository_id' => $repo->id],
                ['name' => $code, 'type' => 'repository', 'is_active' => true],
            );
        }
    }

    /** @var Import $imp */
    $imp = Import::query()->create([
        'completed_at' => null, 'file_name' => 'box.xlsx', 'file_path' => '/tmp/box.xlsx',
        'importer' => BoxImporter::class, 'processed_rows' => 0, 'total_rows' => 1,
        'successful_rows' => 0, 'user_id' => $u->id,
    ]);

    $loosenedHits = 0;
    $loosenedPatterns = [
        'must have a location',
        'must carry a disinfestation',
        'barcode field is required',
        'box type', // "The box type … field is required"
    ];
    $imported = 0;

    foreach (array_slice($grid, 1) as $row) {
        if (count(array_filter($row, fn ($c): bool => $c !== null && trim((string) $c) !== '')) === 0) {
            continue;
        }
        $data = [];
        foreach ($headers as $i => $h) {
            $data[$h] = $row[$i] ?? null;
        }
        EntityResolver::flushMemo();

        try {
            (new BoxImporter($imp, $map, []))($data);
            $imported++;
        } catch (Throwable $e) {
            $msg = strtolower($e->getMessage());
            foreach ($loosenedPatterns as $p) {
                if (str_contains($msg, $p) && str_contains($msg, 'required')) {
                    $loosenedHits++;
                    break;
                }
                if (str_contains($msg, $p) && ! str_contains($p, 'box type')) {
                    $loosenedHits++;
                    break;
                }
            }
        }
    }

    // The whole point: not a single row is rejected by a rule the client asked
    // to loosen. (Rows may still fail for unrelated reasons — e.g. a Location
    // code that doesn't exist yet — but never for the loosened rules.)
    expect($loosenedHits)->toBe(0);

    // And the vast majority of the 7166 rows now land.
    $count = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->count();
    expect($count)->toBeGreaterThan(7000);
})->skip(! file_exists($realFile), 'client PII box file not present (local-only smoke test)');
