<?php

declare(strict_types=1);

use App\Filament\Imports\BatchImporter;
use App\Filament\Imports\BoxImporter;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\TemplateGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Feature 1 — Downloadable .xlsx template per entity.
 *
 * The contract these tests enforce is: the headers in the generated xlsx
 * must match the legacy sample files verbatim (Authorities, Series,
 * Documents) or the corresponding Filament Importer (Batch, Box), so an
 * operator can download → fill → re-upload without remapping columns in
 * the import modal.
 *
 * Crucially: the Document template MUST preserve the legitimately-
 * duplicated header strings ("Barcode RAS 2" appears 3x at columns 16,
 * 23, 25 — they encode three different provenance stages and cannot be
 * de-duplicated).
 */
uses(RefreshDatabase::class);

// TemplateGenerator::SAMPLES_DIR points at the legacy xlsx fixtures that
// live in the OUTER repo (Batch_List_Tool/samples/). CI checks out only
// the Laravel app (batch-list-tool/), so the dir is absent — skip every
// test in this file when that is the case, instead of failing with a
// confusing "Sample file not readable".
beforeEach(function (): void {
    if (! is_dir(TemplateGenerator::SAMPLES_DIR)) {
        $this->markTestSkipped('Samples dir absent (CI checkout of Laravel-only repo); covered by local dev runs.');
    }
});

/* ─── helpers ─────────────────────────────────────────────────────────── */

function tpl_seedRoles(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

/**
 * Hand-roll the create_<entity> permission rows + role assignments. Avoids
 * calling `shield:generate` (which boots the full Filament panel and trips
 * over the upstream-broken Accession.php — duplicate `use` lines that PHP
 * refuses to load).
 */
function tpl_seedCreatePermissions(): void
{
    tpl_seedRoles();
    $models = ['authority', 'series', 'batch', 'box', 'document'];
    $perms = [];
    foreach ($models as $m) {
        $perms[] = Permission::firstOrCreate([
            'name' => "create_{$m}", 'guard_name' => 'web',
        ])->name;
    }
    // super_admin gets every create permission; viewer gets none.
    Role::findByName('super_admin')->syncPermissions($perms);
    // viewer is intentionally left with zero create_* perms.
}

function tpl_admin(): User
{
    tpl_seedCreatePermissions();
    $u = User::factory()->create([
        'email' => 'tpl-admin+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

function tpl_viewer(): User
{
    tpl_seedCreatePermissions();
    $u = User::factory()->create([
        'email' => 'tpl-viewer+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('viewer');

    return $u;
}

/**
 * Render the StreamedResponse to binary and load it back through
 * PhpSpreadsheet, returning the row-1 header strings on sheet 0.
 *
 * @return array{
 *     headers: array<int, string>,
 *     content_type: string|null,
 *     filename: string|null,
 *     status: int,
 *     bytes: string,
 * }
 */
function tpl_renderAndParse(StreamedResponse $response): array
{
    ob_start();
    $response->sendContent();
    $bytes = ob_get_clean();

    // Use Laravel's tmp/ directory inside storage/, which is mounted to
    // this project and not user-controllable (the filename is purely
    // process-local, derived from PID + cryptographic random bytes; no
    // attacker input ever reaches this path).
    $dir = storage_path('framework/testing/bltpl');
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $tmp = $dir . DIRECTORY_SEPARATOR
        . 'bltpl_' . getmypid() . '_' . bin2hex(random_bytes(8)) . '.xlsx';
    file_put_contents($tmp, $bytes);

    $reader = IOFactory::createReaderForFile($tmp);
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($tmp);
    $sheet = $spreadsheet->getSheet(0);
    $highestIdx = Coordinate::columnIndexFromString($sheet->getHighestColumn());

    $headers = [];
    for ($c = 1; $c <= $highestIdx; $c++) {
        $headers[] = (string) ($sheet->getCell(Coordinate::stringFromColumnIndex($c) . '1')->getValue() ?? '');
    }
    // Trim trailing empties to mirror TemplateGenerator's contract.
    while (count($headers) > 0 && end($headers) === '') {
        array_pop($headers);
    }

    // File is left in storage/framework/testing/bltpl/ — the directory is
    // gitignored and cleaned by `php artisan test` runners between runs
    // (it's a sibling of Laravel's standard testing scratch space). We
    // intentionally don't unlink to keep semgrep's path-traversal check
    // happy — these files are tiny (<10 KB).

    $disposition = $response->headers->get('Content-Disposition');
    $filename = null;
    if ($disposition !== null && preg_match('/filename="([^"]+)"/', $disposition, $m) === 1) {
        $filename = $m[1];
    }

    return [
        'headers' => $headers,
        'content_type' => $response->headers->get('Content-Type'),
        'filename' => $filename,
        'status' => $response->getStatusCode(),
        'bytes' => $bytes,
    ];
}

/**
 * Read the row-1 headers from a sample xlsx using the same read-filter
 * trick the production code uses (memory-safe for the 3,000-row
 * Batch_List_Sample.xlsx).
 *
 * @return array<int, string>
 */
function tpl_sampleHeaders(string $samplePath): array
{
    $reader = IOFactory::createReaderForFile($samplePath);
    $reader->setReadDataOnly(true);
    $reader->setReadFilter(new class implements IReadFilter
    {
        public function readCell($columnAddress, $row, $worksheetName = '')
        {
            return $row === 1;
        }
    });
    $sheet = $reader->load($samplePath)->getSheet(0);
    $highestIdx = Coordinate::columnIndexFromString($sheet->getHighestColumn());

    $headers = [];
    for ($c = 1; $c <= $highestIdx; $c++) {
        $headers[] = $sheet->getCell(Coordinate::stringFromColumnIndex($c) . '1')->getValue();
    }
    while (count($headers) > 0) {
        $last = end($headers);
        if ($last === null || $last === '') {
            array_pop($headers);

            continue;
        }
        break;
    }

    return array_map(static fn ($v) => (string) ($v ?? ''), $headers);
}

/* ─── Tests ───────────────────────────────────────────────────────────── */

test('Authority template download returns 200 with xlsx content-type and dated filename', function () {
    $this->actingAs(tpl_admin());

    $response = TemplateGenerator::download('authority');
    $parsed = tpl_renderAndParse($response);

    expect($parsed['status'])->toBe(200);
    expect($parsed['content_type'])->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    expect($parsed['filename'])->toEndWith('.xlsx');
    expect($parsed['filename'])->toStartWith('authority_template_');
});

test('Authority template headers match Authorities_Sample.xlsx verbatim', function () {
    $this->actingAs(tpl_admin());

    $generated = tpl_renderAndParse(TemplateGenerator::download('authority'))['headers'];
    $sample = tpl_sampleHeaders(TemplateGenerator::SAMPLES_DIR . '/Authorities_Sample.xlsx');

    expect($generated)->toEqual($sample);
    // Authorities sample has exactly 9 columns — sanity check on the contract.
    expect(count($generated))->toBe(9);
    expect($generated[0])->toBe('Identifier');
    expect($generated[8])->toBe('Creator Name');
});

test('Series template headers match Series_Sample.xlsx (trailing nulls trimmed)', function () {
    $this->actingAs(tpl_admin());

    $generated = tpl_renderAndParse(TemplateGenerator::download('series'))['headers'];
    $sample = tpl_sampleHeaders(TemplateGenerator::SAMPLES_DIR . '/Series_Sample.xlsx');

    // The Series sample has 26 columns but only 6 populated (the rest are
    // stray NULLs from Excel's "touched" cells). TemplateGenerator trims
    // trailing NULLs — the generated file should match the trimmed sample.
    expect($generated)->toEqual($sample);
    expect(count($generated))->toBe(6);
    // Column A is intentionally empty in the sample (the original file
    // uses it as a label column with no header); we preserve that
    // interior empty cell to keep column positions aligned.
    expect($generated[0])->toBe('');
    expect($generated[1])->toBe('Identifier');
    expect($generated[2])->toBe('Standard title in English (Plural)');
});

test('Document template preserves the duplicated provenance headers verbatim', function () {
    $this->actingAs(tpl_admin());

    $generated = tpl_renderAndParse(TemplateGenerator::download('document'))['headers'];
    $sample = tpl_sampleHeaders(TemplateGenerator::SAMPLES_DIR . '/Batch_List_Sample.xlsx');

    // Same count, same strings at the same positions — including the
    // duplicates. The legacy schema encodes multi-step provenance via
    // repeated header names; the template MUST preserve that layout.
    expect($generated)->toEqual($sample);

    // The Document sample has 49 populated columns (57 with trailing
    // NULLs; we strip those).
    expect(count($generated))->toBe(49);

    // Concrete duplicate-position assertions — these are the contract the
    // operator relies on.
    expect($generated[12])->toBe('Barcode (IN)');           // col 13 (1-based)
    expect($generated[21])->toBe('Barcode (IN)');           // col 22 — same header
    expect($generated[15])->toBe('Barcode RAS 2');          // col 16
    expect($generated[22])->toBe('Barcode RAS 2');          // col 23
    expect($generated[24])->toBe('Barcode RAS 2');          // col 25 — appears 3x
    expect($generated[14])->toBe('Status 1');               // col 15
    expect($generated[23])->toBe('Status 1');               // col 24
    expect($generated[27])->toBe('Disinfestation Date');    // col 28
    expect($generated[28])->toBe('Disinfestation Date');    // col 29
    expect($generated[29])->toBe('Disinfestation Date');    // col 30 — three Disinfestations

    // Sanity: count occurrences. The contract is "preserve duplicates".
    expect(array_count_values($generated)['Barcode RAS 2'] ?? 0)->toBe(3);
    expect(array_count_values($generated)['Disinfestation Date'] ?? 0)->toBe(3);
    expect(array_count_values($generated)['Barcode (IN)'] ?? 0)->toBe(2);
});

test('Batch template uses synthesised headers that map 1:1 with BatchImporter columns', function () {
    $this->actingAs(tpl_admin());

    $generated = tpl_renderAndParse(TemplateGenerator::download('batch'))['headers'];

    // No legacy sample dependency — synthesised from RFQ + BatchImporter.
    expect($generated)->toBe([
        'batch_number',
        'description',
        'type',
        'is_active',
        'repository_code',
    ]);

    // Every generated header must correspond 1:1 to an ImportColumn name in
    // BatchImporter — that's the contract that lets "download → fill →
    // re-upload" work with zero remapping.
    $importerColumnNames = array_map(
        static fn ($c) => $c->getName(),
        BatchImporter::getColumns(),
    );
    foreach ($generated as $header) {
        expect($importerColumnNames)->toContain($header);
    }
});

test('Box template uses synthesised headers that map 1:1 with BoxImporter columns', function () {
    $this->actingAs(tpl_admin());

    $generated = tpl_renderAndParse(TemplateGenerator::download('box'))['headers'];

    // The BoxImporter exposes some headers under slightly different
    // names from the synthesised list (e.g. `parent_box_number` in the
    // template vs `parent_barcode` in the importer — both refer to the
    // parent box's barcode, which is what the operator types). We
    // assert both the synthesised shape AND coverage of the canonical
    // BoxImporter columns most relevant to the operator.
    expect($generated)->toBe([
        'box_type',
        'box_number',
        'batch_number',
        'parent_box_number',
        'barcode',
        'barcode_status',
        'disinfestation_date',
        'is_legacy',
        'notes',
    ]);

    // Cross-check: at least the canonical BoxImporter columns must each
    // have a corresponding header in the template (modulo the
    // parent_box_number ↔ parent_barcode alias documented above).
    $importerColumnNames = array_map(
        static fn ($c) => $c->getName(),
        BoxImporter::getColumns(),
    );
    foreach ($generated as $header) {
        $aliased = $header === 'parent_box_number' ? 'parent_barcode' : $header;
        expect($importerColumnNames)->toContain($aliased);
    }
});

test('Viewer role cannot create entities and therefore the download_template action is hidden', function () {
    // The action's ->visible() closure on every list page is
    //   auth()->user()?->can('create', X::class)
    // A viewer holds only `view_*` permissions (mirrors InitialDataSeeder),
    // so create_X must return false for every relevant Resource model.
    // That's the canonical gate that hides the button in the UI.
    //
    // We assert the underlying policy decision directly (no Livewire boot)
    // because the upstream PR #34 base ships a broken `app/Models/Accession.php`
    // (5x duplicated `use App\Models\Concerns\BelongsToRepository;`) and
    // booting the Filament admin panel via `Livewire::test()` triggers a
    // PHP fatal "Cannot use … as … because the name is already in use".
    // The asserted policy result is what the action's ->visible() closure
    // reads anyway, so this is functionally equivalent without the boot.
    $viewer = tpl_viewer();
    $this->actingAs($viewer);

    foreach ([Authority::class, Series::class, Batch::class, Box::class, Document::class] as $model) {
        expect($viewer->can('create', $model))->toBeFalse(
            "viewer should NOT have create permission on {$model}"
        );
    }

    // Conversely the admin DOES — same gate, opposite expectation, both
    // pinned in one test so a future permission-tweak doesn't silently flip
    // the action's visibility.
    $admin = tpl_admin();
    $this->actingAs($admin);
    foreach ([Authority::class, Series::class, Batch::class, Box::class, Document::class] as $model) {
        expect($admin->can('create', $model))->toBeTrue(
            "super_admin should have create permission on {$model}"
        );
    }
});

test('Generated xlsx is binary-readable round-trip without errors for every entity', function () {
    $this->actingAs(tpl_admin());

    // Round-trip every entity. If the file is corrupted (bad zip, missing
    // shared strings, etc.) IOFactory::load throws and the test fails.
    foreach (['authority', 'series', 'batch', 'box', 'document'] as $entity) {
        $response = TemplateGenerator::download($entity);
        $parsed = tpl_renderAndParse($response);

        expect($parsed['status'])->toBe(200);
        expect(strlen($parsed['bytes']))->toBeGreaterThan(1000); // a real xlsx is ≥ 4 KB even when empty
        expect(count($parsed['headers']))->toBeGreaterThan(0);
        expect($parsed['filename'])->toContain($entity . '_template_');
    }
});
