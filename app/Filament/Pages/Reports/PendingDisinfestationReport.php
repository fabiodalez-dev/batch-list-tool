<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Pages\Reports\Concerns\CapsExportRows;
use App\Filament\Pages\Reports\Concerns\HasReportTemplates;
use App\Filament\Pages\Reports\Filters\DateRangeFilter;
use App\Filament\Widgets\PendingDisinfestationTable;
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
 * RFQ §3.1.10 #4 — Documents pending disinfestation.
 *
 * Surfaces the actionable backlog: any Document whose
 * `disinfestation_date` is NULL and whose current Box is NOT in
 * `PERM_OUT` (because PERM_OUT documents are off the floor and don't
 * need fumigation). Same filter shape as the
 * {@see PendingDisinfestationTable} dashboard
 * widget — but this Page renders the FULL list (not the top-10),
 * supports CSV / PDF export, and is sortable / filterable.
 *
 * Multi-tenancy: the BelongsToRepository scope on Document does the
 * heavy lifting — viewers / editors see only their tenants' pending docs.
 */
class PendingDisinfestationReport extends Page implements HasTable
{
    use CapsExportRows;
    use ExplainsPage;
    use HasReportTemplates;
    use InteractsWithTable;

    /** @see ReportTemplate::SOURCES */
    public const REPORT_SOURCE = ReportTemplate::SOURCE_PENDING_DISINFESTATION;

    protected string $view = 'filament.pages.reports.table';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'Documents pending disinfestation';

    protected static ?string $slug = 'reports/pending-disinfestation';

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
            ->defaultSort('created_at', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('identifier')
                    ->label('Identifier')
                    ->sortable()
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('document_type')
                    ->label('Type')
                    ->badge()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('series.code')
                    ->label('Series')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('batch.batch_number')
                    ->label('Batch')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('currentBox.box_number')
                    ->label('Current box')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('currentBox.barcode_status')
                    ->label('Box status')
                    ->badge()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Days waiting')
                    ->state(fn (Document $r): int => (int) round(now()->diffInDays($r->created_at, true)))
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state > 30 => 'danger',
                        $state > 7 => 'warning',
                        default => 'gray',
                    })
                    ->alignEnd()
                    ->sortable(),
            ])
            ->filtersFormColumns(2)
            ->filters([
                Tables\Filters\Filter::make('older_than_30_days')
                    ->label('> 30 days waiting')
                    ->toggle()
                    ->query(function (Builder $query, array $data): Builder {
                        if (! ($data['isActive'] ?? false)) {
                            return $query;
                        }

                        return $query->where('documents.created_at', '<=', now()->subDays(30));
                    }),

                // ── Date range pickers ──
                DateRangeFilter::make('created_range')
                    ->label('Added to queue')
                    ->column('documents.created_at')
                    ->columnLabel('Created'),

                DateRangeFilter::make('document_dates')
                    ->label('Document dates')
                    ->column('documents.dates_start')
                    ->columnLabel('Document dates'),

                DateRangeFilter::make('updated_range')
                    ->label('Last updated')
                    ->column('documents.updated_at')
                    ->columnLabel('Updated'),

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

                Tables\Filters\SelectFilter::make('batch_id')
                    ->label('Batch')
                    ->relationship('batch', 'batch_number')
                    ->searchable()
                    ->multiple()
                    ->query(function (Builder $query, array $data): Builder {
                        $values = $data['values'] ?? [];
                        if (empty($values)) {
                            return $query;
                        }

                        return $query->whereIn('documents.batch_id', $values);
                    }),

                Tables\Filters\SelectFilter::make('authorities')
                    ->label('Creators')
                    ->relationship('authorities', 'surname')
                    ->multiple()
                    ->preload()
                    ->searchable(),

                Tables\Filters\SelectFilter::make('document_type')
                    ->label('Document type')
                    ->options(fn (): array => Document::query()
                        ->whereNotNull('document_type')
                        ->distinct()
                        ->orderBy('document_type')
                        ->pluck('document_type', 'document_type')
                        ->all())
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

        return ReportRenderer::streamCsv(
            title: 'Documents pending disinfestation',
            slug: 'pending-disinfestation',
            columns: [
                'Identifier' => 'identifier',
                'Type' => 'document_type',
                'Series' => 'series',
                'Batch' => 'batch',
                'Current box' => 'current_box',
                'Box status' => 'box_status',
                'Created at' => 'created_at',
                'Days waiting' => 'days_waiting',
            ],
            query: $this->reportQuery()->orderBy('documents.id'),
            rowMapper: fn (Document $r): array => self::pendingRow($r),
        );
    }

    public function exportPdf(): Response
    {
        abort_unless(static::canAccess(), 403);

        $rows = [];
        /** @var Document $r */
        foreach ($this->reportQuery()->oldest('documents.created_at')->limit(5000)->get() as $r) {
            $rows[] = self::pendingRow($r);
        }

        return ReportRenderer::renderPdf(
            title: 'Documents pending disinfestation',
            slug: 'pending-disinfestation',
            headers: ['Identifier', 'Type', 'Series', 'Batch', 'Current box', 'Box status', 'Created at', 'Days waiting'],
            rows: $rows,
        );
    }

    public function exportXlsx(): BinaryFileResponse|StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        $query = $this->getFilteredTableQuery() ?? $this->reportQuery();
        $rows = $this->fetchExportRowsWithCap(
            $query
                ->with(['currentBox:id,box_number,barcode_status', 'batch:id,batch_number', 'series:id,code'])
                ->oldest('documents.created_at'),
        );

        return ReportRenderer::streamXlsx(
            rows: $rows,
            columns: $this->getXlsxColumns(),
            filename: ReportRenderer::filename($this->getReportSlug(), 'xlsx'),
            title: $this->getReportTitle(),
        );
    }

    /**
     * @return array<string, callable(Document): mixed>
     */
    public function getXlsxColumns(): array
    {
        return [
            'Identifier' => fn (Document $r) => $r->identifier,
            'Type' => fn (Document $r) => $r->document_type,
            'Series' => fn (Document $r) => $r->series?->getAttribute('code'),
            'Batch' => fn (Document $r) => $r->batch?->getAttribute('batch_number'),
            'Current box' => fn (Document $r) => $r->currentBox?->getAttribute('box_number'),
            'Box status' => fn (Document $r) => $r->currentBox?->getAttribute('barcode_status'),
            'Created at' => fn (Document $r) => $r->created_at instanceof \DateTimeInterface ? $r->created_at->format('Y-m-d') : null,
            'Days waiting' => function (Document $r): int {
                $created = $r->created_at;

                return $created instanceof \DateTimeInterface
                    ? (int) round(now()->diffInDays($created, true))
                    : 0;
            },
        ];
    }

    public function getReportTitle(): string
    {
        return 'Documents pending disinfestation';
    }

    public function getReportSlug(): string
    {
        return 'pending-disinfestation';
    }

    public function mount(): void
    {
        $this->applyTemplateFromQuery();
    }

    /**
     * `disinfestation_date IS NULL` AND
     * (current_box_id IS NULL OR currentBox.barcode_status != 'PERM_OUT')
     */
    protected function reportQuery(): Builder
    {
        return Document::query()
            ->with(['currentBox:id,box_number,barcode_status', 'batch:id,batch_number', 'series:id,code'])
            ->whereNull('disinfestation_date')
            ->where(function (Builder $q): void {
                $q->whereNull('current_box_id')
                    ->orWhereHas('currentBox', function (Builder $q): void {
                        $q->where('barcode_status', '!=', 'PERM_OUT');
                    });
            });
    }

    /**
     * Flatten one pending Document into the column order this report uses.
     *
     * @return array<int, scalar|null>
     */
    protected static function pendingRow(Document $r): array
    {
        $created = $r->created_at;
        $days = $created instanceof \DateTimeInterface
            ? (int) round(now()->diffInDays($created, true))
            : 0;

        return [
            $r->identifier,
            $r->document_type,
            $r->series?->getAttribute('code'),
            $r->batch?->getAttribute('batch_number'),
            $r->currentBox?->getAttribute('box_number'),
            $r->currentBox?->getAttribute('barcode_status'),
            $created instanceof \DateTimeInterface ? $created->format('Y-m-d') : null,
            $days,
        ];
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
