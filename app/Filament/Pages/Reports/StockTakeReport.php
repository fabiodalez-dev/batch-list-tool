<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Pages\Reports\Concerns\HasReportTemplates;
use App\Models\Location;
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
 * NAF Queries Q4 — Stock take (by box and by item, per location).
 *
 * Client answer: run by BOTH box and item; use the LOCATION (a box can carry
 * several barcodes, some PERM OUT, while still at RAS) rather than the PERM_OUT
 * status. MAV/STVC boxes are counted through their assigned NRA box numbers, so
 * they are ordinary boxes here — the legacy label is only history.
 *
 * Each row is a location (room / area) with its box count and its item
 * (document) count, so a single view answers both "how many boxes" and "how
 * many items" per room — different rooms filterable independently.
 */
class StockTakeReport extends Page implements HasTable
{
    use ExplainsPage;
    use HasReportTemplates;
    use InteractsWithTable;

    /** @see ReportTemplate::SOURCES */
    public const REPORT_SOURCE = ReportTemplate::SOURCE_STOCK_TAKE;

    protected string $view = 'filament.pages.reports.table';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'Stock take (by location)';

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
            ->defaultSort('box_count', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Location')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('repository.code')
                    ->label('Repository')
                    ->badge()
                    ->color('gray')
                    ->placeholder('GLOBAL'),

                Tables\Columns\TextColumn::make('box_count')
                    ->label('Boxes')
                    ->alignEnd()
                    ->numeric()
                    ->sortable(),

                Tables\Columns\TextColumn::make('item_count')
                    ->label('Items')
                    ->alignEnd()
                    ->numeric()
                    ->sortable(),
            ])
            ->filtersFormColumns(2)
            ->filters([
                Tables\Filters\SelectFilter::make('id')
                    ->label('Location / room')
                    ->options(fn (): array => Location::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->multiple()
                    ->query(function (Builder $query, array $data): Builder {
                        $values = $data['values'] ?? [];

                        return empty($values) ? $query : $query->whereIn('locations.id', $values);
                    }),

                Tables\Filters\SelectFilter::make('repository_id')
                    ->label('Repository')
                    ->relationship('repository', 'code')
                    ->searchable()
                    ->multiple()
                    ->preload()
                    ->query(function (Builder $query, array $data): Builder {
                        $values = $data['values'] ?? [];

                        return empty($values) ? $query : $query->whereIn('locations.repository_id', $values);
                    }),

                Tables\Filters\Filter::make('non_empty')
                    ->label('Only locations holding stock')
                    ->toggle()
                    ->query(fn (Builder $query, array $data): Builder => ($data['isActive'] ?? false)
                        ? $query->where(fn (Builder $q): Builder => $q->has('boxes')->orHas('documents'))
                        : $query),
            ])
            ->paginated([25, 50, 100, 'all']);
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::streamCsvFromRows(
            slug: 'stock-take',
            columns: [
                'Location' => 'location',
                'Repository' => 'repository',
                'Boxes' => 'boxes',
                'Items' => 'items',
            ],
            rows: $this->collectRows(),
        );
    }

    public function exportPdf(): Response
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::renderPdf(
            title: 'Stock take (by location)',
            slug: 'stock-take',
            headers: ['Location', 'Repository', 'Boxes', 'Items'],
            rows: $this->collectRows(),
        );
    }

    public function exportXlsx(): BinaryFileResponse|StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        $rows = [];
        /** @var Location $r */
        foreach ($this->reportQuery()->orderByDesc('box_count')->get() as $r) {
            $attrs = $r->getAttributes();
            $rows[] = [
                'location' => $r->name,
                'repository' => $r->repository?->getAttribute('code') ?? 'GLOBAL',
                'boxes' => (int) ($attrs['box_count'] ?? 0),
                'items' => (int) ($attrs['item_count'] ?? 0),
            ];
        }

        return ReportRenderer::streamXlsx(
            rows: $rows,
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
            'Location' => fn (array $r) => $r['location'],
            'Repository' => fn (array $r) => $r['repository'],
            'Boxes' => fn (array $r): int => (int) ($r['boxes'] ?? 0),
            'Items' => fn (array $r): int => (int) ($r['items'] ?? 0),
        ];
    }

    public function getReportTitle(): string
    {
        return 'Stock take (by location)';
    }

    public function getReportSlug(): string
    {
        return 'stock-take';
    }

    /**
     * Every location with its box count (destroyed boxes excluded — they're off
     * the floor) and its item (document) count. Location's own repository scope
     * keeps a tenant-restricted user to their rooms.
     */
    protected function reportQuery(): Builder
    {
        return Location::query()
            ->with('repository:id,code')
            ->withCount([
                'boxes as box_count' => fn (Builder $q) => $q->whereNull('destroyed_at'),
                'documents as item_count',
            ]);
    }

    /**
     * @return array<int, array<int, scalar|null>>
     */
    protected function collectRows(): array
    {
        $rows = [];
        /** @var Location $r */
        foreach ($this->reportQuery()->orderByDesc('box_count')->get() as $r) {
            $attrs = $r->getAttributes();
            $rows[] = [
                $r->name,
                $r->repository?->getAttribute('code') ?? 'GLOBAL',
                (int) ($attrs['box_count'] ?? 0),
                (int) ($attrs['item_count'] ?? 0),
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
