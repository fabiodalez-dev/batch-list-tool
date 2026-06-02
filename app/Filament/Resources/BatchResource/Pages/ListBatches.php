<?php

declare(strict_types=1);

namespace App\Filament\Resources\BatchResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Imports\BatchImporter;
use App\Filament\Resources\BatchResource;
use App\Models\Batch;
use App\Models\CustomFieldDefinition;
use App\Support\BulkImport\TemplateGenerator;
use App\Support\CustomFields\CustomFieldCsv;
use App\Support\CustomFields\CustomFieldResolver;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use HayderHatem\FilamentExcelImport\Actions\FullImportAction;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListBatches extends ListRecords
{
    use ExplainsPage;

    protected static string $resource = BatchResource::class;

    /**
     * Stream the currently filtered Batch list as CSV.
     *
     * Fixed columns come first in canonical order, followed by any active
     * custom-field columns for the 'batch' entity type in the active
     * repository (CustomFieldResolver::definitionsFor).
     *
     * Value formatting: boolean→1/0, date→Y-m-d, datetime→Y-m-d H:i:s,
     * else (string). User-controlled strings are sanitised against CSV
     * injection (CWE-1236 / OWASP) via sanitizeCsvCell().
     *
     * Eager-loads customFieldValues.definition to avoid N+1.
     */
    public function exportToCsv(): StreamedResponse
    {
        abort_unless(auth()->user()?->can('view_any_batch'), 403, 'Not authorized to export batches.');

        $columns = [
            'batch_number' => 'Batch number',
            'type' => 'Type',
            'description' => 'Description',
            'is_active' => 'Is active?',
        ];

        // Append active custom-field columns after the fixed ones.
        $customFieldDefs = CustomFieldResolver::definitionsFor('batch');
        $customFieldColumns = [];
        foreach ($customFieldDefs as $def) {
            $customFieldColumns['cf_' . $def->key] = $def->label;
        }

        $allColumns = array_merge($columns, $customFieldColumns);

        $user = auth()->user();
        $repoCode = optional($user?->defaultRepository ?? null)->code ?? 'all';
        $filename = sprintf(
            'batches_%s_%s.csv',
            Str::slug($repoCode, '_'),
            now()->format('Ymd_His'),
        );

        $query = $this->getFilteredTableQuery()
            ->with([
                // Eager-load custom field values with their definitions so the
                // CSV row builder can resolve typed values without N+1.
                'customFieldValues.definition',
            ]);

        return response()->streamDownload(function () use ($query, $allColumns, $customFieldDefs): void {
            $out = fopen('php://output', 'wb');
            // UTF-8 BOM — Excel on Windows needs it for non-ASCII characters.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values($allColumns));

            $query->orderBy('id')->chunk(500, function ($batches) use ($out, $allColumns, $customFieldDefs): void {
                foreach ($batches as $batch) {
                    /** @var Batch $batch */
                    $allCells = [
                        // batch_number is an integer — safe, no injection risk.
                        'batch_number' => (string) ($batch->batch_number ?? ''),
                        'type' => $this->sanitizeCsvCell($batch->type),
                        'description' => $this->sanitizeCsvCell($batch->description),
                        // Boolean cast to 1/0 for spreadsheet compatibility.
                        'is_active' => $batch->is_active ? '1' : '0',
                    ];

                    foreach ($customFieldDefs as $def) {
                        /** @var CustomFieldDefinition $def */
                        $valueModel = $batch->customFieldValues
                            ->firstWhere('custom_field_definition_id', $def->id);
                        $typed = $valueModel?->getTypedValueAttribute();
                        $raw = CustomFieldCsv::format($def, $typed);
                        $allCells['cf_' . $def->key] = $raw !== '' ? $this->sanitizeCsvCell($raw) : '';
                    }

                    fputcsv($out, array_intersect_key($allCells, $allColumns));
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
     * Header actions for the Batches list page.
     *
     * The importer enforces RFQ App.1 #1 (batch numbers 33/34/36 are
     * reserved and refused) client-side via Laravel `not_in:` rules so the
     * operator sees a per-row error in the failed-rows export instead of a
     * cryptic SQL CHECK violation from MySQL.
     */
    protected function getHeaderActions(): array
    {
        return [
            FullImportAction::make()
                ->importer(BatchImporter::class)
                ->label('Import Excel / CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->chunkSize(500)
                ->maxRows(50000)
                ->streamingThreshold(10 * 1024 * 1024)
                ->visible(fn () => auth()->user()?->can('create', Batch::class) ?? false),

            Actions\Action::make('export_csv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->authorize(fn () => auth()->user()?->can('view_any_batch') ?? false)
                ->action(fn () => $this->exportToCsv()),

            // Synthesised xlsx (no legacy sample for Batch alone — the
            // concept was buried inside Batch_List_Sample). Column names
            // match BatchImporter 1:1 so download → fill → re-upload needs
            // no remapping.
            Actions\Action::make('download_template')
                ->label('Download template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => TemplateGenerator::download('batch'))
                ->visible(fn () => auth()->user()?->can('create', Batch::class) ?? false),

            Actions\CreateAction::make(),
        ];
    }

    /**
     * Sanitize a single CSV cell to neutralize spreadsheet formula injection.
     *
     * fputcsv only escapes CSV grammar (commas, quotes, newlines) — it does NOT
     * defend against Excel/LibreOffice/Sheets interpreting a leading "=", "+",
     * "-", "@", TAB or CR as a formula trigger (CWE-1236 / OWASP "CSV Injection").
     *
     * OWASP-recommended mitigation: prefix dangerous leading characters with a
     * single quote (') — both Excel and LibreOffice treat that as "show literally,
     * do not evaluate".
     */
    private function sanitizeCsvCell(mixed $value): string
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
