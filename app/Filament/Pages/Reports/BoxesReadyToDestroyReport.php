<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Pages\Reports\Concerns\CapsExportRows;
use App\Filament\Pages\Reports\Concerns\ExportsWhatIsOnScreen;
use App\Filament\Pages\Reports\Concerns\HasReportTemplates;
use App\Models\Box;
use App\Models\Document;
use App\Models\Lookup\BoxType;
use App\Models\ReportTemplate;
use App\Models\Repository;
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
 * RFQ Appendix 2 §vii — "Once a RAS Box or an In Situ Box has been all
 * catalogued, it is destroyed and thrown away. If all the documents of a box
 * have a Catalogue Identifier ... the box can be marked as destroyed."
 *
 * Box::canBeDestroyed() already enforces that rule on the box's own Destroy
 * button, but nothing listed the boxes that meet it: finding them meant opening
 * boxes one by one. This is that list — the same rule, as a worklist.
 *
 * By default only boxes that HAVE documents, all catalogued. An empty box also
 * passes the rule, but on 2026-10-01 every box in production is empty (the
 * documents are not imported yet), so all 7,159 would be listed and the boxes
 * that are genuinely finished would drown. "Include empty boxes" brings them in.
 */
class BoxesReadyToDestroyReport extends Page implements HasTable
{
    use CapsExportRows;
    use ExplainsPage;
    use ExportsWhatIsOnScreen;
    use HasReportTemplates;
    use InteractsWithTable;

    public const REPORT_SOURCE = ReportTemplate::SOURCE_BOXES_READY_TO_DESTROY;

    private const array HEADERS = ['Box type', 'Box', 'Batch', 'Barcode', 'Location', 'Documents', 'Last document update'];

    protected string $view = 'filament.pages.reports.table';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'Boxes ready to be destroyed';

    protected static ?string $slug = 'reports/boxes-ready-to-destroy';

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
            ->defaultSort('documents_count', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('box_type')->label('Box type')->badge()->sortable(),
                Tables\Columns\TextColumn::make('box_number')->label('Box')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('batch.batch_number')->label('Batch')->placeholder('—'),
                Tables\Columns\TextColumn::make('barcode')->label('Barcode')->placeholder('—')->searchable(),
                Tables\Columns\TextColumn::make('location.name')->label('Location')->placeholder('—'),
                Tables\Columns\TextColumn::make('documents_count')
                    ->label('Documents')
                    ->alignEnd()
                    ->sortable()
                    ->tooltip('Documents in the box — every one of them catalogued.'),
                Tables\Columns\TextColumn::make('last_catalogued_at')
                    ->label('Last document update')
                    ->date()
                    ->placeholder('—')
                    ->sortable()
                    ->tooltip('The most recent change to any document of this box — usually its cataloguing.'),
            ])
            ->filtersFormColumns(2)
            ->filters([
                Tables\Filters\SelectFilter::make('box_type')
                    ->label('Box type')
                    ->options(fn (): array => BoxType::filterOptions())
                    ->multiple()
                    ->query(fn (Builder $query, array $data): Builder => empty($data['values']) ? $query : $query->whereIn('boxes.box_type', $data['values'])),
                Tables\Filters\SelectFilter::make('repository_id')
                    ->label('Repository')
                    ->options(fn (): array => Repository::query()->orderBy('code')->pluck('code', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => empty($data['value'])
                        ? $query
                        : $query->where(fn (Builder $query) => $query
                            ->whereHas('batch', fn (Builder $query) => $query->where('repository_id', $data['value']))
                            ->orWhere(fn (Builder $query) => $query->whereNull('boxes.batch_id')->where('boxes.repository_id', $data['value'])))),
                Tables\Filters\Filter::make('include_empty')
                    ->label('Include empty boxes')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query),
            ]);
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::streamCsvFromRows(
            slug: $this->getReportSlug(),
            // Only the keys are written (as the header row); the rows are positional.
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
        return 'Boxes ready to be destroyed';
    }

    public function getReportSlug(): string
    {
        return 'boxes-ready-to-destroy';
    }

    /**
     * Not destroyed, and no document — soft-deleted ones included, exactly as
     * Box::canBeDestroyed() counts them — without a catalogue identifier.
     * With documents_count / last_catalogued_at for the columns.
     *
     * @return Builder<Box>
     */
    protected function reportQuery(): Builder
    {
        $uncatalogued = Document::withTrashed()
            ->withoutGlobalScopes()
            ->select('current_box_id')
            ->whereNotNull('current_box_id')
            ->whereNull('catalogue_identifier');

        $query = Box::query()
            ->with(['batch:id,batch_number', 'location:id,name'])
            ->whereNull('boxes.destroyed_at')
            ->whereNotIn('boxes.id', $uncatalogued)
            ->withCount(['documents' => fn (Builder $query) => $query->withoutGlobalScopes()])
            ->withMax(['documents as last_catalogued_at' => fn (Builder $query) => $query->withoutGlobalScopes()], 'updated_at');

        if (! ($this->tableFilters['include_empty']['isActive'] ?? false)) {
            $query->whereHas('documents', fn (Builder $query) => $query->withoutGlobalScopes());
        }

        return $query;
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
        $query = $this->exportQuery()->orderByDesc('documents_count');
        $records = $limit === null ? $this->fetchExportRowsWithCap($query) : $query->limit($limit)->get();

        $rows = [];
        foreach ($records as $box) {
            /** @var Box $box */
            $rows[] = [
                $box->box_type,
                $box->box_number,
                $box->batch?->getAttribute('batch_number'),
                $box->barcode,
                $box->location?->getAttribute('name'),
                (int) $box->getAttribute('documents_count'),
                $box->getAttribute('last_catalogued_at') ? substr((string) $box->getAttribute('last_catalogued_at'), 0, 10) : null,
            ];
        }

        return $rows;
    }
}
