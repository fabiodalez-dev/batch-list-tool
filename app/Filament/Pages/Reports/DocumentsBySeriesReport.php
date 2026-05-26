<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Models\Document;
use App\Support\Reports\ReportRenderer;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
    use InteractsWithTable;

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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => $this->exportCsv()),

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
