<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Pages\Reports\Concerns\CapsExportRows;
use App\Filament\Pages\Reports\Concerns\HasReportTemplates;
use App\Models\Document;
use App\Models\ReportTemplate;
use App\Support\Reports\RasReconciliation;
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
 * NAF Queries Q3 — RAS ↔ NRA reconciliation.
 *
 * Extracts, per RAS-originated document, the reconciliation key the client
 * asked for — RAS Batch, RAS Box and the latest Barcode IN — alongside the
 * document's current NRA placement, and flags rows that cannot be reconciled
 * (any part of the key missing). See {@see RasReconciliation}.
 */
class RasNraReconciliationReport extends Page implements HasTable
{
    use CapsExportRows;
    use ExplainsPage;
    use HasReportTemplates;
    use InteractsWithTable;

    /** @see ReportTemplate::SOURCES */
    public const REPORT_SOURCE = ReportTemplate::SOURCE_RAS_NRA_RECONCILIATION;

    protected string $view = 'filament.pages.reports.table';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'RAS ↔ NRA reconciliation';

    protected static ?string $slug = 'reports/ras-nra-reconciliation';

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
            ->defaultSort('identifier')
            ->columns([
                Tables\Columns\TextColumn::make('identifier')
                    ->label('Document')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('ras_batch_1')
                    ->label('RAS batch')
                    ->state(fn (Document $r): ?string => RasReconciliation::latestRasBatch($r))
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('ras_box_1')
                    ->label('RAS box')
                    ->state(fn (Document $r): ?string => RasReconciliation::latestRasBox($r))
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('barcode_ras_1')
                    ->label('RAS barcode')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('latest_barcode_in')
                    ->label('Latest barcode IN')
                    ->state(fn (Document $r): ?string => RasReconciliation::latestBarcodeIn($r))
                    ->copyable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('batch.batch_number')
                    ->label('Current batch')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('currentBox.box_number')
                    ->label('Current box')
                    ->placeholder('—'),

                Tables\Columns\IconColumn::make('reconcilable')
                    ->label('Reconcilable')
                    ->boolean()
                    ->tooltip(fn (Document $r): string => RasReconciliation::isReconcilable($r)
                        ? 'Has the full RAS key (Batch + Box + latest Barcode IN).'
                        : 'Missing part of the RAS key — fill in the RAS Batch / Box / Barcode IN to reconcile.')
                    ->state(fn (Document $r): bool => RasReconciliation::isReconcilable($r)),
            ])
            ->filtersFormColumns(2)
            ->filters([
                Tables\Filters\TernaryFilter::make('reconcilable')
                    ->label('Reconcilable')
                    ->placeholder('Any')
                    ->trueLabel('Has full RAS key')
                    ->falseLabel('Missing part of the key')
                    ->queries(
                        // Reconcilable = RAS batch + RAS box + a barcode IN all present.
                        true: fn (Builder $q): Builder => $q
                            ->where(fn (Builder $b): Builder => $b
                                ->where(fn (Builder $bb) => $bb->whereNotNull('ras_batch_2')->where('ras_batch_2', '!=', ''))
                                ->orWhere(fn (Builder $bb) => $bb->whereNotNull('ras_batch_1')->where('ras_batch_1', '!=', '')))
                            ->where(fn (Builder $b): Builder => $b
                                ->where(fn (Builder $bb) => $bb->whereNotNull('ras_box_2')->where('ras_box_2', '!=', ''))
                                ->orWhere(fn (Builder $bb) => $bb->whereNotNull('ras_box_1')->where('ras_box_1', '!=', '')))
                            ->where(fn (Builder $b): Builder => $b
                                ->where(fn (Builder $bb) => $bb->whereNotNull('barcode_in_2')->where('barcode_in_2', '!=', ''))
                                ->orWhere(fn (Builder $bb) => $bb->whereNotNull('barcode_in')->where('barcode_in', '!=', ''))
                                ->orWhereHas('currentBox', fn (Builder $box) => $box->where('barcode_status', 'IN')->whereNotNull('barcode')->where('barcode', '!=', ''))),
                        false: fn (Builder $q): Builder => $q
                            ->where(fn (Builder $b): Builder => $b
                                ->where(fn (Builder $bb) => $bb
                                    ->where(fn (Builder $x) => $x->whereNull('ras_batch_2')->orWhere('ras_batch_2', ''))
                                    ->where(fn (Builder $x) => $x->whereNull('ras_batch_1')->orWhere('ras_batch_1', '')))
                                ->orWhere(fn (Builder $bb) => $bb
                                    ->where(fn (Builder $x) => $x->whereNull('ras_box_2')->orWhere('ras_box_2', ''))
                                    ->where(fn (Builder $x) => $x->whereNull('ras_box_1')->orWhere('ras_box_1', '')))
                                ->orWhere(fn (Builder $bb) => $bb
                                    ->where(fn (Builder $x) => $x->whereNull('barcode_in_2')->orWhere('barcode_in_2', ''))
                                    ->where(fn (Builder $x) => $x->whereNull('barcode_in')->orWhere('barcode_in', ''))
                                    ->whereDoesntHave('currentBox', fn (Builder $box) => $box->where('barcode_status', 'IN')->whereNotNull('barcode')->where('barcode', '!=', '')))),
                    ),
            ])
            ->paginated([25, 50, 100, 'all']);
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::streamCsv(
            title: 'RAS ↔ NRA reconciliation',
            slug: 'ras-nra-reconciliation',
            columns: [
                'Document' => 'identifier',
                'RAS batch' => 'ras_batch',
                'RAS box' => 'ras_box',
                'RAS barcode' => 'ras_barcode',
                'Latest barcode IN' => 'latest_barcode_in',
                'Current batch' => 'current_batch',
                'Current box' => 'current_box',
                'Reconcilable' => 'reconcilable',
            ],
            query: $this->reportQuery()->orderBy('documents.id'),
            rowMapper: fn (Document $r): array => self::reconciliationRow($r),
        );
    }

    public function exportPdf(): Response
    {
        abort_unless(static::canAccess(), 403);

        $rows = [];
        /** @var Document $r */
        foreach ($this->reportQuery()->orderBy('identifier')->limit(5000)->get() as $r) {
            $rows[] = self::reconciliationRow($r);
        }

        return ReportRenderer::renderPdf(
            title: 'RAS ↔ NRA reconciliation',
            slug: 'ras-nra-reconciliation',
            headers: ['Document', 'RAS batch', 'RAS box', 'RAS barcode', 'Latest barcode IN', 'Current batch', 'Current box', 'Reconcilable'],
            rows: $rows,
        );
    }

    public function exportXlsx(): BinaryFileResponse|StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        $query = $this->getFilteredTableQuery() ?? $this->reportQuery();
        $rows = $this->fetchExportRowsWithCap(
            $query->with(['batch:id,batch_number', 'currentBox:id,box_number,barcode,barcode_status'])
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
            'Document' => fn (Document $r) => $r->identifier,
            'RAS batch' => fn (Document $r): ?string => RasReconciliation::latestRasBatch($r),
            'RAS box' => fn (Document $r): ?string => RasReconciliation::latestRasBox($r),
            'RAS barcode' => fn (Document $r) => $r->barcode_ras_1,
            'Latest barcode IN' => fn (Document $r): ?string => RasReconciliation::latestBarcodeIn($r),
            'Current batch' => fn (Document $r) => $r->batch?->getAttribute('batch_number'),
            'Current box' => fn (Document $r) => $r->currentBox?->getAttribute('box_number'),
            'Reconcilable' => fn (Document $r): string => RasReconciliation::isReconcilable($r) ? 'Yes' : 'No',
        ];
    }

    public function getReportTitle(): string
    {
        return 'RAS ↔ NRA reconciliation';
    }

    public function getReportSlug(): string
    {
        return 'ras-nra-reconciliation';
    }

    /**
     * RAS-originated documents: any of the RAS-origin columns is recorded.
     */
    protected function reportQuery(): Builder
    {
        return Document::query()
            ->with(['batch:id,batch_number', 'currentBox:id,box_number,barcode,barcode_status'])
            ->where(function (Builder $q): void {
                $q->where(fn (Builder $b) => $b->whereNotNull('ras_batch_1')->where('ras_batch_1', '!=', ''))
                    ->orWhere(fn (Builder $b) => $b->whereNotNull('ras_batch_2')->where('ras_batch_2', '!=', ''))
                    ->orWhere(fn (Builder $b) => $b->whereNotNull('ras_box_1')->where('ras_box_1', '!=', ''))
                    ->orWhere(fn (Builder $b) => $b->whereNotNull('ras_box_2')->where('ras_box_2', '!=', ''))
                    ->orWhere(fn (Builder $b) => $b->whereNotNull('barcode_ras_1')->where('barcode_ras_1', '!=', ''))
                    ->orWhere(fn (Builder $b) => $b->whereNotNull('barcode_ras_2')->where('barcode_ras_2', '!=', ''))
                    ->orWhere(fn (Builder $b) => $b->whereNotNull('barcode_in_2')->where('barcode_in_2', '!=', ''));
            });
    }

    /**
     * @return array<int, scalar|null>
     */
    protected static function reconciliationRow(Document $r): array
    {
        return [
            $r->identifier,
            RasReconciliation::latestRasBatch($r),
            RasReconciliation::latestRasBox($r),
            $r->barcode_ras_1,
            RasReconciliation::latestBarcodeIn($r),
            $r->batch?->getAttribute('batch_number'),
            $r->currentBox?->getAttribute('box_number'),
            RasReconciliation::isReconcilable($r) ? 'Yes' : 'No',
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
