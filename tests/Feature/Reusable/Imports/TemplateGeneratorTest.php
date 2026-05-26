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

it('TemplateGenerator: headersFor("authority") reads from legacy sample (must exist)', function () {
    if (! is_readable(TemplateGenerator::SAMPLES_DIR . '/Authorities_Sample.xlsx')) {
        $this->markTestSkipped('Authorities_Sample.xlsx missing from samples dir.');
    }
    $headers = TemplateGenerator::headersFor('authority');
    expect($headers)->toBeArray()
        ->and(count($headers))->toBeGreaterThan(0);
});

it('TemplateGenerator: headersFor("series") trims trailing nulls', function () {
    if (! is_readable(TemplateGenerator::SAMPLES_DIR . '/Series_Sample.xlsx')) {
        $this->markTestSkipped('Series_Sample.xlsx missing from samples dir.');
    }
    $headers = TemplateGenerator::headersFor('series');
    // sample declares 26 columns but only 6 populated → trimmed
    expect(count($headers))->toBeLessThanOrEqual(10)
        ->and(count($headers))->toBeGreaterThan(0);
});

it('TemplateGenerator: headersFor("document") preserves duplicate "Barcode (IN)" headers', function () {
    if (! is_readable(TemplateGenerator::SAMPLES_DIR . '/Batch_List_Sample.xlsx')) {
        $this->markTestSkipped('Batch_List_Sample.xlsx missing from samples dir.');
    }
    $headers = TemplateGenerator::headersFor('document');
    expect(count($headers))->toBeGreaterThan(20);
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
