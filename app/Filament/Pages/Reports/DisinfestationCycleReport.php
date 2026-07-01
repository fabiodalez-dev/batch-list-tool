<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Pages\Reports\Concerns\CapsExportRows;
use App\Filament\Pages\Reports\Concerns\HasReportTemplates;
use App\Models\Box;
use App\Models\ReportTemplate;
use App\Support\Reports\DisinfestationCapacity;
use App\Support\Reports\DisinfestationCycle;
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
 * NAF Queries Q1 — Disinfestation cycle plan (by box).
 *
 * The disinfestation process runs BY BOX (client answer). A cycle is 40 days
 * but service-provider delays push it to 80. This report lists the boxes that
 * should go for disinfestation — the never-disinfested ones FIRST, then the
 * ones going round for a second (or later) cycle, most overdue first — exactly
 * the ordering the client asked for. A Big Brown Box counts as 2 slots against
 * the cycle limit ({@see DisinfestationCapacity}).
 *
 * The per-DOCUMENT disinfestation view (done in-house, recorded on
 * documents.disinfestation_date) is served by
 * {@see PendingDisinfestationReport}.
 */
class DisinfestationCycleReport extends Page implements HasTable
{
    use CapsExportRows;
    use ExplainsPage;
    use HasReportTemplates;
    use InteractsWithTable;

    /** @see ReportTemplate::SOURCES */
    public const REPORT_SOURCE = ReportTemplate::SOURCE_DISINFESTATION_CYCLE;

    protected string $view = 'filament.pages.reports.table';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'Disinfestation cycle plan';

    protected static ?string $slug = 'reports/disinfestation-cycle';

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
            // Never-disinfested first (NULL), then oldest disinfestation date
            // (most overdue) first — the client's requested cycle ordering.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->orderByRaw('disinfestation_date is not null')
                ->orderBy('disinfestation_date'))
            ->columns([
                Tables\Columns\TextColumn::make('box_number')
                    ->label('Box')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('batch.batch_number')
                    ->label('Batch')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('location.name')
                    ->label('Location')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('cycle_weight')
                    ->label('Slots')
                    ->state(fn (Box $r): int => DisinfestationCapacity::weightForBox($r))
                    ->badge()
                    ->color(fn (int $state): string => $state > 1 ? 'warning' : 'gray')
                    ->alignEnd()
                    ->tooltip('Capacity slots against the cycle limit (Big Brown Box = 2).'),

                Tables\Columns\TextColumn::make('disinfestation_date')
                    ->label('Last disinfestation')
                    ->date()
                    ->placeholder('Never')
                    ->sortable(),

                Tables\Columns\TextColumn::make('cycle_status')
                    ->label('Cycle status')
                    ->state(fn (Box $r): string => DisinfestationCycle::status($r->disinfestation_date))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        DisinfestationCycle::NEVER => 'danger',
                        DisinfestationCycle::OVERDUE => 'danger',
                        DisinfestationCycle::DUE => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('next_due')
                    ->label('Next due')
                    ->state(fn (Box $r): ?string => DisinfestationCycle::dueDate($r->disinfestation_date)?->toDateString())
                    ->placeholder('Now'),
            ])
            ->filtersFormColumns(2)
            ->filters([
                Tables\Filters\SelectFilter::make('cycle_status')
                    ->label('Cycle status')
                    ->options([
                        DisinfestationCycle::NEVER => 'Never disinfested',
                        DisinfestationCycle::DUE => 'Due (40+ days)',
                        DisinfestationCycle::OVERDUE => 'Overdue (80+ days)',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return match ($value) {
                            DisinfestationCycle::NEVER => $query->whereNull('disinfestation_date'),
                            DisinfestationCycle::DUE => $query->whereNotNull('disinfestation_date')
                                ->whereBetween('disinfestation_date', [
                                    now()->subDays(DisinfestationCycle::OVERDUE_DAYS)->startOfDay(),
                                    now()->subDays(DisinfestationCycle::DUE_DAYS)->endOfDay(),
                                ]),
                            DisinfestationCycle::OVERDUE => $query->whereNotNull('disinfestation_date')
                                ->where('disinfestation_date', '<=', now()->subDays(DisinfestationCycle::OVERDUE_DAYS)->startOfDay()),
                            default => $query,
                        };
                    }),

                Tables\Filters\SelectFilter::make('box_type')
                    ->label('Box type')
                    ->options(array_combine(Box::TYPES, Box::TYPES))
                    ->multiple()
                    ->query(function (Builder $query, array $data): Builder {
                        $values = $data['values'] ?? [];

                        return empty($values) ? $query : $query->whereIn('box_type', $values);
                    }),
            ])
            ->paginated([25, 50, 100, 'all']);
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::streamCsv(
            title: 'Disinfestation cycle plan',
            slug: 'disinfestation-cycle',
            columns: [
                'Box' => 'box_number',
                'Batch' => 'batch',
                'Location' => 'location',
                'Slots' => 'slots',
                'Last disinfestation' => 'last_disinfestation',
                'Cycle status' => 'cycle_status',
                'Next due' => 'next_due',
            ],
            query: $this->reportQuery()->orderBy('boxes.id'),
            rowMapper: fn (Box $r): array => self::cycleRow($r),
        );
    }

    public function exportPdf(): Response
    {
        abort_unless(static::canAccess(), 403);

        $rows = [];
        /** @var Box $r */
        foreach ($this->reportQuery()->orderByRaw('disinfestation_date is not null')->orderBy('disinfestation_date')->limit(5000)->get() as $r) {
            $rows[] = self::cycleRow($r);
        }

        return ReportRenderer::renderPdf(
            title: 'Disinfestation cycle plan',
            slug: 'disinfestation-cycle',
            headers: ['Box', 'Batch', 'Location', 'Slots', 'Last disinfestation', 'Cycle status', 'Next due'],
            rows: $rows,
        );
    }

    public function exportXlsx(): BinaryFileResponse|StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        $query = $this->getFilteredTableQuery() ?? $this->reportQuery();
        $rows = $this->fetchExportRowsWithCap(
            $query->with(['batch:id,batch_number', 'location:id,name', 'documents:id,current_box_id,current_box_type'])
        );

        return ReportRenderer::streamXlsx(
            rows: $rows,
            columns: $this->getXlsxColumns(),
            filename: ReportRenderer::filename($this->getReportSlug(), 'xlsx'),
            title: $this->getReportTitle(),
        );
    }

    /**
     * @return array<string, callable(Box): mixed>
     */
    public function getXlsxColumns(): array
    {
        return [
            'Box' => fn (Box $r) => $r->box_number,
            'Batch' => fn (Box $r) => $r->batch?->getAttribute('batch_number'),
            'Location' => fn (Box $r) => $r->location?->getAttribute('name'),
            'Slots' => fn (Box $r): int => DisinfestationCapacity::weightForBox($r),
            'Last disinfestation' => fn (Box $r) => $r->disinfestation_date instanceof \DateTimeInterface ? $r->disinfestation_date->format('Y-m-d') : 'Never',
            'Cycle status' => fn (Box $r): string => ucfirst(DisinfestationCycle::status($r->disinfestation_date)),
            'Next due' => fn (Box $r): string => DisinfestationCycle::dueDate($r->disinfestation_date)?->toDateString() ?? 'Now',
        ];
    }

    public function getReportTitle(): string
    {
        return 'Disinfestation cycle plan';
    }

    public function getReportSlug(): string
    {
        return 'disinfestation-cycle';
    }

    /**
     * Plannable boxes: never disinfested OR past the 40-day cycle mark.
     * Destroyed boxes are excluded (they're off the floor).
     */
    protected function reportQuery(): Builder
    {
        return Box::query()
            ->with([
                'batch:id,batch_number',
                'location:id,name',
                'documents:id,current_box_id,current_box_type',
            ])
            ->whereNull('destroyed_at')
            ->where(function (Builder $q): void {
                $q->whereNull('disinfestation_date')
                    ->orWhere('disinfestation_date', '<=', now()->subDays(DisinfestationCycle::DUE_DAYS)->startOfDay());
            });
    }

    /**
     * @return array<int, scalar|null>
     */
    protected static function cycleRow(Box $r): array
    {
        return [
            $r->box_number,
            $r->batch?->getAttribute('batch_number'),
            $r->location?->getAttribute('name'),
            DisinfestationCapacity::weightForBox($r),
            $r->disinfestation_date instanceof \DateTimeInterface ? $r->disinfestation_date->format('Y-m-d') : 'Never',
            ucfirst(DisinfestationCycle::status($r->disinfestation_date)),
            DisinfestationCycle::dueDate($r->disinfestation_date)?->toDateString() ?? 'Now',
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
