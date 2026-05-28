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

it('TemplateGenerator: headersFor("box") includes parent_box_number and barcode_status', function () {
    $headers = TemplateGenerator::headersFor('box');
    expect($headers)->toContain('parent_box_number')
        ->and($headers)->toContain('barcode_status')
        ->and($headers)->toContain('disinfestation_date');
});

it('TemplateGenerator: headersFor("authority") returns the in-repo legacy contract', function () {
    $headers = TemplateGenerator::headersFor('authority');
    expect($headers)->toEqual(TemplateGenerator::AUTHORITY_HEADERS)
        ->and($headers)->toHaveCount(9)
        ->and($headers[0])->toBe('Identifier');
});

it('TemplateGenerator: headersFor("series") returns the trimmed 6-column contract', function () {
    $headers = TemplateGenerator::headersFor('series');
    expect($headers)->toEqual(TemplateGenerator::SERIES_HEADERS)
        ->and($headers)->toHaveCount(6)
        ->and($headers[1])->toBe('Identifier');
});

it('TemplateGenerator: headersFor("document") preserves the duplicated provenance headers', function () {
    $headers = TemplateGenerator::headersFor('document');
    expect($headers)->toEqual(TemplateGenerator::DOCUMENT_HEADERS)
        ->and($headers)->toHaveCount(49)
        // "Barcode (IN)" appears at columns 13 and 22 (0-based 12 / 21)
        ->and($headers[12])->toBe('Barcode (IN)')
        ->and($headers[21])->toBe('Barcode (IN)');
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
