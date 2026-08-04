<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Pages\Reports\Concerns\CapsExportRows;
use App\Filament\Pages\Reports\Concerns\HasReportTemplates;
use App\Models\Document;
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
 * Client feedback (Charlene, 2026-08-04) — Documents by location.
 *
 * A stock-take view: every Document with its EFFECTIVE location (its own pinned
 * location if set, otherwise the location of the box it sits in — see
 * {@see Document::effectiveLocation()}), the box it currently sits in (number +
 * barcode) when it is still in one, and a marker showing whether the location
 * was inherited from the box or set on the document. Documents no longer in a
 * box show only their own location.
 *
 * Multi-tenancy: the BelongsToRepository scope on Document scopes the rows to
 * the viewer's tenants automatically.
 */
class DocumentLocationReport extends Page implements HasTable
{
    use CapsExportRows;
    use ExplainsPage;
    use HasReportTemplates;
    use InteractsWithTable;

    /** @see ReportTemplate::SOURCES */
    public const REPORT_SOURCE = ReportTemplate::SOURCE_DOCUMENT_LOCATIONS;

    protected string $view = 'filament.pages.reports.table';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'Documents by location';

    protected static ?string $slug = 'reports/document-locations';

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
            ->defaultSort('identifier', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('identifier')
                    ->label('Identifier')
                    ->sortable()
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('currentBox.box_number')
                    ->label('Current box')
                    ->placeholder('— (not in a box)'),

                Tables\Columns\TextColumn::make('currentBox.barcode')
                    ->label('Box barcode')
                    ->copyable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('effective_location')
                    ->label('Location')
                    ->state(fn (Document $r): string => $r->effectiveLocation()?->breadcrumb() ?? '—')
                    ->wrap(),

                Tables\Columns\TextColumn::make('location_source')
                    ->label('Location from')
                    ->badge()
                    ->state(fn (Document $r): ?string => $r->effectiveLocation() === null
                        ? null
                        : ($r->locationIsInherited() ? 'box' : 'document'))
                    ->color(fn (?string $state): string => $state === 'document' ? 'success' : 'gray')
                    ->placeholder('—'),
            ])
            ->filtersFormColumns(2)
            ->filters([
                Tables\Filters\TernaryFilter::make('in_a_box')
                    ->label('Currently in a box?')
                    ->placeholder('Any')
                    ->trueLabel('In a box')
                    ->falseLabel('Not in a box')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereNotNull('documents.current_box_id'),
                        false: fn (Builder $q): Builder => $q->whereNull('documents.current_box_id'),
                    ),

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

                // Effective-location filter: match the document's OWN location OR
                // the location of the box it sits in (mirrors effectiveLocation()).
                Tables\Filters\SelectFilter::make('effective_location_id')
                    ->label('Location (effective)')
                    ->options(fn (): array => Location::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->multiple()
                    ->query(function (Builder $query, array $data): Builder {
                        $values = $data['values'] ?? [];
                        if (empty($values)) {
                            return $query;
                        }

                        return $query->where(function (Builder $q) use ($values): void {
                            $q->whereIn('documents.location_id', $values)
                                ->orWhereHas('currentBox', function (Builder $inner) use ($values): void {
                                    $inner->whereNull('documents.location_id')
                                        ->whereIn('location_id', $values);
                                });
                        });
                    }),
            ])
            ->paginated([25, 50, 100, 'all']);
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::streamCsv(
            title: $this->getReportTitle(),
            slug: $this->getReportSlug(),
            columns: [
                'Identifier' => 'identifier',
                'Current box' => 'current_box',
                'Box barcode' => 'box_barcode',
                'Location' => 'location',
                'Location from' => 'location_source',
                'In a box' => 'in_a_box',
            ],
            query: $this->reportQuery()->orderBy('documents.identifier'),
            rowMapper: fn (Document $r): array => self::locationRow($r),
        );
    }

    public function exportPdf(): Response
    {
        abort_unless(static::canAccess(), 403);

        $rows = [];
        /** @var Document $r */
        foreach ($this->reportQuery()->orderBy('documents.identifier')->limit(5000)->get() as $r) {
            $rows[] = self::locationRow($r);
        }

        return ReportRenderer::renderPdf(
            title: $this->getReportTitle(),
            slug: $this->getReportSlug(),
            headers: ['Identifier', 'Current box', 'Box barcode', 'Location', 'Location from', 'In a box'],
            rows: $rows,
        );
    }

    public function exportXlsx(): BinaryFileResponse|StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        $query = $this->getFilteredTableQuery() ?? $this->reportQuery();
        $rows = $this->fetchExportRowsWithCap(
            $query->with(['currentBox.location', 'location'])->orderBy('documents.identifier'),
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
            'Current box' => fn (Document $r) => $r->currentBox?->getAttribute('box_number'),
            'Box barcode' => fn (Document $r) => $r->currentBox?->getAttribute('barcode'),
            'Location' => fn (Document $r) => $r->effectiveLocation()?->breadcrumb(),
            'Location from' => fn (Document $r): ?string => $r->effectiveLocation() === null
                ? null
                : ($r->locationIsInherited() ? 'box' : 'document'),
            'In a box' => fn (Document $r): string => $r->current_box_id !== null ? 'Yes' : 'No',
        ];
    }

    public function getReportTitle(): string
    {
        return 'Documents by location';
    }

    public function getReportSlug(): string
    {
        return 'document-locations';
    }

    public function mount(): void
    {
        $this->applyTemplateFromQuery();
    }

    /**
     * Every document, with its effective location resolved for display.
     */
    protected function reportQuery(): Builder
    {
        return Document::query()
            ->with(['currentBox:id,box_number,barcode,location_id', 'currentBox.location', 'location']);
    }

    /**
     * Flatten one Document into the column order this report uses.
     *
     * @return array<int, scalar|null>
     */
    protected static function locationRow(Document $r): array
    {
        return [
            $r->identifier,
            $r->currentBox?->getAttribute('box_number'),
            $r->currentBox?->getAttribute('barcode'),
            $r->effectiveLocation()?->breadcrumb(),
            $r->effectiveLocation() === null
                ? null
                : ($r->locationIsInherited() ? 'box' : 'document'),
            $r->current_box_id !== null ? 'Yes' : 'No',
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
