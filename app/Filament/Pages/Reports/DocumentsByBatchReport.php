<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Pages\Reports\Concerns\HasReportTemplates;
use App\Filament\Pages\Reports\Filters\DateRangeFilter;
use App\Models\Concerns\BelongsToRepository;
use App\Models\Document;
use App\Models\ReportTemplate;
use App\Support\Reports\ReportRenderer;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RFQ §3.1.10 #1 — Documents by Batch.
 *
 * Aggregates `documents` by `batch_id`, joined to `batches` so the row
 * shows the human-readable `batch_number` instead of the surrogate id.
 * Documents that are NOT yet associated with a batch are surfaced as a
 * single "(unassigned)" row so operators can see un-batched backlog at
 * a glance.
 *
 * Multi-tenancy: the COUNT subquery runs against {@see Document},
 * which carries the {@see BelongsToRepository}
 * global scope — admin / super_admin bypass, editor / viewer are
 * restricted to their tenant automatically.
 */
class DocumentsByBatchReport extends Page implements HasTable
{
    use HasReportTemplates;
    use InteractsWithTable;

    /** @see ReportTemplate::SOURCES */
    public const REPORT_SOURCE = ReportTemplate::SOURCE_DOCUMENTS_BY_BATCH;

    protected string $view = 'filament.pages.reports.table';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'Documents by batch';

    protected static ?string $slug = 'reports/documents-by-batch';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('view_any_report');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->reportQuery())
            ->defaultSort('document_count', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('batch_number')
                    ->label('Batch #')
                    ->sortable()
                    ->searchable()
                    ->formatStateUsing(fn ($state): string => $state === null ? '(unassigned)' : (string) $state),

                Tables\Columns\TextColumn::make('batch_description')
                    ->label('Description')
                    ->limit(60)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('batch_type')
                    ->label('Type')
                    ->badge()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('document_count')
                    ->label('# Documents')
                    ->alignEnd()
                    ->sortable()
                    ->numeric(),
            ])
            ->filtersFormColumns(2)
            ->filters([
                Tables\Filters\SelectFilter::make('batch_type')
                    ->label('Batch type')
                    ->options([
                        'MAIN_COLLECTION' => 'Main collection',
                        'NOTARY_ACCESSION' => 'Notary accession',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (empty($data['value'])) {
                            return $query;
                        }

                        return $query->where('batches.type', $data['value']);
                    }),

                // ── Date range pickers (universal, RFQ §3.2 reporting) ──
                DateRangeFilter::make('document_dates')
                    ->label('Document dates')
                    ->column('documents.dates_start')
                    ->columnLabel('Document dates'),

                DateRangeFilter::make('created_range')
                    ->label('Created in system')
                    ->column('documents.created_at')
                    ->columnLabel('Created'),

                DateRangeFilter::make('updated_range')
                    ->label('Last updated')
                    ->column('documents.updated_at')
                    ->columnLabel('Updated'),

                DateRangeFilter::make('disinfestation_range')
                    ->label('Disinfestation date')
                    ->column('documents.disinfestation_date')
                    ->columnLabel('Disinfested'),

                // ── Multi-select scopes ──
                Tables\Filters\SelectFilter::make('repository_id')
                    ->label('Repository')
                    ->relationship('repository', 'code')
                    ->searchable()
                    ->multiple()
                    ->preload()
                    ->query(function (Builder $query, array $data): Builder {
                        $values = $data['values'] ?? [];
                        if (empty($values)) {
                            return $query;
                        }

                        return $query->whereIn('documents.repository_id', $values);
                    }),

                Tables\Filters\SelectFilter::make('series_id')
                    ->label('Series')
                    ->relationship('series', 'code')
                    ->searchable()
                    ->multiple()
                    ->query(function (Builder $query, array $data): Builder {
                        $values = $data['values'] ?? [];
                        if (empty($values)) {
                            return $query;
                        }

                        return $query->whereIn('documents.series_id', $values);
                    }),

                Tables\Filters\SelectFilter::make('authorities')
                    ->label('Creators')
                    ->relationship('authorities', 'surname')
                    ->multiple()
                    ->preload()
                    ->searchable(),

                Tables\Filters\SelectFilter::make('document_type')
                    ->label('Document type')
                    ->options(fn (): array => self::documentTypeOptions())
                    ->multiple()
                    ->searchable()
                    ->query(function (Builder $query, array $data): Builder {
                        $values = $data['values'] ?? [];
                        if (empty($values)) {
                            return $query;
                        }

                        return $query->whereIn('documents.document_type', $values);
                    }),

                Tables\Filters\SelectFilter::make('barcode_status')
                    ->label('Barcode status')
                    ->options([
                        'IN' => 'IN',
                        'OUT' => 'OUT',
                        'PERM_OUT' => 'PERM_OUT',
                    ])
                    ->multiple()
                    ->query(function (Builder $query, array $data): Builder {
                        $values = $data['values'] ?? [];
                        if (empty($values)) {
                            return $query;
                        }

                        return $query->whereHas('currentBox', function (Builder $q) use ($values): void {
                            $q->whereIn('barcode_status', $values);
                        });
                    }),

                // ── Ternary filters (workflow / flags) ──
                Tables\Filters\TernaryFilter::make('has_open_flags')
                    ->label('Has open flags?')
                    ->placeholder('Any')
                    ->trueLabel('With open flags')
                    ->falseLabel('No open flags')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereHas('openFlags'),
                        false: fn (Builder $q): Builder => $q->whereDoesntHave('openFlags'),
                    ),

                Tables\Filters\TernaryFilter::make('uncatalogued')
                    ->label('Uncatalogued?')
                    ->placeholder('Any')
                    ->trueLabel('Uncatalogued')
                    ->falseLabel('Catalogued')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereNull('documents.catalogue_identifier'),
                        false: fn (Builder $q): Builder => $q->whereNotNull('documents.catalogue_identifier'),
                    ),

                Tables\Filters\TernaryFilter::make('torre')
                    ->label('Torre')
                    ->placeholder('Any')
                    ->trueLabel('Torre = yes')
                    ->falseLabel('Torre = no'),

                Tables\Filters\TernaryFilter::make('is_in_disinfestation')
                    ->label('Currently in disinfestation')
                    ->placeholder('Any')
                    ->trueLabel('Currently out')
                    ->falseLabel('Not currently out'),
            ])
            ->paginated([25, 50, 100, 'all']);
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::streamCsvFromRows(
            slug: 'documents-by-batch',
            columns: [
                'Batch #' => 'batch_number',
                'Description' => 'batch_description',
                'Type' => 'batch_type',
                '# Documents' => 'document_count',
            ],
            rows: $this->collectRows(),
        );
    }

    public function exportPdf(): Response
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::renderPdf(
            title: 'Documents by batch',
            slug: 'documents-by-batch',
            headers: ['Batch #', 'Description', 'Type', '# Documents'],
            rows: $this->collectRows(),
        );
    }

    public function exportXlsx(): BinaryFileResponse|StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::streamXlsx(
            rows: $this->collectRowsAsAssoc(),
            columns: $this->getXlsxColumns(),
            filename: ReportRenderer::filename($this->getReportSlug(), 'xlsx'),
            title: $this->getReportTitle(),
        );
    }

    /**
     * @return array<string, callable(array<string, mixed>): mixed>
     */
    public function getXlsxColumns(): array
    {
        return [
            'Batch #' => fn (array $r): string => $r['batch_number'] === null ? '(unassigned)' : (string) $r['batch_number'],
            'Description' => fn (array $r) => $r['batch_description'],
            'Type' => fn (array $r) => $r['batch_type'],
            '# Documents' => fn (array $r): int => (int) ($r['document_count'] ?? 0),
        ];
    }

    public function getReportTitle(): string
    {
        return 'Documents by batch';
    }

    public function getReportSlug(): string
    {
        return 'documents-by-batch';
    }

    public function mount(): void
    {
        $this->applyTemplateFromQuery();
    }

    /**
     * Distinct, non-null document_type values harvested from the live
     * dataset — keeps the dropdown current without a hard-coded enum.
     *
     * @return array<string, string>
     */
    protected static function documentTypeOptions(): array
    {
        return Document::query()
            ->whereNotNull('document_type')
            ->select('document_type')
            ->distinct()
            ->orderBy('document_type')
            ->pluck('document_type', 'document_type')
            ->all();
    }

    /**
     * SELECT documents.batch_id, batches.*, COUNT(documents.id)
     * FROM documents LEFT JOIN batches ON batches.id = documents.batch_id
     * GROUP BY documents.batch_id, batches.batch_number, batches.description, batches.type
     */
    protected function reportQuery(): Builder
    {
        return Document::query()
            ->leftJoin('batches', 'batches.id', '=', 'documents.batch_id')
            ->selectRaw(
                'documents.batch_id as id,'
                . ' batches.batch_number as batch_number,'
                . ' batches.description as batch_description,'
                . ' batches.type as batch_type,'
                . ' COUNT(documents.id) as document_count'
            )
            ->groupBy('documents.batch_id', 'batches.batch_number', 'batches.description', 'batches.type');
    }

    /**
     * Materialise the aggregate query into flat rows for export.
     * Reads via getAttributes() because the selectRaw column aliases
     * are not declared on the Document model (PHPStan-friendly).
     *
     * @return array<int, array<int, scalar|null>>
     */
    protected function collectRows(): array
    {
        $rows = [];
        $records = $this->reportQuery()->orderByDesc('document_count')->get();

        foreach ($records as $r) {
            $attrs = $r->getAttributes();
            $batchNumber = $attrs['batch_number'] ?? null;

            $rows[] = [
                $batchNumber === null ? '(unassigned)' : (string) $batchNumber,
                isset($attrs['batch_description']) ? (string) $attrs['batch_description'] : null,
                isset($attrs['batch_type']) ? (string) $attrs['batch_type'] : null,
                (int) ($attrs['document_count'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Associative version used by the Excel exporter — keeps the column
     * mapping declarative (closure receives a labelled row).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function collectRowsAsAssoc(): array
    {
        $rows = [];
        $records = $this->reportQuery()->orderByDesc('document_count')->get();

        foreach ($records as $r) {
            $attrs = $r->getAttributes();
            $rows[] = [
                'batch_number' => $attrs['batch_number'] ?? null,
                'batch_description' => $attrs['batch_description'] ?? null,
                'batch_type' => $attrs['batch_type'] ?? null,
                'document_count' => (int) ($attrs['document_count'] ?? 0),
            ];
        }

        return $rows;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->saveAsTemplateAction(),

            Action::make('exportCsv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => $this->exportCsv()),

            Action::make('exportXlsx')
                ->label('Export Excel')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->action(fn () => $this->exportXlsx()),

            Action::make('exportPdf')
                ->label('Export PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(fn () => $this->exportPdf()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        return [];
    }
}
