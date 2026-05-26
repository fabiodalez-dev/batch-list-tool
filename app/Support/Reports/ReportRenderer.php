<?php

declare(strict_types=1);

namespace App\Support\Reports;

use Barryvdh\DomPDF\Facade\Pdf;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RFQ §3.1.10 — shared rendering helper for the canned reports.
 *
 * Every Report Filament Page reuses this class for the two export
 * formats (CSV stream, PDF download). Centralising the formatting
 * here keeps the per-report Pages thin (they just declare columns
 * and a query) and guarantees consistent:
 *
 *   - filename pattern: <report-slug>_<date>.<ext>
 *   - CSV: UTF-8 BOM, sanitised cells (formula injection defence),
 *          chunked streaming (memory-safe for 50k+ rows)
 *   - PDF: A4 portrait via barryvdh/laravel-dompdf, shared blade
 *          (pdf-layout) with title / generated_at / footer / page
 *          numbers wired in CSS @page rules.
 *
 * The class is intentionally NOT a Filament concept — Pages call
 * static methods on it. That makes it trivially unit-testable
 * without booting Livewire.
 */
final class ReportRenderer
{
    /**
     * Stream a CSV download.
     *
     * The rowMapper closure receives one Eloquent model at a time
     * from the chunked iteration; per-page callers narrow the type
     * with an explicit `BoxMovement|Document|...` parameter on their
     * own closure. We deliberately keep the type loose here
     * (`Closure` with no template parameters) because PHPStan's
     * Builder template parameter is not covariant — declaring
     * `Closure(TModel)` would force every caller to use an
     * exactly-matching `Builder<TModel>` which the existing custom
     * Eloquent builders (DocumentBuilder) cannot satisfy.
     *
     * @param string $title human-readable report title
     * @param string $slug used in the filename, e.g. `documents-by-batch`
     * @param array<string, string> $columns ordered map of header label → internal key
     * @param Builder<Model> $query Eloquent builder; will be chunkById(500)-iterated
     * @param Closure $rowMapper turns each yielded model into a flat row
     */
    public static function streamCsv(
        string $title,
        string $slug,
        array $columns,
        Builder $query,
        Closure $rowMapper,
    ): StreamedResponse {
        $filename = self::filename($slug, 'csv');

        return response()->streamDownload(function () use ($columns, $query, $rowMapper): void {
            $out = fopen('php://output', 'wb');
            // UTF-8 BOM — Excel on Windows needs it for non-ASCII (Maltese accents).
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_keys($columns));

            $query->chunkById(500, function ($rows) use ($out, $rowMapper): void {
                foreach ($rows as $row) {
                    $cells = $rowMapper($row);
                    fputcsv($out, array_map(self::sanitizeCsvCell(...), $cells));
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Stream a CSV download from a pre-collected, summarised row set
     * (used by the "grouped" reports where the Page already computed
     * counts in PHP and there is no Builder to chunk over).
     *
     * @param array<string, string> $columns
     * @param iterable<int, array<int, scalar|null>> $rows
     */
    public static function streamCsvFromRows(
        string $slug,
        array $columns,
        iterable $rows,
    ): StreamedResponse {
        $filename = self::filename($slug, 'csv');

        return response()->streamDownload(function () use ($columns, $rows): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_keys($columns));

            foreach ($rows as $row) {
                fputcsv($out, array_map(self::sanitizeCsvCell(...), $row));
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Render a PDF download via the shared `reports/pdf-layout` blade.
     *
     * No chunking — DomPDF needs the full DOM in memory anyway. The
     * caller decides how many rows to render (typically capped at
     * ~5000); the CSV export is the right tool for "everything on
     * file" dumps.
     *
     * @param array<int, string> $headers ordered display labels
     * @param iterable<int, array<int, scalar|null>> $rows flat row data already in display order
     */
    public static function renderPdf(
        string $title,
        string $slug,
        array $headers,
        iterable $rows,
        ?string $subtitle = null,
    ): Response {
        $filename = self::filename($slug, 'pdf');

        $rowsArray = is_array($rows) ? $rows : iterator_to_array($rows, preserve_keys: false);

        $user = auth()->user();
        $generatedBy = 'system';
        if ($user !== null) {
            $name = $user->getAttribute('name');
            $email = $user->getAttribute('email');
            $generatedBy = is_string($name) && $name !== ''
                ? $name
                : (is_string($email) && $email !== '' ? $email : 'system');
        }

        $pdf = Pdf::loadView('reports.pdf-layout', [
            'title' => $title,
            'subtitle' => $subtitle,
            'headers' => $headers,
            'rows' => $rowsArray,
            'generated_at' => now()->format('Y-m-d H:i'),
            'generated_by' => $generatedBy,
            'total_rows' => count($rowsArray),
        ])->setPaper('a4', 'portrait');

        return response(
            $pdf->output(),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * Build the standardised filename `<slug>_<YYYYmmdd_His>.<ext>`.
     */
    public static function filename(string $slug, string $extension): string
    {
        return sprintf(
            '%s_%s.%s',
            Str::slug($slug, '_'),
            now()->format('Ymd_His'),
            $extension,
        );
    }

    /**
     * Sanitize a single CSV cell to neutralise spreadsheet formula injection.
     *
     * Same defence as ListDocuments::sanitizeCsvCell — prefix dangerous
     * leading characters (=, +, -, @, TAB, CR) with a single quote so
     * Excel / LibreOffice / Sheets treat the value as literal text.
     */
    public static function sanitizeCsvCell(mixed $value): string
    {
        $string = (string) ($value ?? '');
        if ($string === '') {
            return '';
        }
        if (preg_match('/^[=+\-@\t\r]/', $string)) {
            return "'" . $string;
        }

        return $string;
    }
}
