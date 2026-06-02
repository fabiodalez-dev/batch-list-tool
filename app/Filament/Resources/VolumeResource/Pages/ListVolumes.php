<?php

declare(strict_types=1);

namespace App\Filament\Resources\VolumeResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Imports\VolumeImporter;
use App\Filament\Resources\VolumeResource;
use App\Models\CustomFieldDefinition;
use App\Models\Volume;
use App\Support\BulkImport\TemplateGenerator;
use App\Support\CustomFields\CustomFieldCsv;
use App\Support\CustomFields\CustomFieldResolver;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use HayderHatem\FilamentExcelImport\Actions\FullImportAction;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListVolumes extends ListRecords
{
    use ExplainsPage;

    protected static string $resource = VolumeResource::class;

    /**
     * Stream the currently filtered Volume list as CSV.
     *
     * Fixed columns (in order defined by TemplateGenerator::synthesiseVolumeHeaders):
     *   document_identifier, volume_number, dates_start, dates_end, notes
     *
     * Followed by any active custom-field columns for the 'volume' entity
     * type in the active repository (CustomFieldResolver::definitionsFor).
     *
     * Note: Volume has no direct repository_id — it is derived via the
     * parent document. Custom-field resolution uses the active repo from
     * the request context (topbar switcher / user default).
     *
     * Value formatting: boolean→1/0, date→Y-m-d, datetime→Y-m-d H:i:s,
     * else (string). User-controlled strings are sanitised against CSV
     * injection (CWE-1236 / OWASP) via sanitizeCsvCell().
     *
     * Eager-loads document (for identifier) + customFieldValues.definition
     * to avoid N+1.
     */
    public function exportToCsv(): StreamedResponse
    {
        abort_unless(auth()->user()?->can('view_any_volume'), 403, 'Not authorized to export volumes.');

        // Fixed column map: keys match TemplateGenerator::synthesiseVolumeHeaders()
        // so a downloaded template and a re-uploaded CSV stay in sync.
        $columns = [
            'document_identifier' => 'Document identifier',
            'volume_number' => 'Volume number',
            'dates_start' => 'Dates start',
            'dates_end' => 'Dates end',
            'notes' => 'Notes',
        ];

        // Append active custom-field columns after the fixed ones.
        $customFieldDefs = CustomFieldResolver::definitionsFor('volume');
        $customFieldColumns = [];
        foreach ($customFieldDefs as $def) {
            $customFieldColumns['cf_' . $def->key] = $def->label;
        }

        $allColumns = array_merge($columns, $customFieldColumns);

        $user = auth()->user();
        $repoCode = optional($user?->defaultRepository ?? null)->code ?? 'all';
        $filename = sprintf(
            'volumes_%s_%s.csv',
            Str::slug($repoCode, '_'),
            now()->format('Ymd_His'),
        );

        $query = $this->getFilteredTableQuery()
            ->with([
                // document is needed for the document identifier fixed column.
                'document:id,identifier',
                // Eager-load custom field values with their definitions so the
                // CSV row builder can resolve typed values without N+1.
                'customFieldValues.definition',
            ]);

        return response()->streamDownload(function () use ($query, $allColumns, $customFieldDefs): void {
            $out = fopen('php://output', 'wb');
            // UTF-8 BOM — Excel on Windows needs it for non-ASCII characters.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values($allColumns));

            $query->orderBy('id')->chunk(500, function ($volumes) use ($out, $allColumns, $customFieldDefs): void {
                foreach ($volumes as $volume) {
                    /** @var Volume $volume */
                    $allCells = [
                        'document_identifier' => $this->sanitizeCsvCell($volume->document?->identifier),
                        'volume_number' => $this->sanitizeCsvCell($volume->volume_number),
                        // Dates in canonical Y-m-d form — never start with a dangerous char.
                        'dates_start' => $volume->dates_start
                            ? $volume->dates_start->format('Y-m-d')
                            : '',
                        'dates_end' => $volume->dates_end
                            ? $volume->dates_end->format('Y-m-d')
                            : '',
                        'notes' => $this->sanitizeCsvCell($volume->notes),
                    ];

                    foreach ($customFieldDefs as $def) {
                        /** @var CustomFieldDefinition $def */
                        $valueModel = $volume->customFieldValues
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

    protected function getHeaderActions(): array
    {
        return [
            // Import Excel / CSV — mirrors ListBoxes pattern (FullImportAction).
            // RFQ rules enforced by VolumeImporter:
            //   - document_identifier must resolve to an existing document in
            //     the active repository (multi-tenant guard).
            //   - Custom-field columns are applied with merge semantics so a
            //     partial CSV does not wipe unmentioned fields.
            FullImportAction::make()
                ->importer(VolumeImporter::class)
                ->label('Import Excel / CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->chunkSize(500)
                ->maxRows(50000)
                ->streamingThreshold(10 * 1024 * 1024)
                ->visible(fn () => auth()->user()?->can('create_volume') ?? false),

            Actions\Action::make('export_csv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->authorize(fn () => auth()->user()?->can('view_any_volume') ?? false)
                ->action(fn () => $this->exportToCsv()),

            // Download a blank template whose column headers match the
            // VolumeImporter column keys exactly — so download → fill → re-upload
            // needs no column remapping. Volume headers are defined by
            // TemplateGenerator::synthesiseVolumeHeaders().
            Actions\Action::make('download_template')
                ->label('Download template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => TemplateGenerator::download('volume'))
                ->visible(fn () => auth()->user()?->can('create_volume') ?? false),

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
