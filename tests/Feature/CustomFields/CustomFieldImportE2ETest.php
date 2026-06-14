<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Models\CustomFieldDefinition;
use App\Models\Document;
use App\Models\Repository;
use App\Models\Series;
use App\Support\ActiveRepository;
use App\Support\BulkImport\TemplateGenerator;
use App\Support\CustomFields\CustomFieldResolver;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| End-to-end custom-field import (real .xlsx round-trip)
|--------------------------------------------------------------------------
|
| Unlike the feature tests that hand the importer an inline data array, this
| exercises the *real file pipeline*:
|
|   1. TemplateGenerator::download('document') streams a genuine .xlsx (the
|      exact bytes the operator downloads), with the active repository's
|      custom-field column appended after the static legacy headers.
|   2. We persist those bytes to a temp file and re-read them with
|      PhpSpreadsheet — the same library the import action uses to parse an
|      uploaded workbook — to recover the header row.
|   3. We assert the custom column is physically present in the .xlsx header.
|   4. We then drive the DocumentImporter pipeline with a row keyed by that
|      header and assert the value lands in custom_field_values (EAV).
|
| This proves the whole chain: dynamic template generation → xlsx bytes →
| spreadsheet parse → column mapping → importer → typed EAV persistence.
*/

beforeEach(function () {
    bl_seedShieldPermissions();
    CustomFieldResolver::flush();
});

// Purge any temp workbook this test class created — runs even when an
// assertion above fails mid-test, so nothing is left in sys_get_temp_dir().
afterEach(function () {
    foreach (glob(sys_get_temp_dir() . '/cf_e2e_*') ?: [] as $leftover) {
        File::delete($leftover);
    }
});

/**
 * Capture the streamed .xlsx into a temp path and return [path, headerRow].
 *
 * @return array{0:string,1:array<int,string>}
 */
function cfe2e_downloadTemplate(string $entity): array
{
    $response = TemplateGenerator::download($entity);

    ob_start();
    $response->sendContent();
    $binary = (string) ob_get_clean();

    // tempnam() physically creates the file and returns its path; rename it to
    // a .xlsx so PhpSpreadsheet's extension-based reader detection works AND no
    // orphan extension-less file is left behind (concatenating '.xlsx' onto the
    // tempnam result would write a *second* file and leak the first).
    $base = tempnam(sys_get_temp_dir(), 'cf_e2e_');
    $path = $base . '.xlsx';
    File::move($base, $path);
    file_put_contents($path, $binary);

    // Re-read with PhpSpreadsheet — the same parser the import action uses for
    // an uploaded workbook. First (index 0) sheet is the data sheet.
    $sheet = IOFactory::load($path)->getSheet(0);
    $headerRow = [];
    foreach ($sheet->getRowIterator(1, 1) as $row) {
        foreach ($row->getCellIterator() as $cell) {
            $v = (string) $cell->getValue();
            if ($v !== '') {
                $headerRow[] = $v;
            }
        }
    }

    return [$path, $headerRow];
}

it('writes the active repository custom-field column into the real Document .xlsx and imports its value', function () {
    $admin = bl_actor('super_admin');
    /** @var Repository $repo */
    $repo = $admin->repositories()->first();

    // An active custom field on Document in this repository.
    $def = CustomFieldDefinition::create([
        'repository_id' => $repo->id,
        'entity_type' => 'document',
        'key' => 'ocr_state',
        'label' => 'OCR State',
        'type' => 'text',
        'is_active' => true,
        'sort_order' => 0,
    ]);

    // Operator has selected this repository in the topbar switcher.
    $this->actingAs($admin);
    session()->put(ActiveRepository::SESSION_KEY, $repo->id);
    CustomFieldResolver::flush();

    // 1–3. Generate the genuine .xlsx and confirm the custom column is in the header row.
    [$path, $headers] = cfe2e_downloadTemplate('document');

    expect($headers)->toContain('Identifier')          // a static legacy header survives
        ->and($headers)->toContain('OCR State');        // the custom column was appended

    // 4. Drive the real importer with a row keyed by the template headers.
    $import = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'document_template.xlsx',
        'file_path' => $path,
        'importer' => DocumentImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $admin->id,
    ]);

    // A Series is required to create a new Document.
    $series = Series::factory()->create(['code' => 'E2ESER']);

    // The row as the .xlsx delivers it — keyed by the (human) header labels.
    $data = [
        'Identifier' => 'R-E2E-1',
        'Series' => $series->code,
        'OCR State' => 'verified',
    ];

    // The column map is exactly what Filament's mapping step produces:
    // importer column name → the file header it was matched to. The custom
    // column's importer name is custom_field_{key}; its header is the label.
    $columnMap = [
        'identifier' => 'Identifier',
        'series' => 'Series',
        'custom_field_' . $def->key => 'OCR State',
    ];

    $importer = new DocumentImporter($import, $columnMap, []);
    $importer($data);

    // The Document exists and carries the typed custom value via the EAV.
    $doc = Document::query()->where('identifier', 'R-E2E-1')->first();
    expect($doc)->not->toBeNull();

    $stored = $doc->customFieldValues()
        ->where('custom_field_definition_id', $def->id)
        ->first();

    expect($stored)->not->toBeNull()
        ->and($stored->value)->toBe('verified');
});

it('does not append a custom column to the .xlsx when no active repository is selected', function () {
    $admin = bl_actor('super_admin');
    $repo = $admin->repositories()->first();

    CustomFieldDefinition::create([
        'repository_id' => $repo->id,
        'entity_type' => 'document',
        'key' => 'ocr_state',
        'label' => 'OCR State',
        'type' => 'text',
        'is_active' => true,
        'sort_order' => 0,
    ]);

    // No actingAs / no session active repo → resolver returns null → static headers only.
    CustomFieldResolver::flush();

    [$path, $headers] = cfe2e_downloadTemplate('document');

    expect($headers)->toContain('Identifier')
        ->and($headers)->not->toContain('OCR State');
});
