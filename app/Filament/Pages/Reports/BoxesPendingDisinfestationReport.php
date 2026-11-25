<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Actions\Boxes\SendBoxesToDisinfestationAction;
use App\Filament\Concerns\ExplainsPage;
use App\Filament\Pages\Reports\Concerns\CapsExportRows;
use App\Filament\Pages\Reports\Concerns\ExportsWhatIsOnScreen;
use App\Filament\Pages\Reports\Concerns\HasReportTemplates;
use App\Models\Box;
use App\Models\Location;
use App\Models\Lookup\BoxType;
use App\Models\ReportTemplate;
use App\Models\Repository;
use App\Support\Reports\BoxReportCriteria;
use App\Support\Reports\DisinfestationCapacity;
use App\Support\Reports\ReportRenderer;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
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
 * Client 2026-11-10 — "Boxes which contain documents that have not been
 * disinfested yet. If a box has a single document that has not been
 * disinfested, the whole box is disinfested. We would need: Latest batch and
 * box number; Barcode; Status has to be IN as no document is allowed to be at
 * NRA without a disinfestation date."
 *
 * The box-level twin of {@see PendingDisinfestationReport} (which lists the
 * documents and stays as it is). A box is listed when:
 *  - its barcode status is IN, and it is not destroyed (nor archived);
 *  - at least one live document is physically in it (custody In Box — only
 *    those travel with the box, as {@see SendBoxesToDisinfestationAction}
 *    sends them) with no disinfestation date in any round and not out at
 *    disinfestation already ({@see BoxReportCriteria::notDisinfested()}).
 *
 * The selected boxes can be sent to disinfestation from here, with the same
 * bulk action as the Boxes list.
 */
class BoxesPendingDisinfestationReport extends Page implements HasTable
{
    use CapsExportRows;
    use ExplainsPage;
    use ExportsWhatIsOnScreen;
    use HasReportTemplates;
    use InteractsWithTable;

    public const REPORT_SOURCE = ReportTemplate::SOURCE_BOXES_PENDING_DISINFESTATION;

    private const array HEADERS = ['Batch', 'Box', 'Barcode', 'Status', 'Current Box Type', 'Box spaces', 'Documents pending', 'Location'];

    protected string $view = 'filament.pages.reports.table';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'Boxes pending disinfestation';

    protected static ?string $slug = 'reports/boxes-pending-disinfestation';

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
                // Client 2026-11-24: batch and box in separate columns, so the
                // list can be filtered by batch and the Excel needs no splitting.
                Tables\Columns\TextColumn::make('batch.batch_number')
                    ->label('Batch')
                    ->placeholder('—')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('batch', fn (Builder $b): Builder => $b->where('batch_number', $search)))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => BoxReportCriteria::orderBoxes($query, 'boxes.box_type', BoxReportCriteria::BATCH_NUMBER_SQL, 'boxes.box_number', $direction)),
                Tables\Columns\TextColumn::make('box_label')
                    ->label('Box')
                    ->state(fn (Box $r): string => self::boxLabel($r))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('boxes.box_number', 'like', "%{$search}%")),
                Tables\Columns\TextColumn::make('barcode')->label('Barcode')->placeholder('—')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('barcode_status')->label('Status')->badge()->color('success'),
                Tables\Columns\TextColumn::make('current_box_type')->label('Current Box Type')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('box_spaces')
                    ->label('Box spaces')
                    ->state(fn (Box $r): int => DisinfestationCapacity::weightFor($r->current_box_type))
                    ->alignEnd()
                    ->tooltip('The space the box takes at disinfestation, from "Counts as" on Current Box Types (RAS Box 1, Big Brown Box 2).'),
                Tables\Columns\TextColumn::make('pending_documents_count')
                    ->label('Documents pending')
                    ->alignEnd()
                    ->sortable()
                    ->tooltip('Documents in the box (custody In Box) with no disinfestation date.'),
                Tables\Columns\TextColumn::make('location.name')->label('Location')->placeholder('—'),
            ])
            ->filtersFormColumns(3)
            ->filters([
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
                Tables\Filters\SelectFilter::make('batch_id')
                    ->label('Batch')
                    ->relationship('batch', 'batch_number')
                    ->searchable()
                    ->multiple()
                    ->query(fn (Builder $query, array $data): Builder => empty($data['values']) ? $query : $query->whereIn('boxes.batch_id', $data['values'])),
                self::repositoryFilter(),
                // Client 2026-11-24: "generate the export as the next 250 box
                // spaces (as that is the capacity for Comtec)". Last, so it
                // fills from the boxes the other filters leave.
                Tables\Filters\Filter::make('box_spaces')
                    ->schema([
                        TextInput::make('spaces')
                            ->label('Fill up to (box spaces)')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->placeholder('e.g. 250')
                            ->helperText('Takes the boxes in list order (batch, then box) until this many spaces are filled. A Big Brown Box takes 2.'),
                    ])
                    // baseQuery, not query: Filament hands query() an empty
                    // nested query, so the fill would count every box, pending
                    // or not. The base query is the report's own.
                    ->baseQuery(fn (Builder $query, array $data, HasTable $livewire): Builder => self::fillBoxSpaces($query, (int) ($data['spaces'] ?? 0), $livewire))
                    ->indicateUsing(fn (array $data): ?string => (int) ($data['spaces'] ?? 0) > 0 ? 'Up to ' . (int) $data['spaces'] . ' box spaces' : null),
            ])
            ->bulkActions([
                SendBoxesToDisinfestationAction::bulk(),
            ])
            ->paginated([25, 50, 100]);
    }

    /**
     * The box without its batch: "8" for a RAS box (its batch has its own
     * column), "NRA 500" for the others.
     */
    public static function boxLabel(Box $box): string
    {
        return ($box->box_type ?? 'RAS') === 'RAS'
            ? (string) $box->box_number
            : trim($box->box_type . ' ' . $box->box_number);
    }

    /**
     * Keep the first boxes, in the report's order, whose spaces add up to no
     * more than $capacity. The fill stops at the first box that no longer fits,
     * so the boxes sent stay in sequence.
     *
     * @param Builder<Box> $query
     * @return Builder<Box>
     */
    public static function fillBoxSpaces(Builder $query, int $capacity, ?HasTable $livewire = null): Builder
    {
        if ($capacity <= 0) {
            return $query;
        }

        $candidates = (clone $query)->reorder()->setEagerLoads([])->select(['boxes.id', 'boxes.current_box_type']);

        // The other filters (batch, location, ...) apply after the base query,
        // so the fill applies them itself to count only the boxes they leave.
        if ($livewire !== null) {
            $candidates->where(function (Builder $candidates) use ($livewire): void {
                foreach ($livewire->getTable()->getFilters() as $filter) {
                    if ($filter->getName() !== 'box_spaces') {
                        $filter->apply($candidates, $livewire->getTableFilterState($filter->getName()) ?? []);
                    }
                }
            });
        }

        $candidates = BoxReportCriteria::orderBoxes($candidates, 'boxes.box_type', BoxReportCriteria::BATCH_NUMBER_SQL, 'boxes.box_number')
            ->toBase()
            ->get();

        $ids = [];
        $used = 0;
        foreach ($candidates as $box) {
            $spaces = DisinfestationCapacity::weightFor(is_string($box->current_box_type) ? $box->current_box_type : null);
            if ($used + $spaces > $capacity) {
                break;
            }
            $used += $spaces;
            $ids[] = (int) $box->id;
        }

        return $query->whereIn('boxes.id', $ids);
    }

    /** The repository filter the box reports share (a box's tenant is its batch's, else its own). */
    public static function repositoryFilter(): Tables\Filters\SelectFilter
    {
        return Tables\Filters\SelectFilter::make('repository_id')
            ->label('Repository')
            ->options(fn (): array => Repository::query()->orderBy('code')->pluck('code', 'id')->all())
            ->query(fn (Builder $query, array $data): Builder => empty($data['value'])
                ? $query
                : $query->where(fn (Builder $query) => $query
                    ->whereHas('batch', fn (Builder $query) => $query->where('repository_id', $data['value']))
                    ->orWhere(fn (Builder $query) => $query->whereNull('boxes.batch_id')->where('boxes.repository_id', $data['value']))));
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
            rows: $this->rows(ReportRenderer::PDF_FETCH_LIMIT),
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
        return 'Boxes pending disinfestation';
    }

    public function getReportSlug(): string
    {
        return 'boxes-pending-disinfestation';
    }

    /**
     * @return Builder<Box>
     */
    protected function reportQuery(): Builder
    {
        $pending = fn (Builder $documents): Builder => BoxReportCriteria::notDisinfested(
            $documents->where('documents.custody_status', 'in_box'),
        );

        return Box::query()
            ->with(['batch:id,batch_number', 'location:id,name'])
            ->whereNull('boxes.destroyed_at')
            ->where('boxes.barcode_status', 'IN')
            ->whereHas('documents', $pending)
            ->withCount(['documents as pending_documents_count' => $pending]);
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
                $box->batch_id !== null ? $box->getRelationValue('batch')?->getAttribute('batch_number') : null,
                self::boxLabel($box),
                $box->barcode,
                $box->barcode_status,
                $box->current_box_type,
                DisinfestationCapacity::weightFor($box->current_box_type),
                (int) $box->getAttribute('pending_documents_count'),
                $box->location?->getAttribute('name'),
            ];
        }

        return $rows;
    }
}
