<?php

declare(strict_types=1);

namespace App\Filament\Resources\DocumentResource\Pages;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Concerns\FiltersExportColumns;
use App\Filament\Imports\DocumentImporter;
use App\Filament\Resources\DocumentResource;
use App\Models\CustomFieldDefinition;
use App\Models\Document;
use App\Support\BulkImport\TemplateGenerator;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use HayderHatem\FilamentExcelImport\Actions\FullImportAction;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListDocuments extends ListRecords
{
    use ExplainsPage;
    use FiltersExportColumns;

    protected static string $resource = DocumentResource::class;

    /**
     * Stream the currently filtered Document list as CSV.
     * - Honours every active filter / search term (uses the same query the table
     *   is currently displaying via getFilteredTableQuery()).
     * - Uses fputcsv + streamDownload to stay memory-safe for 50k+ rows.
     * - Loads only the columns we render; eager-loads relations to avoid N+1.
     */
    public function exportToCsv(): StreamedResponse
    {
        // Defense-in-depth: even if the action button is bypassed (direct method
        // call, Livewire payload tampering, …), the method itself rejects.
        abort_unless(auth()->user()?->can('view_any_document'), 403, 'Not authorized to export documents.');

        // Full column map in canonical order. Keys are the field names consulted
        // by FieldPermissions; values are the CSV header labels.
        // Filter through FieldPermissions for the current user (RFQ §3.1.4).
        $columns = self::visibleExportColumns([
            'identifier' => 'Identifier',
            'document_type' => 'Type',
            'creator' => 'Creator(s)',
            'series' => 'Series',
            'batch' => 'Batch',
            'current_box' => 'Current box',
            'disinfestation_date' => 'Disinfestation date',
            'notes' => 'Notes',
        ]);

        // Append active custom-field columns after the fixed ones.
        // Column key: 'cf_<key>' to avoid collision with any fixed column name.
        // Column header: definition label (human-readable, matches the UI).
        $customFieldDefs = $this->getActiveCustomFieldDefinitions();
        $customFieldColumns = [];
        foreach ($customFieldDefs as $def) {
            $customFieldColumns['cf_' . $def->key] = $def->label;
        }

        $allColumns = array_merge($columns, $customFieldColumns);

        $user = auth()->user();
        $repoCode = optional($user?->defaultRepository ?? null)->code ?? 'all';
        $filename = sprintf(
            'documents_%s_%s.csv',
            Str::slug($repoCode, '_'),
            now()->format('Ymd_His'),
        );

        // Snapshot the filtered query NOW (before streaming starts) so it
        // reflects the user's current filters / search / sort.
        $query = $this->getFilteredTableQuery()
            ->with([
                'series:id,code',
                'batch:id,batch_number',
                'currentBox:id,box_number',
                'authorities:id,surname',
                // Eager-load custom field values with their definitions so the
                // CSV row builder can resolve typed values without N+1.
                'customFieldValues.definition',
            ]);

        return response()->streamDownload(function () use ($query, $allColumns, $customFieldDefs): void {
            $out = fopen('php://output', 'wb');
            // UTF-8 BOM — Excel on Windows needs it for non-ASCII (Maltese accents).
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values($allColumns));

            $query->orderBy('id')->chunk(500, function ($documents) use ($out, $allColumns, $customFieldDefs): void {
                /** @var Collection<int, Document> $documents */
                foreach ($documents as $doc) {
                    // Build a full cell map keyed by field name, then emit only
                    // the visible columns (same keys as $allColumns) in order.
                    $allCells = [
                        'identifier' => $this->sanitizeCsvCell($doc->identifier),
                        'document_type' => $this->sanitizeCsvCell($doc->document_type),
                        'creator' => $this->sanitizeCsvCell(
                            $doc->authorities->pluck('surname')->filter()->implode('; ')
                        ),
                        'series' => $this->sanitizeCsvCell($doc->series?->code),
                        // batch_number is an integer in DB — safe, but cast to string for fputcsv.
                        'batch' => (string) ($doc->batch?->batch_number ?? ''),
                        'current_box' => $this->sanitizeCsvCell($doc->currentBox?->box_number),
                        // Date in canonical Y-m-d form — never starts with a CSV-dangerous char.
                        'disinfestation_date' => $doc->disinfestation_date ? $doc->disinfestation_date->format('Y-m-d') : '',
                        'notes' => $this->sanitizeCsvCell($doc->notes),
                    ];

                    // Append custom-field values. Use the already-eager-loaded
                    // customFieldValues collection to avoid per-row queries.
                    foreach ($customFieldDefs as $def) {
                        $valueModel = $doc->customFieldValues
                            ->firstWhere('custom_field_definition_id', $def->id);
                        $typed = $valueModel?->getTypedValueAttribute();
                        $cellValue = '';
                        if ($typed !== null) {
                            $cellValue = match ($def->type) {
                                'boolean' => $typed ? '1' : '0',
                                'date' => $typed instanceof Carbon ? $typed->toDateString() : (string) $typed,
                                'datetime' => $typed instanceof Carbon ? $typed->toDateTimeString() : (string) $typed,
                                default => (string) $typed,
                            };
                            $cellValue = $this->sanitizeCsvCell($cellValue);
                        }
                        $allCells['cf_' . $def->key] = $cellValue;
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
            // RFQ §3.1.3 — Bulk Import v2.
            // Per-column dropdown mapping, FK resolution by name (Series via
            // code, Authority via R-code AND surname), F-009 ambiguous-skip
            // policy. See {@see DocumentImporter} for the column declarations.
            FullImportAction::make()
                ->importer(DocumentImporter::class)
                ->label('Import Excel / CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->chunkSize(500)
                ->maxRows(50000)
                ->streamingThreshold(10 * 1024 * 1024)
                ->visible(fn () => auth()->user()?->can('create', Document::class) ?? false),

            Actions\Action::make('export_csv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->authorize(fn () => auth()->user()?->can('view_any_document') ?? false)
                ->action(fn () => $this->exportToCsv()),

            // Blank xlsx whose row-1 headers match Batch_List_Sample.xlsx
            // verbatim — including the legitimately-duplicated headers
            // ("Barcode (IN)", "Barcode RAS 2", "Status 1/2",
            // "Disinfestation Date") that encode multi-step provenance.
            // See TemplateGenerator's docblock for the rationale.
            Actions\Action::make('download_template')
                ->label('Download template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => TemplateGenerator::download('document'))
                ->visible(fn () => auth()->user()?->can('create', Document::class) ?? false),

            Actions\CreateAction::make(),
        ];
    }

    /**
     * Return the active custom-field definitions for the current user's default
     * repository (document entity type), ordered by sort_order. Used by both
     * exportToCsv() (column headers) and the row builder (cell values).
     *
     * Returns an empty Collection when the user has no default repository or
     * when no active definitions exist — safe to iterate unconditionally.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, CustomFieldDefinition>
     */
    private function getActiveCustomFieldDefinitions(): \Illuminate\Database\Eloquent\Collection
    {
        $repositoryId = auth()->user()?->default_repository_id;
        if ($repositoryId === null) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        return CustomFieldDefinition::query()
            ->where('repository_id', $repositoryId)
            ->where('entity_type', 'document')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
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
     *
     * Apply ONLY to user-controllable string fields (identifier, notes,
     * surnames, codes). Do NOT apply to IDs, integers, formatted dates — those
     * cannot start with a dangerous character.
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
