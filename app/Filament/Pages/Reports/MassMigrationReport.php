<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Pages\Reports\Concerns\CapsExportRows;
use App\Filament\Pages\Reports\Concerns\ExportsWhatIsOnScreen;
use App\Filament\Pages\Reports\Concerns\HasReportTemplates;
use App\Models\Box;
use App\Models\Document;
use App\Models\Location;
use App\Models\Lookup\BoxType;
use App\Models\ReportTemplate;
use App\Models\Series;
use App\Support\Reports\BoxReportCriteria;
use App\Support\Reports\ReportRenderer;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Client 2026-11-10 — Mass Migration: "By Actual Authority Reference
 * Number/Series/Document Type. I would need a list of boxes which contain for
 * example: Series: REG, Document Type: Register Volume, Range Actual Authority
 * Reference Number: From R150 – R165 (keep in mind that sometimes a document is
 * associated with multiple Actual Authority Reference Number). A box may have
 * one document within it that meets the criteria. In that case the whole box
 * should be listed even though it is mixed with other documents. Usually the
 * whole box is migrated. The criteria should all be optional."
 *
 * One row per box holding at least one live document that meets every
 * criterion given, with how many of its documents match out of how many it
 * holds. The criteria ({@see BoxReportCriteria::applyMigrationCriteria()}):
 *  - Series, any number, each with its subseries;
 *  - Document type, any number;
 *  - Actual Authority Reference Code from / to (the Citing Reference Code,
 *    authorities.alternative_identifier), compared by letters and NUMBER:
 *    R150–R165 takes R151 but not R1500; a document matches when any of its
 *    authorities is in range;
 *  - "Also documents with no code", for the series where the creator is
 *    unknown (Miscellanea), when a range is given.
 */
class MassMigrationReport extends Page implements HasTable
{
    use CapsExportRows;
    use ExplainsPage;
    use ExportsWhatIsOnScreen;
    use HasReportTemplates;
    use InteractsWithTable;

    public const REPORT_SOURCE = ReportTemplate::SOURCE_MASS_MIGRATION;

    private const array HEADERS = ['Batch / Box', 'Current Box Type', 'Barcode', 'Status', 'Location', 'Matching documents', 'Documents in box'];

    protected string $view = 'filament.pages.reports.table';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'Mass migration';

    protected static ?string $slug = 'reports/mass-migration';

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
            ->defaultSort(fn (Builder $query): Builder => BoxReportCriteria::orderBoxes($query, 'boxes.box_type', BoxReportCriteria::BATCH_NUMBER_SQL, 'boxes.box_number'))
            ->columns([
                Tables\Columns\TextColumn::make('box_label')
                    ->label('Batch / Box')
                    ->state(fn (Box $r): string => $r->shortLabel())
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $q): Builder => $q
                        ->where('boxes.box_number', 'like', "%{$search}%")
                        ->orWhereHas('batch', fn (Builder $b): Builder => $b->where('batch_number', $search))))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => BoxReportCriteria::orderBoxes($query, 'boxes.box_type', BoxReportCriteria::BATCH_NUMBER_SQL, 'boxes.box_number', $direction)),
                Tables\Columns\TextColumn::make('current_box_type')->label('Current Box Type')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('barcode')->label('Barcode')->placeholder('—')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('barcode_status')->label('Status')->badge()->placeholder('—'),
                Tables\Columns\TextColumn::make('location.name')->label('Location')->placeholder('—'),
                Tables\Columns\TextColumn::make('matching_documents_count')
                    ->label('Matching')
                    ->alignEnd()
                    ->sortable()
                    ->tooltip('Documents of the box that meet the criteria.'),
                Tables\Columns\TextColumn::make('documents_count')
                    ->label('In box')
                    ->alignEnd()
                    ->sortable()
                    ->tooltip('All the documents of the box: usually the whole box is migrated.'),
            ])
            ->filtersFormColumns(2)
            ->filters([
                Tables\Filters\SelectFilter::make('series')
                    ->label('Series (with its subseries)')
                    ->options(fn (): array => Series::query()->orderBy('code')->get(['id', 'code', 'title'])
                        ->mapWithKeys(fn (Series $s): array => [$s->getKey() => trim($s->code . ': ' . $s->title, ': ')])
                        ->all())
                    ->searchable()
                    ->multiple()
                    // Read by reportQuery(): it narrows the boxes AND the count.
                    ->query(fn (Builder $query): Builder => $query),
                Tables\Filters\SelectFilter::make('document_type')
                    ->label('Document type')
                    ->options(fn (): array => Document::query()
                        ->whereNotNull('document_type')
                        ->where('document_type', '!=', '')
                        ->distinct()
                        ->orderBy('document_type')
                        ->pluck('document_type', 'document_type')
                        ->all())
                    ->searchable()
                    ->multiple()
                    ->query(fn (Builder $query): Builder => $query),
                Tables\Filters\Filter::make('authority_range')
                    ->label('Actual Authority Reference Code')
                    ->schema([
                        Forms\Components\TextInput::make('from')
                            ->label('Actual Authority Reference Code from')
                            ->placeholder('R150'),
                        Forms\Components\TextInput::make('to')
                            ->label('to')
                            ->placeholder('R165')
                            ->helperText('Compared by number: R150 to R165 takes R151 and R163A, not R1500. A document matches if any of its codes is in range.'),
                        Forms\Components\Toggle::make('include_no_code')
                            ->label('Also documents with no or an Unknown code')
                            ->helperText('Applies with a range: the creator of many Miscellanea is unknown.'),
                    ])
                    ->columns(1)
                    ->query(fn (Builder $query): Builder => $query)
                    ->indicateUsing(function (array $data): array {
                        $from = trim((string) ($data['from'] ?? ''));
                        $to = trim((string) ($data['to'] ?? ''));
                        if ($from === '' && $to === '') {
                            return [];
                        }
                        $label = 'Code ' . ($from !== '' ? $from : '…') . ' – ' . ($to !== '' ? $to : '…');

                        return [Indicator::make($label . (($data['include_no_code'] ?? false) ? ' (+ no code)' : ''))];
                    }),
                Tables\Filters\SelectFilter::make('box_type')
                    ->label('Box type')
                    ->options(fn (): array => BoxType::filterOptions())
                    ->multiple()
                    ->query(fn (Builder $query, array $data): Builder => empty($data['values']) ? $query : $query->whereIn('boxes.box_type', $data['values'])),
                Tables\Filters\SelectFilter::make('location_id')
                    ->label('Location')
                    ->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->multiple()
                    ->query(fn (Builder $query, array $data): Builder => empty($data['values']) ? $query : $query->whereIn('boxes.location_id', $data['values'])),
                BoxesPendingDisinfestationReport::repositoryFilter(),
            ])
            ->paginated([25, 50, 100]);
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::streamCsvFromRows(
            slug: $this->getReportSlug(),
            columns: array_combine(self::HEADERS, self::HEADERS),
            rows: $this->rows(),
        );
    }

    public function exportPdf(): Response
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::renderPdf(
            title: $this->getReportTitle(),
            slug: $this->getReportSlug(),
            headers: self::HEADERS,
            rows: $this->rows(5000),
            subtitle: $this->criteriaSummary(),
        );
    }

    public function exportXlsx(): BinaryFileResponse|StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::streamXlsx(
            rows: array_map(fn (array $r): array => array_combine(self::HEADERS, $r), $this->rows()),
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
        foreach (self::HEADERS as $header) {
            $columns[$header] = fn (array $r): mixed => $r[$header];
        }

        return $columns;
    }

    public function getReportTitle(): string
    {
        return 'Mass migration';
    }

    public function getReportSlug(): string
    {
        return 'mass-migration';
    }

    /**
     * The criteria as the filters hold them.
     *
     * @return array{series: array<int|string>, document_types: array<string>, from: ?string, to: ?string, include_no_code: bool}
     */
    public function criteria(): array
    {
        $f = $this->tableFilters ?? [];

        return [
            'series' => (array) ($f['series']['values'] ?? []),
            'document_types' => (array) ($f['document_type']['values'] ?? []),
            'from' => isset($f['authority_range']['from']) ? (string) $f['authority_range']['from'] : null,
            'to' => isset($f['authority_range']['to']) ? (string) $f['authority_range']['to'] : null,
            'include_no_code' => (bool) ($f['authority_range']['include_no_code'] ?? false),
        ];
    }

    /**
     * Live boxes with at least one matching document; with no criterion at
     * all, every box that holds a document.
     *
     * @return Builder<Box>
     */
    protected function reportQuery(): Builder
    {
        $criteria = $this->criteria();
        $matching = fn (Builder $documents): Builder => BoxReportCriteria::applyMigrationCriteria($documents, $criteria);

        return Box::query()
            ->with(['batch:id,batch_number', 'location:id,name'])
            ->whereNull('boxes.destroyed_at')
            ->whereHas('documents', $matching)
            ->withCount([
                'documents as matching_documents_count' => $matching,
                'documents',
            ]);
    }

    protected function criteriaSummary(): ?string
    {
        $c = $this->criteria();
        $parts = [];
        if ($c['series'] !== []) {
            $parts[] = 'Series: ' . implode(', ', Series::query()->whereKey($c['series'])->orderBy('code')->pluck('code')->all()) . ' (with subseries)';
        }
        if ($c['document_types'] !== []) {
            $parts[] = 'Document type: ' . implode(', ', $c['document_types']);
        }
        if (filled($c['from']) || filled($c['to'])) {
            $parts[] = 'Actual Authority Reference Code: ' . ($c['from'] ?: '…') . ' – ' . ($c['to'] ?: '…') . ($c['include_no_code'] ? ', and documents with no code' : '');
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->saveAsTemplateAction(),
            Action::make('exportCsv')->label('Export CSV')->icon('heroicon-o-arrow-down-tray')->color('gray')->action(fn () => $this->exportCsv()),
            Action::make('exportXlsx')->label('Export Excel')->icon('heroicon-o-table-cells')->color('gray')->action(fn () => $this->exportXlsx()),
            Action::make('exportPdf')->label('Export PDF')->icon('heroicon-o-document-arrow-down')->color('gray')->action(fn () => $this->exportPdf()),
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

    /**
     * @return list<list<scalar|null>>
     */
    private function rows(?int $limit = null): array
    {
        $query = BoxReportCriteria::orderBoxes($this->exportQuery()->reorder(), 'boxes.box_type', BoxReportCriteria::BATCH_NUMBER_SQL, 'boxes.box_number');
        $records = $limit === null ? $this->fetchExportRowsWithCap($query) : $query->limit($limit)->get();

        $rows = [];
        foreach ($records as $box) {
            /** @var Box $box */
            $rows[] = [
                $box->shortLabel(),
                $box->current_box_type,
                $box->barcode,
                $box->barcode_status,
                $box->location?->getAttribute('name'),
                (int) $box->getAttribute('matching_documents_count'),
                (int) $box->getAttribute('documents_count'),
            ];
        }

        return $rows;
    }
}
