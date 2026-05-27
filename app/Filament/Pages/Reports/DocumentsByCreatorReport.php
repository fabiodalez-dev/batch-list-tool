<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Pages\Reports\Concerns\HasReportTemplates;
use App\Filament\Pages\Reports\Filters\DateRangeFilter;
use App\Models\Authority;
use App\Models\Document;
use App\Models\ReportTemplate;
use App\Models\Repository;
use App\Models\Series;
use App\Support\Reports\ReportRenderer;
use Filament\Actions\Action;
use Filament\Forms;
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
 * RFQ §3.1.10 #2 — Documents by Creator (Notary / Authority).
 *
 * `documents.authorities` is a many-to-many via `document_authority`,
 * so a single Document can appear under TWO creators. The COUNT in
 * this report uses `COUNT(DISTINCT document_authority.document_id)`
 * which is what the RFQ wording asks for — "counts per Authority" —
 * but each Document is counted *once per Authority* it is attached
 * to, not deduplicated globally. The dedup test in the suite asserts
 * a Document with 2 authorities appears in BOTH rows (count 1+1=2).
 *
 * Multi-tenancy: Authorities are global reference data and have NO
 * RepositoryScope; the IN-clause against the visible-Document subquery
 * restricts the pivot rows we count to ones whose Document is visible
 * — which is the correct semantic (viewer in tenant A sees 0 docs for
 * an Authority whose docs all sit in tenant B).
 */
class DocumentsByCreatorReport extends Page implements HasTable
{
    use HasReportTemplates;
    use InteractsWithTable;

    /** @see ReportTemplate::SOURCES */
    public const REPORT_SOURCE = ReportTemplate::SOURCE_DOCUMENTS_BY_CREATOR;

    protected string $view = 'filament.pages.reports.table';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'Documents by creator / notary';

    protected static ?string $slug = 'reports/documents-by-creator';

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
                Tables\Columns\TextColumn::make('identifier')
                    ->label('Code')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('surname')
                    ->label('Surname')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('given_names')
                    ->label('Given names')
                    ->limit(40)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('document_count')
                    ->label('# Documents')
                    ->alignEnd()
                    ->sortable()
                    ->numeric(),
            ])
            ->filtersFormColumns(2)
            ->filters([
                Tables\Filters\Filter::make('only_with_documents')
                    ->label('Only authorities with documents')
                    ->toggle()
                    ->default()
                    ->query(function (Builder $query, array $data): Builder {
                        if (! ($data['isActive'] ?? false)) {
                            return $query;
                        }

                        return $query->having('document_count', '>', 0);
                    }),

                // ── Date range filters (constrain the underlying Document set) ──
                // Authorities is the outer query; documents.* is reachable only
                // via the document_authority pivot, so we apply each filter as
                // a `whereIn('document_authority.document_id', <doc subquery>)`.
                self::documentDateRangeFilter('document_dates', 'Document dates', 'dates_start'),
                self::documentDateRangeFilter('created_range', 'Document created in system', 'created_at'),
                self::documentDateRangeFilter('updated_range', 'Document last updated', 'updated_at'),
                self::documentDateRangeFilter('disinfestation_range', 'Disinfestation date', 'disinfestation_date'),

                // ── Multi-select scopes on Documents (via doc-id subquery) ──
                Tables\Filters\SelectFilter::make('repository_id')
                    ->label('Repository')
                    ->options(fn (): array => self::repositoryOptions())
                    ->multiple()
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => self::scopeViaDocumentColumn($query, 'repository_id', $data['values'] ?? [])),

                Tables\Filters\SelectFilter::make('series_id')
                    ->label('Series')
                    ->options(fn (): array => self::seriesOptions())
                    ->multiple()
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => self::scopeViaDocumentColumn($query, 'series_id', $data['values'] ?? [])),

                Tables\Filters\SelectFilter::make('document_type')
                    ->label('Document type')
                    ->options(fn (): array => self::documentTypeOptions())
                    ->multiple()
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => self::scopeViaDocumentColumn($query, 'document_type', $data['values'] ?? [])),

                Tables\Filters\SelectFilter::make('barcode_status')
                    ->label('Barcode status (current box)')
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

                        $docIds = Document::query()
                            ->whereHas('currentBox', fn (Builder $q) => $q->whereIn('barcode_status', $values))
                            ->select('documents.id');

                        return $query->whereIn('document_authority.document_id', $docIds);
                    }),

                // ── Ternary filters on the visible-document set ──
                Tables\Filters\TernaryFilter::make('has_open_flags')
                    ->label('Documents with open flags')
                    ->placeholder('Any')
                    ->trueLabel('Only with open flags')
                    ->falseLabel('Only without')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereIn(
                            'document_authority.document_id',
                            Document::query()->whereHas('openFlags')->select('documents.id'),
                        ),
                        false: fn (Builder $q): Builder => $q->whereIn(
                            'document_authority.document_id',
                            Document::query()->whereDoesntHave('openFlags')->select('documents.id'),
                        ),
                    ),

                Tables\Filters\TernaryFilter::make('uncatalogued')
                    ->label('Uncatalogued documents')
                    ->placeholder('Any')
                    ->trueLabel('Uncatalogued')
                    ->falseLabel('Catalogued')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereIn(
                            'document_authority.document_id',
                            Document::query()->whereNull('catalogue_identifier')->select('documents.id'),
                        ),
                        false: fn (Builder $q): Builder => $q->whereIn(
                            'document_authority.document_id',
                            Document::query()->whereNotNull('catalogue_identifier')->select('documents.id'),
                        ),
                    ),

                Tables\Filters\TernaryFilter::make('is_in_disinfestation')
                    ->label('Currently in disinfestation')
                    ->placeholder('Any')
                    ->trueLabel('Currently out')
                    ->falseLabel('Not currently out')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereIn(
                            'document_authority.document_id',
                            Document::query()->where('is_in_disinfestation', true)->select('documents.id'),
                        ),
                        false: fn (Builder $q): Builder => $q->whereIn(
                            'document_authority.document_id',
                            Document::query()->where('is_in_disinfestation', false)->select('documents.id'),
                        ),
                    ),
            ])
            ->paginated([25, 50, 100, 'all']);
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::streamCsvFromRows(
            slug: 'documents-by-creator',
            columns: [
                'Code' => 'identifier',
                'Surname' => 'surname',
                'Given names' => 'given_names',
                '# Documents' => 'document_count',
            ],
            rows: $this->collectRows(),
        );
    }

    public function exportPdf(): Response
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::renderPdf(
            title: 'Documents by creator / notary',
            slug: 'documents-by-creator',
            headers: ['Code', 'Surname', 'Given names', '# Documents'],
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
            'Code' => fn (array $r) => $r['identifier'],
            'Surname' => fn (array $r) => $r['surname'],
            'Given names' => fn (array $r) => $r['given_names'],
            '# Documents' => fn (array $r): int => (int) ($r['document_count'] ?? 0),
        ];
    }

    public function getReportTitle(): string
    {
        return 'Documents by creator / notary';
    }

    public function getReportSlug(): string
    {
        return 'documents-by-creator';
    }

    public function mount(): void
    {
        $this->applyTemplateFromQuery();
    }

    /**
     * Build a "documents.<column> in [from, to]" filter that targets the
     * outer Authority query through the document_authority pivot. The
     * pivot is already LEFT JOINed in {@see self::reportQuery()}.
     */
    protected static function documentDateRangeFilter(string $name, string $label, string $column): Tables\Filters\Filter
    {
        return Tables\Filters\Filter::make($name)
            ->label($label)
            ->columnSpan(['default' => 1, 'md' => 2])
            ->schema([
                Forms\Components\DatePicker::make('from')->label('From')->native(false)->closeOnDateSelection(),
                Forms\Components\DatePicker::make('to')->label('To')->native(false)->closeOnDateSelection(),
            ])
            ->query(function (Builder $query, array $data) use ($column): Builder {
                $from = DateRangeFilter::normalizeBoundary($data['from'] ?? null);
                $to = DateRangeFilter::normalizeBoundary($data['to'] ?? null, endOfDay: true);

                if ($from === null && $to === null) {
                    return $query;
                }

                $docIds = Document::query()
                    ->when($from !== null, fn ($q) => $q->where('documents.' . $column, '>=', $from))
                    ->when($to !== null, fn ($q) => $q->where('documents.' . $column, '<=', $to))
                    ->select('documents.id');

                return $query->whereIn('document_authority.document_id', $docIds);
            })
            ->indicateUsing(function (array $data) use ($label): array {
                $i = [];
                if (! empty($data['from'])) {
                    $i[] = $label . ' ≥ ' . $data['from'];
                }
                if (! empty($data['to'])) {
                    $i[] = $label . ' ≤ ' . $data['to'];
                }

                return $i;
            });
    }

    /**
     * Apply a multi-value filter on a Document column via the document_authority pivot.
     *
     * @param array<int, mixed> $values
     */
    protected static function scopeViaDocumentColumn(Builder $query, string $column, array $values): Builder
    {
        if (empty($values)) {
            return $query;
        }

        $docIds = Document::query()
            ->whereIn('documents.' . $column, $values)
            ->select('documents.id');

        return $query->whereIn('document_authority.document_id', $docIds);
    }

    /**
     * @return array<int|string, string>
     */
    protected static function repositoryOptions(): array
    {
        return Repository::query()
            ->orderBy('code')
            ->pluck('code', 'id')
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    protected static function seriesOptions(): array
    {
        return Series::query()
            ->orderBy('code')
            ->pluck('code', 'id')
            ->all();
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

    /**
     * SELECT authorities.*,
     *        COUNT(DISTINCT document_authority.document_id) AS document_count
     *   FROM authorities
     *   LEFT JOIN document_authority ON document_authority.authority_id = authorities.id
     *  WHERE document_authority.document_id IS NULL
     *        OR document_authority.document_id IN (visible Document ids)
     *  GROUP BY authorities.id
     */
    protected function reportQuery(): Builder
    {
        $visibleDocIds = Document::query()->select('documents.id');

        return Authority::query()
            ->leftJoin('document_authority', 'document_authority.authority_id', '=', 'authorities.id')
            ->whereNull('authorities.deleted_at')
            ->where(function (Builder $q) use ($visibleDocIds): void {
                $q->whereNull('document_authority.document_id')
                    ->orWhereIn('document_authority.document_id', $visibleDocIds);
            })
            ->selectRaw(
                'authorities.id as id,'
                . ' authorities.identifier as identifier,'
                . ' authorities.surname as surname,'
                . ' authorities.given_names as given_names,'
                . ' COUNT(DISTINCT document_authority.document_id) as document_count'
            )
            ->groupBy('authorities.id', 'authorities.identifier', 'authorities.surname', 'authorities.given_names');
    }

    /**
     * @return array<int, array<int, scalar|null>>
     */
    protected function collectRows(): array
    {
        $rows = [];
        $records = $this->reportQuery()
            ->orderByDesc('document_count')
            ->orderBy('surname')
            ->get();

        foreach ($records as $r) {
            $attrs = $r->getAttributes();
            $rows[] = [
                isset($attrs['identifier']) ? (string) $attrs['identifier'] : null,
                isset($attrs['surname']) ? (string) $attrs['surname'] : null,
                isset($attrs['given_names']) ? (string) $attrs['given_names'] : null,
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
        $records = $this->reportQuery()
            ->orderByDesc('document_count')
            ->orderBy('surname')
            ->get();

        foreach ($records as $r) {
            $attrs = $r->getAttributes();
            $rows[] = [
                'identifier' => $attrs['identifier'] ?? null,
                'surname' => $attrs['surname'] ?? null,
                'given_names' => $attrs['given_names'] ?? null,
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
