<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Pages\Reports\Concerns\HasReportTemplates;
use App\Filament\Pages\Reports\Filters\DateRangeFilter;
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
 * RFQ §3.1.10 #3 — Documents by Series.
 *
 * Aggregates `documents` by `series_id`, joined to `series` so the row
 * shows the series CODE (R, REG, RWL, O, …) rather than the surrogate
 * id. `series_id` is NOT NULL on the canonical schema, so we don't
 * expect an "(unassigned)" bucket — but we still LEFT JOIN to be safe
 * against test fixtures.
 *
 * Multi-tenancy: identical to {@see DocumentsByBatchReport} — the
 * BelongsToRepository scope on Document filters per-tenant.
 */
class DocumentsBySeriesReport extends Page implements HasTable
{
    use HasReportTemplates;
    use InteractsWithTable;

    /** @see ReportTemplate::SOURCES */
    public const REPORT_SOURCE = ReportTemplate::SOURCE_DOCUMENTS_BY_SERIES;

    protected string $view = 'filament.pages.reports.table';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'Documents by series';

    protected static ?string $slug = 'reports/documents-by-series';

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
                Tables\Columns\TextColumn::make('series_code')
                    ->label('Code')
                    ->sortable()
                    ->searchable()
                    ->formatStateUsing(fn ($state): string => $state === null ? '(unassigned)' : (string) $state),

                Tables\Columns\TextColumn::make('series_title')
                    ->label('Title')
                    ->limit(60)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('document_count')
                    ->label('# Documents')
                    ->alignEnd()
                    ->sortable()
                    ->numeric(),
            ])
            ->filtersFormColumns(2)
            ->filters([
                // ── Date range pickers ──
                DateRangeFilter::make('document_dates')
                    ->label('Document dates')
                    ->column('documents.dates_start'),

                DateRangeFilter::make('created_range')
                    ->label('Created in system')
                    ->column('documents.created_at'),

                DateRangeFilter::make('updated_range')
                    ->label('Last updated')
                    ->column('documents.updated_at'),

                DateRangeFilter::make('disinfestation_range')
                    ->label('Disinfestation date')
                    ->column('documents.disinfestation_date'),

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

                // ── Ternary filters ──
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
            slug: 'documents-by-series',
            columns: [
                'Code' => 'series_code',
                'Title' => 'series_title',
                '# Documents' => 'document_count',
            ],
            rows: $this->collectRows(),
        );
    }

    public function exportPdf(): Response
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::renderPdf(
            title: 'Documents by series',
            slug: 'documents-by-series',
            headers: ['Code', 'Title', '# Documents'],
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
            'Code' => fn (array $r): string => $r['series_code'] === null ? '(unassigned)' : (string) $r['series_code'],
            'Title' => fn (array $r) => $r['series_title'],
            '# Documents' => fn (array $r): int => (int) ($r['document_count'] ?? 0),
        ];
    }

    public function getReportTitle(): string
    {
        return 'Documents by series';
    }

    public function getReportSlug(): string
    {
        return 'documents-by-series';
    }

    public function mount(): void
    {
        $this->applyTemplateFromQuery();
    }

    /**
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

    protected function reportQuery(): Builder
    {
        return Document::query()
            ->leftJoin('series', 'series.id', '=', 'documents.series_id')
            ->selectRaw(
                'documents.series_id as id,'
                . ' series.code as series_code,'
                . ' series.title as series_title,'
                . ' COUNT(documents.id) as document_count'
            )
            ->groupBy('documents.series_id', 'series.code', 'series.title');
    }

    /**
     * @return array<int, array<int, scalar|null>>
     */
    protected function collectRows(): array
    {
        $rows = [];
        $records = $this->reportQuery()->orderByDesc('document_count')->get();

        foreach ($records as $r) {
            $attrs = $r->getAttributes();
            $code = $attrs['series_code'] ?? null;

            $rows[] = [
                $code === null ? '(unassigned)' : (string) $code,
                isset($attrs['series_title']) ? (string) $attrs['series_title'] : null,
                (int) ($attrs['document_count'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * Associative version used by the Excel exporter.
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
                'series_code' => $attrs['series_code'] ?? null,
                'series_title' => $attrs['series_title'] ?? null,
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
