<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Pages\Reports\Concerns\CapsExportRows;
use App\Filament\Pages\Reports\Concerns\ExportsWhatIsOnScreen;
use App\Filament\Pages\Reports\Concerns\HasReportTemplates;
use App\Filament\Resources\DocumentResource;
use App\Filament\Support\DocumentDates;
use App\Models\Authority;
use App\Models\Box;
use App\Models\Builders\DocumentBuilder;
use App\Models\Document;
use App\Models\Location;
use App\Models\Lookup\BoxType;
use App\Models\ReportTemplate;
use App\Models\Repository;
use App\Models\Series;
use App\Models\StockTakeEntry;
use App\Support\Reports\BoxReportCriteria;
use App\Support\Reports\ReportRenderer;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Stock take, the two ways the client asked for on 2026-11-10.
 *
 * Per box: "Latest batch and box number if RAS or NRA/MUS/MAV/STVC box
 * number; Current Box Type; Location. Preferably Boxes are grouped by
 * Location and are sorted by Batch/Box and NRA/MUS/MAV/STVC number. An option
 * to include documents with Custody Status Not in Box/Mounted/Conservation Box
 * should also be included here." The rows come from a UNION of boxes and,
 * on request, those documents (at their own location), exposed through
 * {@see StockTakeEntry}.
 *
 * Per document ("this is documents by location currently"): Catalogue
 * Identifier, Actual Authority Reference Code, Volume, Creator, Dates,
 * Location — grouped by location and sorted by catalogue identifier.
 *
 * Both views group the rows under a heading per location with its count, on
 * screen and in the PDF. Everything is ordered and counted in SQL.
 */
class StockTakeReport extends Page implements HasTable
{
    use CapsExportRows;
    use ExplainsPage;
    use ExportsWhatIsOnScreen;
    use HasReportTemplates;
    use InteractsWithTable;

    /** @see ReportTemplate::SOURCES */
    public const REPORT_SOURCE = ReportTemplate::SOURCE_STOCK_TAKE;

    public const string MODE_BOX = 'box';

    public const string MODE_DOCUMENT = 'document';

    /** Per box or per document; in the URL so each view can be bookmarked. */
    #[Url]
    public string $mode = self::MODE_BOX;

    protected string $view = 'filament.pages.reports.table';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'Stock take';

    protected static ?string $slug = 'reports/stock-take';

    /** @var array<string, array<string, int>>|null memo of the group heading counts */
    private ?array $groupCounts = null;

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
        $this->mode = $this->isDocumentMode() ? self::MODE_DOCUMENT : self::MODE_BOX;
    }

    public function isDocumentMode(): bool
    {
        return $this->mode === self::MODE_DOCUMENT;
    }

    public function table(Table $table): Table
    {
        $table = $table
            ->query(fn (): Builder => $this->reportQuery())
            ->groups([
                Group::make('location_name')
                    ->label('Location')
                    ->collapsible()
                    ->getTitleFromRecordUsing(fn (Model $record): string => $this->groupTitle($record->getAttribute('location_name'))),
            ])
            ->defaultGroup('location_name')
            ->filtersFormColumns(3)
            ->paginated([25, 50, 100]);

        return $this->isDocumentMode() ? $this->documentTable($table) : $this->boxTable($table);
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        $headers = $this->exportHeaders();

        return ReportRenderer::streamCsvFromRows(
            slug: $this->getReportSlug(),
            columns: array_combine($headers, $headers),
            rows: $this->exportRows(),
        );
    }

    public function exportPdf(): Response
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::renderPdf(
            title: $this->getReportTitle(),
            slug: $this->getReportSlug(),
            headers: $this->exportHeaders(),
            rows: $this->exportRows(limit: ReportRenderer::PDF_FETCH_LIMIT),
            groupBy: 0,
            groupNoun: $this->isDocumentMode() ? 'documents' : 'rows',
        );
    }

    public function exportXlsx(): BinaryFileResponse|StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        $headers = $this->exportHeaders();

        return ReportRenderer::streamXlsx(
            rows: array_map(fn (array $r): array => array_combine($headers, $r), $this->exportRows()),
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
        $columns = [];
        foreach ($this->exportHeaders() as $header) {
            $columns[$header] = fn (array $r): mixed => $r[$header];
        }

        return $columns;
    }

    public function getReportTitle(): string
    {
        return $this->isDocumentMode() ? 'Stock take per document' : 'Stock take per box';
    }

    public function getReportSlug(): string
    {
        return $this->isDocumentMode() ? 'stock-take-documents' : 'stock-take-boxes';
    }

    public function getTitle(): string
    {
        return $this->getReportTitle();
    }

    /**
     * The rows of the active view.
     *
     * @return Builder<StockTakeEntry>|DocumentBuilder
     */
    protected function reportQuery(): Builder
    {
        return $this->isDocumentMode() ? $this->documentQuery() : $this->boxQuery();
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

    /**
     * Per box: the live boxes, and with "Include documents kept outside their
     * box" also the documents whose custody is Not in Box / Mounted /
     * Conservation Box, at their effective location (their own, else their
     * box's — as {@see Document::effectiveLocation()}).
     *
     * @return Builder<StockTakeEntry>
     */
    protected function boxQuery(): Builder
    {
        $boxRows = Box::query()
            ->leftJoin('batches as st_batch', 'st_batch.id', '=', 'boxes.batch_id')
            ->leftJoin('locations as st_loc', fn (JoinClause $j): JoinClause => $j->on('st_loc.id', '=', 'boxes.location_id')->whereNull('st_loc.deleted_at'))
            ->whereNull('boxes.destroyed_at')
            ->selectRaw('(boxes.id * 2) as row_key')
            ->selectRaw('boxes.id as source_id')
            ->selectRaw("'box' as stock_type")
            ->selectRaw('COALESCE(st_batch.repository_id, boxes.repository_id) as repository_id')
            ->selectRaw('st_loc.id as location_id')
            ->selectRaw('st_loc.name as location_name')
            ->selectRaw('boxes.box_type as box_type')
            ->selectRaw('boxes.current_box_type as current_box_type')
            ->selectRaw('st_batch.batch_number as batch_number')
            ->selectRaw('boxes.box_number as box_number')
            ->selectRaw('boxes.barcode as barcode')
            ->selectRaw('boxes.barcode_status as barcode_status')
            ->selectRaw('NULL as custody_status')
            ->selectRaw('NULL as document_ref')
            ->selectRaw('0 as sort_rank')
            ->toBase();

        $union = $boxRows;
        if ($this->includesOutOfBoxDocuments()) {
            $union = $boxRows->unionAll($this->outOfBoxDocumentRows());
        }

        /** @var Builder<StockTakeEntry> $query */
        $query = StockTakeEntry::query()
            ->fromSub($union, 'stock_take_entries')
            ->select('stock_take_entries.*');

        return $query;
    }

    protected function outOfBoxDocumentRows(): QueryBuilder
    {
        return Document::query()
            ->leftJoin('boxes as st_box', fn (JoinClause $j): JoinClause => $j->on('st_box.id', '=', 'documents.current_box_id')->whereNull('st_box.deleted_at'))
            ->leftJoin('batches as st_batch', 'st_batch.id', '=', 'st_box.batch_id')
            ->leftJoin('locations as st_own', fn (JoinClause $j): JoinClause => $j->on('st_own.id', '=', 'documents.location_id')->whereNull('st_own.deleted_at'))
            ->leftJoin('locations as st_box_loc', fn (JoinClause $j): JoinClause => $j->on('st_box_loc.id', '=', 'st_box.location_id')->whereNull('st_box_loc.deleted_at'))
            ->whereIn('documents.custody_status', BoxReportCriteria::OUT_OF_BOX_CUSTODY)
            ->selectRaw('(documents.id * 2 + 1) as row_key')
            ->selectRaw('documents.id as source_id')
            ->selectRaw("'document' as stock_type")
            ->selectRaw('documents.repository_id as repository_id')
            ->selectRaw('COALESCE(st_own.id, st_box_loc.id) as location_id')
            ->selectRaw('COALESCE(st_own.name, st_box_loc.name) as location_name')
            ->selectRaw('st_box.box_type as box_type')
            ->selectRaw('st_box.current_box_type as current_box_type')
            ->selectRaw('st_batch.batch_number as batch_number')
            ->selectRaw('st_box.box_number as box_number')
            ->selectRaw('st_box.barcode as barcode')
            ->selectRaw('st_box.barcode_status as barcode_status')
            ->selectRaw('documents.custody_status as custody_status')
            ->selectRaw("COALESCE(NULLIF(documents.catalogue_identifier, ''), documents.identifier) as document_ref")
            ->selectRaw('1 as sort_rank')
            ->toBase();
    }

    /**
     * Per document: every live document with its effective location resolved
     * in SQL (own location, else its box's), so it can be grouped and counted.
     *
     * @return DocumentBuilder
     */
    protected function documentQuery(): Builder
    {
        return Document::query()
            ->leftJoin('locations as st_own', fn (JoinClause $j): JoinClause => $j->on('st_own.id', '=', 'documents.location_id')->whereNull('st_own.deleted_at'))
            ->leftJoin('boxes as st_box', fn (JoinClause $j): JoinClause => $j->on('st_box.id', '=', 'documents.current_box_id')->whereNull('st_box.deleted_at'))
            ->leftJoin('locations as st_box_loc', fn (JoinClause $j): JoinClause => $j->on('st_box_loc.id', '=', 'st_box.location_id')->whereNull('st_box_loc.deleted_at'))
            ->select('documents.*')
            ->selectRaw('COALESCE(st_own.id, st_box_loc.id) as effective_location_id')
            ->selectRaw('COALESCE(st_own.name, st_box_loc.name) as location_name')
            ->with(['authorities:id,alternative_identifier,surname,given_names,name_suffix,authorised_form_of_name']);
    }

    protected function boxTable(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query): Builder => $this->orderBoxRows($query))
            ->columns([
                Tables\Columns\TextColumn::make('stock_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === 'document' ? 'Document' : 'Box')
                    ->color(fn (?string $state): string => $state === 'document' ? 'primary' : 'gray')
                    ->visible(fn (): bool => $this->includesOutOfBoxDocuments()),

                Tables\Columns\TextColumn::make('box_label')
                    ->label('Batch / Box')
                    ->state(fn (StockTakeEntry $r): string => $this->boxRowLabel($r))
                    ->description(fn (StockTakeEntry $r): ?string => $this->boxRowNote($r))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q): Builder => $q
                        ->where('box_number', 'like', "%{$search}%")
                        ->orWhere('batch_number', $search)
                        ->orWhere('barcode', 'like', "%{$search}%")
                        ->orWhere('document_ref', 'like', "%{$search}%")))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $this->orderBoxRows($query, $direction)),

                Tables\Columns\TextColumn::make('current_box_type')
                    ->label('Current Box Type')
                    ->state(fn (StockTakeEntry $r): ?string => $r->getAttribute('stock_type') === 'document'
                        ? $this->custodyLabel($r->getAttribute('custody_status'))
                        : $r->getAttribute('current_box_type'))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('barcode')
                    ->label('Barcode')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('location_name')
                    ->label('Location')
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\Filter::make('include_out_of_box')
                    ->label('Include documents kept outside their box (Not in Box / Mounted / Conservation Box)')
                    ->toggle()
                    // Read by boxQuery(): it adds the rows rather than narrowing.
                    ->query(fn (Builder $query): Builder => $query),

                Tables\Filters\SelectFilter::make('location_id')
                    ->label('Location')
                    ->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->multiple(),

                Tables\Filters\SelectFilter::make('repository_id')
                    ->label('Repository')
                    ->options(fn (): array => Repository::query()->orderBy('code')->pluck('code', 'id')->all())
                    ->searchable()
                    ->multiple(),

                Tables\Filters\SelectFilter::make('box_type')
                    ->label('Box type')
                    ->options(fn (): array => BoxType::filterOptions())
                    ->multiple()
                    ->query(fn (Builder $query, array $data): Builder => empty($data['values'])
                        ? $query
                        : $query->where(fn (Builder $q): Builder => $q->where('stock_type', 'document')->orWhereIn('box_type', $data['values']))),

                Tables\Filters\SelectFilter::make('barcode_status')
                    ->label('Barcode status')
                    ->options(array_combine(Box::BARCODE_STATUSES, Box::BARCODE_STATUSES))
                    ->multiple()
                    ->query(fn (Builder $query, array $data): Builder => empty($data['values'])
                        ? $query
                        : $query->where(fn (Builder $q): Builder => $q->where('stock_type', 'document')->orWhereIn('barcode_status', $data['values']))),
            ]);
    }

    protected function documentTable(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query): Builder => $this->orderDocumentRows($query))
            ->columns([
                Tables\Columns\TextColumn::make('catalogue_identifier')
                    ->label('Catalogue Identifier')
                    ->searchable()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $this->orderDocumentRows($query, $direction))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('authority_codes')
                    ->label('Actual Authority Reference Code')
                    ->state(fn (Document $r): array => $this->authorityCodes($r))
                    ->listWithLineBreaks()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('volume_number')
                    ->label('Volume')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('creators')
                    ->label('Creator')
                    ->state(fn (Document $r): array => $this->creators($r))
                    ->listWithLineBreaks()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('dates')
                    ->label('Dates')
                    ->state(fn (Document $r): ?string => $this->datesText($r))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('location_name')
                    ->label('Location')
                    ->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('location_id')
                    ->label('Location (effective)')
                    ->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->multiple()
                    ->query(fn (Builder $query, array $data): Builder => empty($data['values'])
                        ? $query
                        : $query->whereIn(DB::raw('COALESCE(st_own.id, st_box_loc.id)'), $data['values'])),

                Tables\Filters\SelectFilter::make('repository_id')
                    ->label('Repository')
                    ->options(fn (): array => Repository::query()->orderBy('code')->pluck('code', 'id')->all())
                    ->searchable()
                    ->multiple()
                    ->query(fn (Builder $query, array $data): Builder => empty($data['values'])
                        ? $query
                        : $query->whereIn('documents.repository_id', $data['values'])),

                Tables\Filters\SelectFilter::make('series_id')
                    ->label('Series (with its subseries)')
                    ->options(fn (): array => Series::query()->orderBy('code')->pluck('code', 'id')->all())
                    ->searchable()
                    ->multiple()
                    ->query(fn (Builder $query, array $data): Builder => empty($data['values'])
                        ? $query
                        : $query->whereIn('documents.series_id', BoxReportCriteria::seriesWithSubseries($data['values']))),
            ]);
    }

    /**
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    protected function orderBoxRows(Builder $query, string $direction = 'asc'): Builder
    {
        $query->orderBy('sort_rank');
        BoxReportCriteria::orderBoxes($query, 'box_type', 'batch_number', 'box_number', $direction);

        return $query->orderBy('document_ref', $direction)->orderBy('row_key');
    }

    /**
     * @param Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    protected function orderDocumentRows(Builder $query, string $direction = 'asc'): Builder
    {
        return BoxReportCriteria::orderCatalogueIdentifier($query, 'documents.catalogue_identifier', $direction)
            ->orderBy('documents.identifier', $direction)
            ->orderBy('documents.id');
    }

    protected function includesOutOfBoxDocuments(): bool
    {
        return ! $this->isDocumentMode()
            && (bool) ($this->tableFilters['include_out_of_box']['isActive'] ?? false);
    }

    protected function boxRowLabel(StockTakeEntry $r): string
    {
        if ($r->getAttribute('stock_type') === 'document') {
            return (string) $r->getAttribute('document_ref');
        }

        return Box::shortLabelFrom($r->getAttribute('box_type'), $r->getAttribute('box_number'), $r->getAttribute('batch_number'));
    }

    /** A document row names the box it belongs to. */
    protected function boxRowNote(StockTakeEntry $r): ?string
    {
        if ($r->getAttribute('stock_type') !== 'document' || $r->getAttribute('box_number') === null) {
            return null;
        }

        return 'Of ' . Box::shortLabelFrom($r->getAttribute('box_type'), $r->getAttribute('box_number'), $r->getAttribute('batch_number'));
    }

    protected function custodyLabel(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        return once(fn (): array => Document::custodyLabels())[$status] ?? $status;
    }

    /**
     * @return list<Authority>
     */
    protected function authoritiesOf(Document $r): array
    {
        $out = [];
        foreach ($r->authorities as $authority) {
            if ($authority instanceof Authority) {
                $out[] = $authority;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    protected function authorityCodes(Document $r): array
    {
        return collect($this->authoritiesOf($r))
            ->map(fn (Authority $a): string => trim((string) $a->alternative_identifier))
            ->filter(fn (string $code): bool => $code !== '')
            ->values()
            ->all();
    }

    /**
     * The creator as the archive names it: the authorised form of name, else
     * "Surname, Given names" as the Documents list shows it.
     *
     * @return list<string>
     */
    protected function creators(Document $r): array
    {
        return collect($this->authoritiesOf($r))
            ->map(function (Authority $a): string {
                $form = trim((string) $a->authorised_form_of_name);

                return $form !== '' ? $form : DocumentResource::creatorName($a);
            })
            ->filter(fn (string $name): bool => $name !== '')
            ->values()
            ->all();
    }

    protected function datesText(Document $r): ?string
    {
        if (filled($r->dates)) {
            return (string) $r->dates;
        }

        return DocumentDates::dateText($r->dates_start, $r->dates_end)
            ?? DocumentDates::yearText($r->dates_year_start, $r->dates_year_end);
    }

    /**
     * "Room 1 · 12 boxes, 3 documents" — the counts of what the table shows
     * under that location, read once per request in one GROUP BY.
     */
    protected function groupTitle(mixed $location): string
    {
        $name = is_string($location) && $location !== '' ? $location : 'No location';

        $this->groupCounts ??= BoxReportCriteria::countsPerLocation(
            $this->getFilteredTableQuery(),
            'location_name',
            $this->isDocumentMode() ? null : 'stock_type',
        );
        $counts = $this->groupCounts[is_string($location) ? $location : ''] ?? [];

        $parts = [];
        foreach (['box' => 'box', 'document' => 'document', 'row' => 'document'] as $kind => $noun) {
            $n = $counts[$kind] ?? 0;
            if ($n > 0) {
                $parts[] = $n . ' ' . Str::plural($noun, $n);
            }
        }

        return $name . ($parts !== [] ? ' · ' . implode(', ', $parts) : '');
    }

    /**
     * @return list<string>
     */
    protected function exportHeaders(): array
    {
        if ($this->isDocumentMode()) {
            return ['Location', 'Catalogue Identifier', 'Actual Authority Reference Code', 'Volume', 'Creator', 'Dates'];
        }

        return ['Location', 'Type', 'Batch / Box', 'Current Box Type', 'Barcode'];
    }

    /**
     * The rows on screen, in screen order (location, then the view's own
     * order), the location first so the PDF can head each group with it.
     *
     * @return list<list<scalar|null>>
     */
    protected function exportRows(?int $limit = null): array
    {
        $query = $this->exportQuery()->reorder()->orderBy('location_name');
        $this->isDocumentMode() ? $this->orderDocumentRows($query) : $this->orderBoxRows($query);

        $records = $limit !== null ? $query->limit($limit)->get() : $this->fetchExportRowsWithCap($query);

        $rows = [];
        foreach ($records as $record) {
            if ($record instanceof Document) {
                $rows[] = [
                    $record->getAttribute('location_name'),
                    $record->catalogue_identifier,
                    implode('; ', $this->authorityCodes($record)),
                    $record->volume_number,
                    implode('; ', $this->creators($record)),
                    $this->datesText($record),
                ];

                continue;
            }

            /** @var StockTakeEntry $record */
            $isDocument = $record->getAttribute('stock_type') === 'document';
            $rows[] = [
                $record->getAttribute('location_name'),
                $isDocument ? 'Document' : 'Box',
                $this->boxRowLabel($record) . ($isDocument && ($note = $this->boxRowNote($record)) !== null ? ' (' . $note . ')' : ''),
                $isDocument ? $this->custodyLabel($record->getAttribute('custody_status')) : $record->getAttribute('current_box_type'),
                $record->getAttribute('barcode'),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    protected function templateExtraState(): array
    {
        return ['_mode' => $this->mode];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    protected function restoreTemplateExtraState(array $filters): array
    {
        if (isset($filters['_mode']) && is_string($filters['_mode'])) {
            $this->mode = $filters['_mode'];
        }
        unset($filters['_mode']);

        return $filters;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('perBox')
                ->label('Per box')
                ->icon('heroicon-o-archive-box')
                ->color(fn (): string => $this->isDocumentMode() ? 'gray' : 'primary')
                ->url(fn (): string => static::getUrl(['mode' => self::MODE_BOX])),

            Action::make('perDocument')
                ->label('Per document')
                ->icon('heroicon-o-document-text')
                ->color(fn (): string => $this->isDocumentMode() ? 'primary' : 'gray')
                ->url(fn (): string => static::getUrl(['mode' => self::MODE_DOCUMENT])),

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
