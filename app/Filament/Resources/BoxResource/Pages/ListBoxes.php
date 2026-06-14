<?php

declare(strict_types=1);

namespace App\Filament\Resources\BoxResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Imports\BoxImporter;
use App\Filament\Resources\BoxResource;
use App\Models\Box;
use App\Models\CustomFieldDefinition;
use App\Support\BulkImport\TemplateGenerator;
use App\Support\CustomFields\CustomFieldCsv;
use App\Support\CustomFields\CustomFieldResolver;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use HayderHatem\FilamentExcelImport\Actions\FullImportAction;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListBoxes extends ListRecords
{
    use ExplainsPage;

    protected static string $resource = BoxResource::class;

    /**
     * Stream the currently filtered Box list as CSV.
     *
     * Fixed columns come first in canonical order, followed by any active
     * custom-field columns for the 'box' entity type in the active
     * repository (CustomFieldResolver::definitionsFor).
     *
     * Note: Box has no direct repository_id — the repo is derived via the
     * batch relation. Custom-field resolution uses the active repo from the
     * request context (topbar switcher / user default).
     *
     * Value formatting: boolean→1/0, date→Y-m-d, datetime→Y-m-d H:i:s,
     * else (string). User-controlled strings are sanitised against CSV
     * injection (CWE-1236 / OWASP) via sanitizeCsvCell().
     *
     * Eager-loads batch (for batch_number) + customFieldValues.definition
     * to avoid N+1.
     */
    public function exportToCsv(): StreamedResponse
    {
        abort_unless(auth()->user()?->can('view_any_box'), 403, 'Not authorized to export boxes.');

        $columns = [
            'box_number' => 'Box number',
            'box_type' => 'Box type',
            'batch_number' => 'Batch number',
            'barcode' => 'Barcode',
            'barcode_status' => 'Barcode status',
            'disinfestation_date' => 'Disinfestation date',
            'is_legacy' => 'Is legacy?',
            'notes' => 'Notes',
        ];

        // Append active custom-field columns after the fixed ones.
        $customFieldDefs = CustomFieldResolver::definitionsFor('box');
        $customFieldColumns = [];
        foreach ($customFieldDefs as $def) {
            $customFieldColumns['cf_' . $def->key] = $def->label;
        }

        $allColumns = array_merge($columns, $customFieldColumns);

        $user = auth()->user();
        $repoCode = ($user?->defaultRepository ?? null)?->code ?? 'all';
        $filename = sprintf(
            'boxes_%s_%s.csv',
            Str::slug($repoCode, '_'),
            now()->format('Ymd_His'),
        );

        $query = $this->getFilteredTableQuery()
            ->with([
                // batch is needed for batch_number in the fixed columns.
                'batch:id,batch_number',
                // Eager-load custom field values with their definitions so the
                // CSV row builder can resolve typed values without N+1.
                'customFieldValues.definition',
            ]);

        return response()->streamDownload(function () use ($query, $allColumns, $customFieldDefs): void {
            $out = fopen('php://output', 'wb');
            // UTF-8 BOM — Excel on Windows needs it for non-ASCII characters.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values($allColumns), escape: '\\');

            $query->orderBy('id')->chunk(500, function ($boxes) use ($out, $allColumns, $customFieldDefs): void {
                foreach ($boxes as $box) {
                    /** @var Box $box */
                    $allCells = [
                        'box_number' => $this->sanitizeCsvCell($box->box_number),
                        'box_type' => $this->sanitizeCsvCell($box->box_type),
                        // batch_number is an integer — safe, no injection risk.
                        'batch_number' => (string) ($box->batch?->batch_number ?? ''),
                        'barcode' => $this->sanitizeCsvCell($box->barcode),
                        'barcode_status' => $this->sanitizeCsvCell($box->barcode_status),
                        // Date in canonical Y-m-d form — never starts with a dangerous char.
                        'disinfestation_date' => $box->disinfestation_date
                            ? $box->disinfestation_date->format('Y-m-d')
                            : '',
                        // Boolean cast to 1/0 for spreadsheet compatibility.
                        'is_legacy' => $box->is_legacy ? '1' : '0',
                        'notes' => $this->sanitizeCsvCell($box->notes),
                    ];

                    foreach ($customFieldDefs as $def) {
                        /** @var CustomFieldDefinition $def */
                        $valueModel = $box->customFieldValues
                            ->firstWhere('custom_field_definition_id', $def->id);
                        $typed = $valueModel?->getTypedValueAttribute();
                        $raw = CustomFieldCsv::format($def, $typed);
                        $allCells['cf_' . $def->key] = $raw !== '' ? $this->sanitizeCsvCell($raw) : '';
                    }

                    fputcsv($out, array_intersect_key($allCells, $allColumns), escape: '\\');
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
     * Header actions for the Boxes list page.
     *
     * RFQ rules enforced by the importer:
     *  - #3 IN_SITU / NRA boxes require a parent RAS box (rejected with a
     *    per-row validation error otherwise).
     *  - #4 MAV / STVC types force `is_legacy = true` on save.
     *  - #5 PERM_OUT requires `disinfestation_date` (validation error
     *    otherwise — MySQL would also refuse the insert, but we want a
     *    clean message for the operator).
     */
    protected function getHeaderActions(): array
    {
        return [
            FullImportAction::make()
                ->importer(BoxImporter::class)
                ->label('Import Excel / CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->chunkSize(500)
                ->maxRows(50000)
                ->streamingThreshold(10 * 1024 * 1024)
                ->visible(fn () => auth()->user()?->can('create', Box::class) ?? false),

            Actions\Action::make('export_csv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->authorize(fn () => auth()->user()?->can('view_any_box') ?? false)
                ->action(fn () => $this->exportToCsv()),

            // Synthesised xlsx — Box has no dedicated legacy sample.
            // Column names match BoxImporter so download → fill → re-upload
            // needs no remapping.
            Actions\Action::make('download_template')
                ->label('Download template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => TemplateGenerator::download('box'))
                ->visible(fn () => auth()->user()?->can('create', Box::class) ?? false),

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
