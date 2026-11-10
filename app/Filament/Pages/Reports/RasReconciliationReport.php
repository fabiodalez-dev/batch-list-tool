<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Pages\Reports\Concerns\CapsExportRows;
use App\Filament\Pages\Reports\Concerns\ExportsWhatIsOnScreen;
use App\Filament\Resources\BoxResource;
use App\Models\RasReconciliationRow as Row;
use App\Models\RasReconciliationRun;
use App\Support\ActiveRepository;
use App\Support\Reports\RasReconciliation\Reconciler;
use App\Support\Reports\ReportRenderer;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Client 2026-11-10, Report 1 — RAS ↔ NRA reconciliation.
 *
 * "What we do is check that our system tallies with the RAS report … Maybe we
 * upload the report and the system matches them automatically and only shows
 * us the discrepancies?"
 *
 * The operator uploads the RAS export; {@see Reconciler} stores it with the
 * outcome of the comparison, and this page pages through the stored rows. The
 * latest upload per repository is kept, so the operator can come back to it,
 * fix our records and press "Compare again".
 */
class RasReconciliationReport extends Page implements HasTable
{
    use CapsExportRows;
    use ExplainsPage;
    use ExportsWhatIsOnScreen;
    use InteractsWithTable;

    /** Upload size limit, in kilobytes (the 12,610-row sample is 500 KB). */
    public const int MAX_UPLOAD_KB = 20 * 1024;

    /** Rows in the PDF export (see exportPdf()). */
    public const int PDF_ROW_CAP = 100;

    /** The run on screen; set on the server only. */
    #[Locked]
    public ?int $runId = null;

    protected string $view = 'filament.pages.reports.ras-reconciliation';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'RAS ↔ NRA reconciliation';

    protected static ?string $slug = 'reports/ras-reconciliation';

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
        $this->runId = self::latestRunFor(app(ActiveRepository::class)->id(), self::userId())?->id;
    }

    public static function latestRunFor(?int $repositoryId, ?int $userId): ?RasReconciliationRun
    {
        return RasReconciliationRun::query()
            ->forScope($repositoryId, $userId)
            ->latest('id')
            ->first();
    }

    public function run(): ?RasReconciliationRun
    {
        return $this->runId === null
            ? null
            : RasReconciliationRun::query()->with('user:id,name')->find($this->runId);
    }

    public function compareAgain(): void
    {
        abort_unless(static::canAccess(), 403);
        $run = $this->run();
        if ($run === null) {
            return;
        }

        app(Reconciler::class)->compare($run);
        $this->resetTable();
        $this->notify('success', 'Compared again with our records as they are now.');
    }

    /**
     * Summary tile clicked: show that outcome only.
     */
    public function showOutcome(string $outcome): void
    {
        if ($outcome === Row::RECONCILED) {
            $this->tableFilters['discrepancies_only']['isActive'] = false;
            $this->tableFilters['outcome']['values'] = [];
        } elseif (array_key_exists($outcome, Row::OUTCOMES)) {
            $this->tableFilters['discrepancies_only']['isActive'] = true;
            $this->tableFilters['outcome']['values'] = [$outcome];
        } else {
            $this->tableFilters['discrepancies_only']['isActive'] = true;
            $this->tableFilters['outcome']['values'] = [];
        }
        $this->resetPage();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->reportQuery())
            ->defaultSort('id')
            ->emptyStateHeading(fn (): string => $this->runId === null ? 'No RAS report uploaded yet' : 'Nothing to show')
            ->emptyStateDescription(fn (): string => $this->runId === null
                ? 'Upload the RAS export (Excel or CSV) to compare it with our RAS boxes.'
                : 'No row matches the filters. With "Discrepancies only" on, an empty list means everything tallies.')
            ->columns([
                Tables\Columns\TextColumn::make('outcome')
                    ->label('Result')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Row::OUTCOMES[$state] ?? $state)
                    ->color(fn (string $state): string => self::outcomeColor($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('ras_barcode')
                    ->label('RAS barcode')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('ras_reference')
                    ->label('RAS batch / box')
                    ->description(fn (Row $r): ?string => $r->ras_batch !== null ? sprintf('Batch %s, box %s', $r->ras_batch, $r->ras_box) : null)
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('ras_status_raw')
                    ->label('RAS status')
                    ->color(fn (Row $r): ?string => $r->status_mismatch ? 'danger' : null)
                    ->weight(fn (Row $r): ?string => $r->status_mismatch ? 'bold' : null)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('our_barcode')
                    ->label('Our barcode')
                    ->description(fn (Row $r): ?string => $r->our_barcode_replaced ? 'earlier barcode' : null)
                    ->url(fn (Row $r): ?string => self::boxUrl($r))
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('our_reference')
                    ->label('Our batch / box')
                    ->state(fn (Row $r): ?string => $r->ourReference())
                    ->url(fn (Row $r): ?string => self::boxUrl($r))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('our_status')
                    ->label('Our status')
                    ->color(fn (Row $r): ?string => $r->status_mismatch ? 'danger' : null)
                    ->weight(fn (Row $r): ?string => $r->status_mismatch ? 'bold' : null)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('note')
                    ->label('Note')
                    ->wrap()
                    ->placeholder(''),

                Tables\Columns\TextColumn::make('ras_date')
                    ->label('RAS date')
                    ->date('d-m-Y')
                    ->sortable()
                    ->toggleable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('ras_container')
                    ->label('RAS container')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('sheet_row')
                    ->label('Row in file')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—'),
            ])
            ->filtersFormColumns(2)
            ->filters([
                Tables\Filters\SelectFilter::make('outcome')
                    ->label('Discrepancy')
                    ->multiple()
                    ->options(array_diff_key(Row::OUTCOMES, [Row::RECONCILED => true]))
                    ->query(function (Builder $query, array $data): Builder {
                        /** @var array<int, string> $values */
                        $values = array_values(array_filter((array) ($data['values'] ?? [])));
                        if ($values === []) {
                            return $query;
                        }

                        // A row whose batch/box differs may have a different
                        // status too: "Status differs" lists it as well.
                        return $query->where(fn (Builder $q): Builder => in_array(Row::STATUS_DIFFERS, $values, true)
                            ? $q->whereIn('outcome', $values)->orWhere('status_mismatch', true)
                            : $q->whereIn('outcome', $values));
                    }),

                Tables\Filters\Filter::make('discrepancies_only')
                    ->label('Discrepancies only')
                    ->toggle()
                    ->default()
                    ->query(fn (Builder $query): Builder => $query->where('outcome', '!=', Row::RECONCILED)),
            ])
            ->paginated([25, 50, 100]);
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::streamCsv(
            title: $this->getReportTitle(),
            slug: $this->getReportSlug(),
            columns: array_fill_keys(self::exportHeaders(), ''),
            query: $this->exportQuery()->orderBy('id'),
            rowMapper: fn (Row $r): array => self::exportRow($r),
        );
    }

    public function exportPdf(): Response
    {
        abort_unless(static::canAccess(), 403);

        // Fewer columns and rows than CSV / Excel: DomPDF lays out every cell
        // in memory (measured 2026-11-10 with the shared layout: 100 rows of
        // 8 columns ≈ 80 MB, 200 rows ≈ 240 MB). The full list is in the
        // CSV / Excel exports.
        $total = $this->exportQuery()->count();
        $rows = [];
        /** @var Row $r */
        foreach ($this->exportQuery()->orderBy('id')->limit(self::PDF_ROW_CAP)->get() as $r) {
            $rows[] = [
                $r->outcomeLabel(),
                $r->ras_barcode,
                $r->ras_reference,
                $r->ras_status_raw,
                $r->our_barcode,
                $r->ourReference(),
                $r->our_status,
                $r->note,
            ];
        }

        $run = $this->run();
        $subtitle = $run === null ? null : sprintf('RAS file %s, uploaded %s', $run->original_filename, $run->created_at?->format('d-m-Y H:i'));
        if ($total > self::PDF_ROW_CAP) {
            $subtitle .= sprintf(' — first %s of %s rows; use Export Excel for all of them', number_format(self::PDF_ROW_CAP), number_format($total));
        }

        return ReportRenderer::renderPdf(
            title: $this->getReportTitle(),
            slug: $this->getReportSlug(),
            headers: ['Result', 'RAS barcode', 'RAS reference', 'RAS status', 'Our barcode', 'Our batch / box', 'Our status', 'Note'],
            rows: $rows,
            subtitle: $subtitle,
        );
    }

    public function exportXlsx(): BinaryFileResponse|StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        $rows = $this->fetchExportRowsWithCap($this->exportQuery()->orderBy('id'));

        return ReportRenderer::streamXlsx(
            rows: $rows,
            columns: $this->getXlsxColumns(),
            filename: ReportRenderer::filename($this->getReportSlug(), 'xlsx'),
            title: $this->getReportTitle(),
        );
    }

    /**
     * @return array<string, callable(Row): mixed>
     */
    public function getXlsxColumns(): array
    {
        $columns = [];
        foreach (self::exportHeaders() as $i => $header) {
            $columns[$header] = fn (Row $r): mixed => self::exportRow($r)[$i];
        }

        return $columns;
    }

    public function getReportTitle(): string
    {
        return 'RAS ↔ NRA reconciliation';
    }

    public function getReportSlug(): string
    {
        return 'ras-reconciliation';
    }

    public static function outcomeColor(string $outcome): string
    {
        return match ($outcome) {
            Row::RECONCILED => 'success',
            Row::STATUS_DIFFERS, Row::BARCODE_DIFFERS => 'warning',
            Row::OLD_BARCODE_MISSING => 'gray',
            Row::BATCH_BOX_DIFFERS => 'danger',
            default => 'info',
        };
    }

    /**
     * Read an uploaded RAS export into a new run for the active repository.
     */
    protected function processUpload(string $path, string $originalName): void
    {
        abort_unless(static::canAccess(), 403);

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (! in_array($extension, ['xlsx', 'xls', 'csv'], true)) {
            $this->notify('danger', 'Upload an Excel (.xlsx / .xls) or CSV file.');

            return;
        }

        try {
            $run = app(Reconciler::class)->upload(
                $path,
                $extension,
                $originalName,
                app(ActiveRepository::class)->id(),
                self::userId(),
            );
        } catch (\Throwable $e) {
            report($e);
            $this->notify('danger', 'The file could not be read: ' . $e->getMessage());

            return;
        }

        $this->runId = $run->id;
        $this->resetTable();

        $summary = Reconciler::summary($run);
        $discrepancies = array_sum($summary) - $summary[Row::RECONCILED] - $summary['status_mismatch'];
        $this->notify(
            $discrepancies === 0 ? 'success' : 'warning',
            sprintf('%s RAS rows compared: %s reconciled, %s discrepancies.', number_format($run->ras_rows), number_format($summary[Row::RECONCILED]), number_format($discrepancies)),
        );
    }

    /**
     * @return Builder<Row>
     */
    protected function reportQuery(): Builder
    {
        return Row::query()->where('run_id', $this->runId ?? 0);
    }

    /** @return array<int, string> */
    protected static function exportHeaders(): array
    {
        return [
            'Result', 'RAS barcode', 'RAS reference', 'RAS batch', 'RAS box', 'RAS status', 'RAS date', 'RAS container',
            'Our barcode', 'Our barcode is an earlier one', 'Our batch', 'Our box', 'Our status', 'Note', 'Row in file',
        ];
    }

    /**
     * @return array<int, scalar|null>
     */
    protected static function exportRow(Row $r): array
    {
        return [
            $r->outcomeLabel(),
            $r->ras_barcode,
            $r->ras_reference,
            $r->ras_batch,
            $r->ras_box,
            $r->ras_status_raw,
            $r->ras_date?->format('d-m-Y'),
            $r->ras_container,
            $r->our_barcode,
            $r->our_barcode_replaced ? 'Yes' : '',
            $r->our_batch,
            $r->our_box,
            $r->our_status,
            $r->note,
            $r->sheet_row,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('upload')
                ->label('Upload RAS report')
                ->icon('heroicon-o-arrow-up-tray')
                ->modalHeading('Upload the RAS report')
                ->modalDescription('The export RAS sends (Excel or CSV). It replaces the previous upload for this repository.')
                ->modalSubmitActionLabel('Compare')
                ->schema([
                    FileUpload::make('file')
                        ->label('RAS report')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                            'text/csv',
                            'application/csv',
                            'text/plain',
                        ])
                        ->maxSize(self::MAX_UPLOAD_KB)
                        // Read straight from the temporary upload; nothing is kept on disk.
                        ->storeFiles(false)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $file = $data['file'] ?? null;
                    if (is_array($file)) {
                        $file = reset($file) ?: null;
                    }
                    if (! $file instanceof TemporaryUploadedFile) {
                        $this->notify('danger', 'No file uploaded.');

                        return;
                    }
                    $this->processUpload((string) $file->getRealPath(), $file->getClientOriginalName());
                }),

            Action::make('compareAgain')
                ->label('Compare again')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->tooltip('Recheck the same RAS report against our records as they are now')
                ->visible(fn (): bool => $this->runId !== null)
                ->action(fn () => $this->compareAgain()),

            Action::make('exportCsv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => $this->runId !== null)
                ->action(fn () => $this->exportCsv()),

            Action::make('exportXlsx')
                ->label('Export Excel')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn (): bool => $this->runId !== null)
                ->action(fn () => $this->exportXlsx()),

            Action::make('exportPdf')
                ->label('Export PDF')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->visible(fn (): bool => $this->runId !== null)
                ->action(fn () => $this->exportPdf()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        $run = $this->run();

        return [
            'run' => $run,
            'summary' => $run === null ? null : Reconciler::summary($run),
            'outcomes' => Row::OUTCOMES,
        ];
    }

    private static function boxUrl(Row $r): ?string
    {
        return $r->box_id === null ? null : BoxResource::getUrl('view', ['record' => $r->box_id]);
    }

    private static function userId(): ?int
    {
        $id = auth()->id();

        return $id === null ? null : (int) $id;
    }

    private function notify(string $status, string $message): void
    {
        Notification::make()->status($status)->title($message)->send();
    }
}
