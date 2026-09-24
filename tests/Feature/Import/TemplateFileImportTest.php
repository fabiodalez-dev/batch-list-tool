<?php

declare(strict_types=1);

use App\Filament\Imports\AuthorityImporter;
use App\Filament\Imports\SeriesImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Authority;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\TemplateGenerator;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Spatie\Permission\Models\Role;

/*
 * Client request, 2026-09-16 — "fai i test con i template aggiornati".
 *
 * Every other import test in this suite starts from a PHP array: either
 * TemplateGenerator::headersFor() (a constant) or a hand-built column map.
 * Both skip the two steps the cataloguer actually performs — downloading the
 * .xlsx and letting the import action guess the mapping from its row 1.
 *
 * So these tests start from the bytes of the generated workbook. A header the
 * generator emits but no importer answers to would pass every other test in
 * the suite and still import as blank in production, silently.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    bl_seedShieldPermissions();
});

function tfi_admin(): User
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $u = User::factory()->create([
        'email' => 'template-file+' . uniqid() . '@test.local',
        'is_active' => true,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * Stream the real template to disk and hand back [path, row-1 headers].
 *
 * Same route the Download template button takes, so the bytes under test are
 * the bytes the cataloguer receives.
 *
 * @return array{0: string, 1: list<string>}
 */
function tfi_template(string $entity): array
{
    $response = TemplateGenerator::download($entity);

    ob_start();
    $response->sendContent();
    $binary = (string) ob_get_clean();

    // tempnam() creates the file; rename rather than concatenate, or the
    // extension-less original leaks.
    $base = tempnam(sys_get_temp_dir(), 'tfi_');
    $path = $base . '.xlsx';
    File::move($base, $path);
    file_put_contents($path, $binary);

    $headers = [];
    foreach (IOFactory::load($path)->getSheet(0)->getRowIterator(1, 1) as $row) {
        foreach ($row->getCellIterator() as $cell) {
            $v = (string) $cell->getValue();
            if ($v !== '') {
                $headers[] = $v;
            }
        }
    }

    return [$path, $headers];
}

/**
 * Fill the downloaded workbook in, save it, and read it back the way the
 * import action does — one associative row per sheet line, keyed by header.
 *
 * @param list<array<string, string>> $rows
 * @return list<array<string, string>>
 */
function tfi_fillAndReread(string $path, array $headers, array $rows): array
{
    $spreadsheet = IOFactory::load($path);
    $sheet = $spreadsheet->getSheet(0);

    foreach ($rows as $r => $row) {
        foreach ($headers as $c => $header) {
            $sheet->setCellValue(
                [$c + 1, $r + 2], // +1: 1-based columns. +2: row 1 is the header.
                $row[$header] ?? ''
            );
        }
    }

    (new XlsxWriter($spreadsheet))->save($path);

    $reread = IOFactory::load($path)->getSheet(0)->toArray();
    $headerRow = array_shift($reread);

    $out = [];
    foreach ($reread as $line) {
        $assoc = [];
        foreach ($headerRow as $i => $header) {
            if ((string) $header !== '') {
                $assoc[(string) $header] = (string) ($line[$i] ?? '');
            }
        }
        if (array_filter($assoc, fn ($v) => $v !== '') !== []) {
            $out[] = $assoc;
        }
    }

    return $out;
}

/**
 * Run the importer over rows keyed by sheet header, using the mapping the
 * wizard guesses — not a map written by hand in the test.
 *
 * @param list<array<string, string>> $rows
 * @param array<string, string> $columnMap
 */
function tfi_import(string $importerClass, array $rows, array $columnMap, int $userId): void
{
    $import = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'template.xlsx',
        'file_path' => '/tmp/template.xlsx',
        'importer' => $importerClass,
        'processed_rows' => 0,
        'total_rows' => count($rows),
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);

    $importer = new $importerClass($import, $columnMap, []);

    foreach ($rows as $row) {
        $importer($row);
    }
}

/* ── no orphan headers ────────────────────────────────────────────────── */

it('leaves no Authorities header in the downloaded file without an importer column', function (): void {
    [$path, $headers] = tfi_template('authority');

    $map = ImportWizard::guessColumnMap(AuthorityImporter::class, $headers);
    // guessColumnMap is attribute => header; invert to find headers nothing claimed.
    $claimed = array_values(array_filter($map, fn ($h) => $h !== null));
    $orphans = array_values(array_diff($headers, $claimed));

    // An orphan header is the worst kind of bug here: the column looks
    // supported, the cataloguer fills it in, and the import reports success
    // while dropping the value.
    expect($orphans)->toBe([]);
    expect($headers)->toHaveCount(19);

    File::delete($path);
});

it('leaves no Series header in the downloaded file without an importer column', function (): void {
    [$path, $headers] = tfi_template('series');

    $map = ImportWizard::guessColumnMap(SeriesImporter::class, $headers);
    $claimed = array_values(array_filter($map, fn ($h) => $h !== null));

    expect(array_values(array_diff($headers, $claimed)))->toBe([]);
    expect($headers)->toContain('Parent');

    File::delete($path);
});

it('offers the allowed values as a dropdown, so an unlisted spelling never reaches the importer', function (): void {
    [$path, $headers] = tfi_template('authority');

    $sheet = IOFactory::load($path)->getSheet(0);

    // Level of detail is column M, Status is column N — resolved from the
    // header row rather than hard-coded, so adding a column ahead of them
    // does not silently move the assertion onto the wrong cells.
    $columnOf = static function (string $header) use ($headers): string {
        $i = array_search($header, $headers, strict: true);
        expect($i)->not->toBeFalse();

        return Coordinate::stringFromColumnIndex($i + 1);
    };

    foreach ([
        'Level of detail' => ['Minimal', 'Partial', 'Full Level'],
        'Status' => ['In Progress', 'Complete'],
    ] as $header => $options) {
        $cell = $columnOf($header) . '2';
        $validation = $sheet->getCell($cell)->getDataValidation();

        expect($validation->getType())->toBe(DataValidation::TYPE_LIST);
        expect($validation->getShowDropDown())->toBeTrue();
        // Excel stores an inline list as one quoted, comma-separated string.
        expect($validation->getFormula1())->toBe('"' . implode(',', $options) . '"');
        // Every one of these fields is optional; an empty cell must stay legal.
        expect($validation->getAllowBlank())->toBeTrue();
        // The message has to name the options, or the refusal tells the
        // cataloguer nothing they can act on.
        foreach ($options as $option) {
            expect($validation->getError())->toContain($option);
        }
    }

    File::delete($path);
});

/* ── a filled-in workbook, imported ───────────────────────────────────── */

it('imports an Authorities workbook filled in as the cataloguer would', function (): void {
    $user = tfi_admin();
    $this->actingAs($user);

    [$path, $headers] = tfi_template('authority');

    $rows = tfi_fillAndReread($path, $headers, [
        [
            'NAM Authority Reference Code' => 'R-TPL-1',
            'Alternate Reference Code' => 'W-901',
            // Client 2026-09-22. Plural on purpose: several superseded codes in
            // one cell must survive whole, not be split at the separator.
            'Past Reference Code' => 'TMP-1962/17; OLD-REF 88',
            'Type of Entity' => 'Notary',
            'Creator Surname' => 'Bonnici',
            'Creator Name' => 'Ġużeppi',
            'Authorised form of name' => 'Bonnici, Ġużeppi',
            'Functions, occupations and activities' => 'Notarial practice',
            // Lower case on purpose: the sheet is typed by hand.
            'Level of detail' => 'full level',
            'Status' => 'in progress',
            'Rules and/or conventions' => 'ISAAR(CPF), 2nd ed.',
            'Date of creation' => '1607-1629',
            'Creator of record' => 'C. Ellul',
            'Notes' => 'Transferred from the Valletta deposit.',
        ],
    ]);

    expect($rows)->toHaveCount(1);

    $map = ImportWizard::guessColumnMap(AuthorityImporter::class, $headers);
    tfi_import(AuthorityImporter::class, $rows, $map, $user->id);

    $a = Authority::where('identifier', 'R-TPL-1')->first();

    expect($a)->not->toBeNull();
    expect($a->alternative_identifier_warrant)->toBe('W-901');
    expect($a->previous_temporary_identifiers)->toBe('TMP-1962/17; OLD-REF 88');
    expect($a->authorised_form_of_name)->toBe('Bonnici, Ġużeppi');
    expect($a->functions_occupations_activities)->toBe('Notarial practice');
    // Stored in the canonical spelling, not the operator's casing.
    expect($a->level_of_detail)->toBe('Full Level');
    expect($a->status)->toBe('In Progress');
    expect($a->rules_and_conventions)->toBe('ISAAR(CPF), 2nd ed.');
    expect($a->date_of_creation)->toBe('1607-1629');
    expect($a->creator_of_record)->toBe('C. Ellul');
    expect($a->notes)->toContain('Transferred from the Valletta deposit.');

    File::delete($path);
});

it('imports a Series workbook and builds the hierarchy from its Parent column', function (): void {
    $user = tfi_admin();
    $this->actingAs($user);

    [$path, $headers] = tfi_template('series');

    $rows = tfi_fillAndReread($path, $headers, [
        ['Identifier' => 'R', 'Standard title in English (Plural)' => 'Registers',
            'Level of description' => 'Series', 'Parent' => '',
            'Date of creation' => '1600-1800', 'Name of Inputter' => 'C. Ellul'],
        ['Identifier' => 'REG', 'Standard title in English (Plural)' => 'Register Volumes',
            'Level of description' => 'SubSeries', 'Parent' => 'R',
            'Date of creation' => '1607-1629', 'Name of Inputter' => 'C. Ellul'],
    ]);

    expect($rows)->toHaveCount(2);

    $map = ImportWizard::guessColumnMap(SeriesImporter::class, $headers);
    tfi_import(SeriesImporter::class, $rows, $map, $user->id);

    $parent = Series::where('code', 'R')->first();
    $child = Series::where('code', 'REG')->first();

    expect($parent)->not->toBeNull();
    expect($child)->not->toBeNull();
    // The whole point of the Parent column: one pass, no manual re-parenting.
    expect($child->parent_id)->toBe($parent->id);
    expect($child->level_of_description)->toBe('SubSeries');
    expect($child->date_of_creation)->toBe('1607-1629');
    expect($child->name_of_inputter)->toBe('C. Ellul');

    File::delete($path);
});
