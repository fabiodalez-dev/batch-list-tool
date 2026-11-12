<?php

declare(strict_types=1);

use App\Filament\Pages\Reports;
use App\Filament\Resources\Lookups\CurrentBoxTypeResource;
use App\Models\ReportTemplate;
use App\Support\Reports\ReportRenderer;
use Illuminate\Support\Facades\Route;

/*
 * Client 2026-11-12: "Export PDF seems to be giving some trouble" on the mass
 * migration report. DomPDF laid out every row as one table and ran out of the
 * host's 512 MB (Cellmap.php, Style.php in the error log). 500 rows in one
 * table took 721 MB; split every 50 rows, 1,000 rows take about 280 MB.
 */

/** @return list<array<int, string|int>> */
function pdfSizeRows(int $n): array
{
    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $rows[] = ["R{$i}/1", 'Cardboard Box', 'NRA' . $i, 'IN', 'Shelf ' . $i, 1, 12];
    }

    return $rows;
}

function pdfLayoutHtml(array $rows, ?string $notice = null): string
{
    return view('reports.pdf-layout', [
        'title' => 'Mass migration',
        'subtitle' => null,
        'headers' => ['Batch / Box', 'Current Box Type', 'Barcode', 'Status', 'Location', 'Matching documents', 'Documents in box'],
        'rows' => $rows,
        'generated_at' => 'now',
        'generated_by' => 'test',
        'total_rows' => count($rows),
        'group_by' => null,
        'group_counts' => [],
        'group_noun' => 'rows',
        'truncation_notice' => $notice,
    ])->render();
}

it('splits the PDF table every 50 rows, repeating the header', function (): void {
    $html = pdfLayoutHtml(pdfSizeRows(120));

    expect(substr_count($html, '<table>'))->toBe(3)
        ->and(substr_count($html, '<th>Batch / Box</th>'))->toBe(3)
        ->and(substr_count($html, '<td>R119/1</td>'))->toBe(1)
        ->and($html)->toContain('Total rows: 120');
});

it('keeps a PDF of up to 1,000 rows whole, with no notice', function (): void {
    [$rows, $notice] = ReportRenderer::limitPdfRows(pdfSizeRows(1000));

    expect($rows)->toHaveCount(1000)->and($notice)->toBeNull();
});

it('cuts a larger PDF at 1,000 rows and says so, pointing to Excel and CSV', function (): void {
    [$rows, $notice] = ReportRenderer::limitPdfRows(pdfSizeRows(1500));

    expect($rows)->toHaveCount(1000)
        ->and($rows[999][0])->toBe('R999/1')
        ->and($notice)->toBe('This PDF lists the first 1,000 of 1,500 rows. Use Export Excel or Export CSV for the full list.');

    expect(pdfLayoutHtml($rows, $notice))->toContain('This PDF lists the first 1,000 of 1,500 rows.');
});

it('says "or more" when the report hit its fetch limit', function (): void {
    [, $notice] = ReportRenderer::limitPdfRows(pdfSizeRows(ReportRenderer::PDF_FETCH_LIMIT));

    expect($notice)->toContain('of 5,000+ rows');
});

it('renders a 1,000-row PDF well inside the host memory limit', function (): void {
    $before = memory_get_peak_usage(true);

    $response = ReportRenderer::renderPdf('Mass migration', 'mass-migration', ['A', 'B', 'C', 'D', 'E', 'F', 'G'], pdfSizeRows(1000));

    expect($response->getStatusCode())->toBe(200)
        ->and(memory_get_peak_usage(true) - $before)->toBeLessThan(400 * 1024 * 1024);
})->group('slow');

/*
 * Client 2026-11-12: the menu said "Physical Box Type" while the page says
 * "Current Box Types"; and Report 8 (Disinfestation cycle plan) is removed,
 * Boxes pending disinfestation covers what the client needs.
 */
it('names the menu entry after the page: Current Box Types', function (): void {
    expect(CurrentBoxTypeResource::getNavigationLabel())->toBe('Current Box Types');
});

it('no longer offers the disinfestation cycle plan', function (): void {
    expect(ReportTemplate::SOURCES)->not->toContain('disinfestation_cycle')
        ->and(class_exists('App\\Filament\\Pages\\Reports\\DisinfestationCycleReport'))->toBeFalse()
        ->and(Route::has('filament.admin.pages.reports.disinfestation-cycle'))->toBeFalse()
        ->and(Reports::sourceLabel('disinfestation_cycle'))->toBe('disinfestation_cycle');
});
