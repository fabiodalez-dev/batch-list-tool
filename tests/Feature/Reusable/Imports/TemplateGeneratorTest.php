<?php

declare(strict_types=1);

use App\Support\BulkImport\TemplateGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reusable: TemplateGenerator contract.
 *
 * Pins the 5-entity template generation: header shape, metadata sheet,
 * permissions / file-availability gates.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    bl_seedShieldPermissions();
});

it('TemplateGenerator: headersFor("batch") returns the synthetic Batch header set', function () {
    $headers = TemplateGenerator::headersFor('batch');
    expect($headers)->toContain('batch_number')
        ->and($headers)->toContain('type')
        ->and($headers)->toContain('repository_code');
});

it('TemplateGenerator: headersFor("box") includes parent_box_number and barcode_status, no longer disinfestation_date/Location', function () {
    // Client feedback 2026-08-04: disinfestation_date and Location moved OFF the
    // box template onto the document template.
    $headers = TemplateGenerator::headersFor('box');
    expect($headers)->toContain('parent_box_number')
        ->and($headers)->toContain('barcode_status')
        ->and($headers)->toContain('Tracking Note')
        ->and($headers)->not->toContain('disinfestation_date')
        ->and($headers)->not->toContain('Location');
});

it('TemplateGenerator: headersFor("authority") carries the ISAAR(CPF) contract', function () {
    // Client 2026-09-16: the nine RFQ columns plus a warrant number and the
    // ISAAR(CPF) descriptive block. "Identifier" was renamed in the same
    // request — the importer still answers to the old spelling, the template
    // does not offer it.
    $headers = TemplateGenerator::headersFor('authority');
    expect($headers)->toEqual(TemplateGenerator::AUTHORITY_HEADERS)
        ->and($headers)->toHaveCount(19)
        ->and($headers[0])->toBe('Authority Record Identifier (NAM)')
        ->and($headers)->not->toContain('Identifier')
        ->and($headers)->toContain('Alternative Identifier (Warrant Number)')
        ->and($headers)->toContain('Level of detail')
        ->and($headers)->toContain('Status')
        ->and($headers)->toContain('Notes');
});

it('TemplateGenerator: headersFor("series") starts at Identifier and includes the optional Repository and Parent columns', function () {
    $headers = TemplateGenerator::headersFor('series');
    expect($headers)->toEqual(TemplateGenerator::SERIES_HEADERS)
        ->and($headers)->toHaveCount(7)
        ->and($headers[0])->toBe('Identifier')
        ->and($headers)->toContain('Repository')
        // Client 2026-09-14 — carries the hierarchy, which "Level of
        // description" only names.
        ->and($headers)->toContain('Parent');
});

it('TemplateGenerator: headersFor("document") preserves the duplicated provenance headers', function () {
    $headers = TemplateGenerator::headersFor('document');
    expect($headers)->toEqual(TemplateGenerator::DOCUMENT_HEADERS)
        // Client 2026-08-31: was 48; removed 'Current Box' + 'Location' (−2),
        // added 'Part Number' (+1) → 47. ('Series' → 'Subseries' is a rename.)
        ->and($headers)->toHaveCount(47)
        // The multi-step provenance duplicate is preserved (position-independent).
        ->and(array_count_values($headers)['Barcode (IN)'] ?? 0)->toBe(2)
        ->and($headers)->not->toContain('RAS 1 Box Destroyed');
});

it('TemplateGenerator: headersFor("unknown") throws InvalidArgumentException', function () {
    expect(fn () => TemplateGenerator::headersFor('not-an-entity'))
        ->toThrow(InvalidArgumentException::class);
});

it('TemplateGenerator: download("batch") returns StreamedResponse with xlsx headers', function () {
    $response = TemplateGenerator::download('batch');
    expect($response)->toBeInstanceOf(StreamedResponse::class)
        ->and($response->headers->get('Content-Type'))
        ->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('TemplateGenerator: download() emits a Content-Disposition attachment header', function () {
    $response = TemplateGenerator::download('box');
    $disposition = $response->headers->get('Content-Disposition');
    expect($disposition)->toContain('attachment')
        ->and($disposition)->toContain('box_template_');
});

it('TemplateGenerator: download("unknown") throws InvalidArgumentException', function () {
    expect(fn () => TemplateGenerator::download('lol'))
        ->toThrow(InvalidArgumentException::class);
});

it('TemplateGenerator: round-trips by writing then reading the xlsx', function () {
    $response = TemplateGenerator::download('batch');
    ob_start();
    $response->sendContent();
    $bytes = ob_get_clean();
    // Static path under storage/framework/testing — not user input.
    $tmp = storage_path('framework/testing/tpl_batch_test.xlsx');
    @mkdir(dirname($tmp), 0775, true);
    file_put_contents($tmp, $bytes);

    $reader = IOFactory::createReaderForFile($tmp);
    $reader->setReadDataOnly(true);
    $sheet = $reader->load($tmp)->getActiveSheet();
    $headerA1 = $sheet->getCell('A1')->getValue();

    expect($headerA1)->toBe('batch_number');
});
