<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Widgets\PendingDisinfestationTable;
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
 * RFQ §3.1.10 #4 — Documents pending disinfestation.
 *
 * Surfaces the actionable backlog: any Document whose
 * `disinfestation_date` is NULL and whose current Box is NOT in
 * `PERM_OUT` (because PERM_OUT documents are off the floor and don't
 * need fumigation). Same filter shape as the
 * {@see PendingDisinfestationTable} dashboard
 * widget — but this Page renders the FULL list (not the top-10),
 * supports CSV / PDF export, and is sortable / filterable.
 *
 * Multi-tenancy: the BelongsToRepository scope on Document does the
 * heavy lifting — viewers / editors see only their tenants' pending docs.
 */
class PendingDisinfestationReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.reports.table';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'Documents pending disinfestation';

    protected static ?string $slug = 'reports/pending-disinfestation';

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
            ->defaultSort('created_at', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('identifier')
                    ->label('Identifier')
                    ->sortable()
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('document_type')
                    ->label('Type')
                    ->badge()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('series.code')
                    ->label('Series')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('batch.batch_number')
                    ->label('Batch')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('currentBox.box_number')
                    ->label('Current box')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('currentBox.barcode_status')
                    ->label('Box status')
                    ->badge()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Days waiting')
                    ->state(fn (Document $r): int => (int) round(now()->diffInDays($r->created_at, true)))
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state > 30 => 'danger',
                        $state > 7 => 'warning',
                        default => 'gray',
                    })
                    ->alignEnd()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('older_than_30_days')
                    ->label('> 30 days waiting')
                    ->toggle()
                    ->query(function (Builder $query, array $data): Builder {
                        if (! ($data['isActive'] ?? false)) {
                            return $query;
                        }

                        return $query->where('documents.created_at', '<=', now()->subDays(30));
                    }),
            ])
            ->paginated([25, 50, 100, 'all']);
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::streamCsv(
            title: 'Documents pending disinfestation',
            slug: 'pending-disinfestation',
            columns: [
                'Identifier' => 'identifier',
                'Type' => 'document_type',
                'Series' => 'series',
                'Batch' => 'batch',
                'Current box' => 'current_box',
                'Box status' => 'box_status',
                'Created at' => 'created_at',
                'Days waiting' => 'days_waiting',
            ],
            query: $this->reportQuery()->orderBy('documents.id'),
            rowMapper: fn (Document $r): array => self::pendingRow($r),
        );
    }

    public function exportPdf(): Response
    {
        abort_unless(static::canAccess(), 403);

        $rows = [];
        /** @var Document $r */
        foreach ($this->reportQuery()->orderBy('documents.created_at')->limit(5000)->get() as $r) {
            $rows[] = self::pendingRow($r);
        }

        return ReportRenderer::renderPdf(
            title: 'Documents pending disinfestation',
            slug: 'pending-disinfestation',
            headers: ['Identifier', 'Type', 'Series', 'Batch', 'Current box', 'Box status', 'Created at', 'Days waiting'],
            rows: $rows,
        );
    }

    /**
     * `disinfestation_date IS NULL` AND
     * (current_box_id IS NULL OR currentBox.barcode_status != 'PERM_OUT')
     */
    protected function reportQuery(): Builder
    {
        return Document::query()
            ->with(['currentBox:id,box_number,barcode_status', 'batch:id,batch_number', 'series:id,code'])
            ->whereNull('disinfestation_date')
            ->where(function (Builder $q): void {
                $q->whereNull('current_box_id')
                    ->orWhereHas('currentBox', function (Builder $q): void {
                        $q->where('barcode_status', '!=', 'PERM_OUT');
                    });
            });
    }

    /**
     * Flatten one pending Document into the column order this report uses.
     *
     * @return array<int, scalar|null>
     */
    protected static function pendingRow(Document $r): array
    {
        $created = $r->created_at;
        $days = $created instanceof \DateTimeInterface
            ? (int) round(now()->diffInDays($created, true))
            : 0;

        return [
            $r->identifier,
            $r->document_type,
            $r->series?->getAttribute('code'),
            $r->batch?->getAttribute('batch_number'),
            $r->currentBox?->getAttribute('box_number'),
            $r->currentBox?->getAttribute('barcode_status'),
            $created instanceof \DateTimeInterface ? $created->format('Y-m-d') : null,
            $days,
        ];
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
