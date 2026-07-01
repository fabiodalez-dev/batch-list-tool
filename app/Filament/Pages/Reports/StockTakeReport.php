<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Pages\Reports\Concerns\CapsExportRows;
use App\Filament\Pages\Reports\Concerns\HasReportTemplates;
use App\Models\Box;
use App\Models\Document;
use App\Models\Location;
use App\Models\ReportTemplate;
use App\Models\Repository;
use App\Models\StockTakeEntry;
use App\Support\Reports\ReportRenderer;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * NAF Queries Q4 — Stock take by box and by item, using NRA location.
 *
 * The client answer requires two operational stock-take views, not just counts:
 * document rows with Catalogue / Temporary / Conservation identifiers and box
 * rows with latest Batch / Box / In-Situ box values. The table is backed by a
 * UNION subquery exposed through {@see StockTakeEntry}; `summaryQuery()` keeps
 * the previous per-location counts available for landing-card/tests.
 */
class StockTakeReport extends Page implements HasTable
{
    use CapsExportRows;
    use ExplainsPage;
    use HasReportTemplates;
    use InteractsWithTable;

    /** @see ReportTemplate::SOURCES */
    public const REPORT_SOURCE = ReportTemplate::SOURCE_STOCK_TAKE;

    protected string $view = 'filament.pages.reports.table';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'Stock take (box + item)';

    protected static ?string $slug = 'reports/stock-take';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('view_any_report');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(): void
    {
        $this->applyTemplateFromQuery();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->reportQuery())
            ->defaultSort('nra_location')
            ->columns([
                Tables\Columns\TextColumn::make('stock_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === 'box' ? 'Box' : 'Document')
                    ->color(fn (?string $state): string => $state === 'box' ? 'gray' : 'primary')
                    ->sortable(),

                Tables\Columns\TextColumn::make('catalogue_identifier')
                    ->label('Catalogue Identifier')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('temporary_identifier')
                    ->label('Temporary Identifier')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('conservation_object_reference_number')
                    ->label('Conservation Object Ref.')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('latest_batch_no')
                    ->label('Latest Batch No')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('box_no')
                    ->label('Box No')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('in_situ_box_no')
                    ->label('Latest In-Situ Box No')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('nra_location')
                    ->label('NRA Location')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('repository_code')
                    ->label('Repository')
                    ->badge()
                    ->color('gray')
                    ->placeholder('GLOBAL'),
            ])
            ->filtersFormColumns(3)
            ->filters([
                Tables\Filters\SelectFilter::make('stock_type')
                    ->label('Stock row type')
                    ->options([
                        'box' => 'Boxes',
                        'document' => 'Documents',
                    ]),

                Tables\Filters\SelectFilter::make('location_id')
                    ->label('NRA location')
                    ->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->multiple(),

                Tables\Filters\SelectFilter::make('repository_id')
                    ->label('Repository')
                    ->options(fn (): array => Repository::query()->orderBy('code')->pluck('code', 'id')->all())
                    ->searchable()
                    ->multiple(),
            ])
            ->paginated([25, 50, 100, 'all']);
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::streamCsvFromRows(
            slug: 'stock-take',
            columns: $this->csvColumns(),
            rows: $this->flatExportRows(),
        );
    }

    public function exportPdf(): Response
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::renderPdf(
            title: $this->getReportTitle(),
            slug: 'stock-take',
            headers: array_keys($this->csvColumns()),
            rows: $this->flatExportRows(limit: 5000),
        );
    }

    public function exportXlsx(): BinaryFileResponse|StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::streamXlsx(
            rows: $this->exportRows(),
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
            'Type' => fn (array $r) => $r['type'],
            'Catalogue Identifier' => fn (array $r) => $r['catalogue_identifier'],
            'Temporary Identifier' => fn (array $r) => $r['temporary_identifier'],
            'Conservation Object Ref.' => fn (array $r) => $r['conservation_object_reference_number'],
            'Latest Batch No' => fn (array $r) => $r['latest_batch_no'],
            'Box No' => fn (array $r) => $r['box_no'],
            'Latest In-Situ Box No' => fn (array $r) => $r['in_situ_box_no'],
            'NRA Location' => fn (array $r) => $r['nra_location'],
            'Repository' => fn (array $r) => $r['repository'],
        ];
    }

    public function getReportTitle(): string
    {
        return 'Stock take (box + item)';
    }

    public function getReportSlug(): string
    {
        return 'stock-take';
    }

    /**
     * Detailed stock-take rows consumed by the table and exports.
     *
     * @return Builder<StockTakeEntry>
     */
    protected function reportQuery(): Builder
    {
        /** @var Builder<StockTakeEntry> $query */
        $query = StockTakeEntry::query()
            ->fromSub($this->stockTakeUnionQuery(), 'stock_take_entries')
            ->select('stock_take_entries.*');

        return $query;
    }

    /**
     * Previous per-location count view, kept for summary consumers.
     *
     * @return Builder<Location>
     */
    protected function summaryQuery(): Builder
    {
        return Location::query()
            ->with('repository:id,code')
            ->withCount([
                'boxes as box_count' => fn (Builder $q) => $q->whereNull('destroyed_at'),
                'documents as item_count',
            ]);
    }

    protected function stockTakeUnionQuery(): QueryBuilder
    {
        $boxRows = Box::query()
            ->leftJoin('batches', 'batches.id', '=', 'boxes.batch_id')
            ->leftJoin('repositories', 'repositories.id', '=', 'batches.repository_id')
            ->leftJoin('locations', 'locations.id', '=', 'boxes.location_id')
            ->whereNull('boxes.destroyed_at')
            ->selectRaw('(boxes.id * 2) as row_key')
            ->selectRaw('boxes.id as source_id')
            ->selectRaw('? as stock_type', ['box'])
            ->selectRaw('batches.repository_id as repository_id')
            ->selectRaw('repositories.code as repository_code')
            ->selectRaw('boxes.location_id as location_id')
            ->selectRaw('locations.name as nra_location')
            ->selectRaw('NULL as catalogue_identifier')
            ->selectRaw('NULL as temporary_identifier')
            ->selectRaw('NULL as conservation_object_reference_number')
            ->selectRaw('batches.batch_number as latest_batch_no')
            ->selectRaw('boxes.box_number as box_no')
            ->selectRaw("CASE WHEN boxes.box_type = 'IN_SITU' THEN boxes.box_number ELSE NULL END as in_situ_box_no")
            ->toBase();

        $documentRows = Document::query()
            ->leftJoin('boxes as current_boxes', 'current_boxes.id', '=', 'documents.current_box_id')
            ->leftJoin('batches as document_batches', 'document_batches.id', '=', 'documents.batch_id')
            ->leftJoin('batches as box_batches', 'box_batches.id', '=', 'current_boxes.batch_id')
            ->leftJoin('repositories', 'repositories.id', '=', 'documents.repository_id')
            ->leftJoin('locations as doc_locations', 'doc_locations.id', '=', 'documents.location_id')
            ->leftJoin('locations as box_locations', 'box_locations.id', '=', 'current_boxes.location_id')
            ->selectRaw('(documents.id * 2 + 1) as row_key')
            ->selectRaw('documents.id as source_id')
            ->selectRaw('? as stock_type', ['document'])
            ->selectRaw('documents.repository_id as repository_id')
            ->selectRaw('repositories.code as repository_code')
            ->selectRaw('COALESCE(documents.location_id, current_boxes.location_id) as location_id')
            ->selectRaw('COALESCE(doc_locations.name, box_locations.name, documents.nra_location) as nra_location')
            ->selectRaw('documents.catalogue_identifier as catalogue_identifier')
            ->selectRaw('documents.identifier as temporary_identifier')
            ->selectRaw('documents.object_reference_number as conservation_object_reference_number')
            ->selectRaw('COALESCE(document_batches.batch_number, box_batches.batch_number) as latest_batch_no')
            ->selectRaw('current_boxes.box_number as box_no')
            ->selectRaw("COALESCE(documents.in_situ_box_3, documents.in_situ_box_2, documents.in_situ_box_1, CASE WHEN current_boxes.box_type = 'IN_SITU' THEN current_boxes.box_number ELSE NULL END) as in_situ_box_no")
            ->toBase();

        return $boxRows->unionAll($documentRows);
    }

    /**
     * @return array<string, string>
     */
    protected function csvColumns(): array
    {
        return [
            'Type' => 'type',
            'Catalogue Identifier' => 'catalogue_identifier',
            'Temporary Identifier' => 'temporary_identifier',
            'Conservation Object Ref.' => 'conservation_object_reference_number',
            'Latest Batch No' => 'latest_batch_no',
            'Box No' => 'box_no',
            'Latest In-Situ Box No' => 'in_situ_box_no',
            'NRA Location' => 'nra_location',
            'Repository' => 'repository',
        ];
    }

    /**
     * @return list<array<string, scalar|null>>
     */
    protected function exportRows(?int $limit = null): array
    {
        $query = $this->getFilteredTableQuery() ?? $this->reportQuery();
        $query
            ->orderBy('nra_location')
            ->orderBy('stock_type')
            ->orderBy('row_key');

        if ($limit !== null) {
            $query->limit($limit);
            $records = $query->get();
        } else {
            /** @var EloquentCollection<int, StockTakeEntry> $records */
            $records = $this->fetchExportRowsWithCap($query);
        }

        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'type' => $record->getAttribute('stock_type') === 'box' ? 'Box' : 'Document',
                'catalogue_identifier' => $record->getAttribute('catalogue_identifier'),
                'temporary_identifier' => $record->getAttribute('temporary_identifier'),
                'conservation_object_reference_number' => $record->getAttribute('conservation_object_reference_number'),
                'latest_batch_no' => $record->getAttribute('latest_batch_no'),
                'box_no' => $record->getAttribute('box_no'),
                'in_situ_box_no' => $record->getAttribute('in_situ_box_no'),
                'nra_location' => $record->getAttribute('nra_location'),
                'repository' => $record->getAttribute('repository_code') ?? 'GLOBAL',
            ];
        }

        return $rows;
    }

    /**
     * @return list<list<scalar|null>>
     */
    protected function flatExportRows(?int $limit = null): array
    {
        return array_map(
            fn (array $row): array => array_values($row),
            $this->exportRows($limit),
        );
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
