<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Pages\Reports\Concerns\CapsExportRows;
use App\Filament\Pages\Reports\Concerns\HasReportTemplates;
use App\Filament\Pages\Reports\Filters\DateRangeFilter;
use App\Models\Box;
use App\Models\BoxMovement;
use App\Models\ReportTemplate;
use App\Models\Scopes\RepositoryScope;
use App\Support\Reports\ReportRenderer;
use Filament\Actions\Action;
use Filament\Forms;
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
 * RFQ §3.1.10 #5 — Box movement history.
 *
 * Chronological list of `box_movements` rows with optional filters by
 * date range and target box. Movements without a target box (legacy
 * data) still appear so operators don't lose audit-trail visibility.
 *
 * Multi-tenancy: BoxMovement carries its own `repository_id` and is scoped by
 * the standard {@see RepositoryScope}, so this report is
 * automatically tenant-correct for non-admin users.
 */
class BoxMovementHistoryReport extends Page implements HasTable
{
    use CapsExportRows;
    use ExplainsPage;
    use HasReportTemplates;
    use InteractsWithTable;

    /** @see ReportTemplate::SOURCES */
    public const REPORT_SOURCE = ReportTemplate::SOURCE_BOX_MOVEMENTS;

    protected string $view = 'filament.pages.reports.table';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'Box movement history';

    protected static ?string $slug = 'reports/box-movements';

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
            ->defaultSort('movement_date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('movement_date')
                    ->label('Date')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('document.identifier')
                    ->label('Document')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('fromBox.box_number')
                    ->label('From box')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('toBox.box_number')
                    ->label('To box')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Reason')
                    ->limit(60)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('By')
                    ->placeholder('—'),
            ])
            ->filtersFormColumns(2)
            ->filters([
                // Original date_range filter — kept exactly as-is for
                // backwards compatibility with the existing test that
                // references `tableFilters.date_range.from`.
                Tables\Filters\Filter::make('date_range')
                    ->label('Movement date (legacy)')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From date'),
                        Forms\Components\DatePicker::make('to')->label('To date'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $q, $date) => $q->where('movement_date', '>=', $date),
                            )
                            ->when(
                                $data['to'] ?? null,
                                fn (Builder $q, $date) => $q->where('movement_date', '<=', $date . ' 23:59:59'),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if (! empty($data['from'])) {
                            $indicators[] = 'From: ' . $data['from'];
                        }
                        if (! empty($data['to'])) {
                            $indicators[] = 'To: ' . $data['to'];
                        }

                        return $indicators;
                    }),

                // ── Standard universal DateRangeFilter for new use cases ──
                DateRangeFilter::make('movement_date_range')
                    ->label('Movement date')
                    ->column('box_movements.movement_date')
                    ->columnLabel('Movement'),

                DateRangeFilter::make('created_range')
                    ->label('Record created in system')
                    ->column('box_movements.created_at')
                    ->columnLabel('Created'),

                Tables\Filters\SelectFilter::make('to_box_id')
                    ->label('Target box')
                    ->options(fn (): array => Box::query()
                        ->orderBy('box_number')
                        ->limit(500)
                        ->pluck('box_number', 'id')
                        ->all())
                    ->searchable()
                    ->multiple()
                    ->query(function (Builder $query, array $data): Builder {
                        $values = $data['values'] ?? [];
                        if (empty($values)) {
                            return $query;
                        }

                        return $query->whereIn('box_movements.to_box_id', $values);
                    }),

                Tables\Filters\SelectFilter::make('from_box_id')
                    ->label('Source box')
                    ->options(fn (): array => Box::query()
                        ->orderBy('box_number')
                        ->limit(500)
                        ->pluck('box_number', 'id')
                        ->all())
                    ->searchable()
                    ->multiple()
                    ->query(function (Builder $query, array $data): Builder {
                        $values = $data['values'] ?? [];
                        if (empty($values)) {
                            return $query;
                        }

                        return $query->whereIn('box_movements.from_box_id', $values);
                    }),

                Tables\Filters\SelectFilter::make('user_id')
                    ->label('Performed by')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->multiple()
                    ->preload(),

                Tables\Filters\SelectFilter::make('document_id')
                    ->label('Document')
                    ->relationship('document', 'identifier')
                    ->searchable()
                    ->multiple(),

                Tables\Filters\TernaryFilter::make('has_reason')
                    ->label('Has reason text?')
                    ->placeholder('Any')
                    ->trueLabel('With reason')
                    ->falseLabel('Without reason')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereNotNull('box_movements.reason')->whereRaw("TRIM(COALESCE(box_movements.reason, '')) <> ''"),
                        false: fn (Builder $q): Builder => $q->where(function (Builder $sub): void {
                            $sub->whereNull('box_movements.reason')
                                ->orWhereRaw("TRIM(COALESCE(box_movements.reason, '')) = ''");
                        }),
                    ),
            ])
            ->paginated([25, 50, 100, 'all']);
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::streamCsv(
            title: 'Box movement history',
            slug: 'box-movement-history',
            columns: [
                'Date' => 'movement_date',
                'Document' => 'document',
                'From box' => 'from_box',
                'To box' => 'to_box',
                'Reason' => 'reason',
                'By' => 'user',
            ],
            query: $this->reportQuery()->orderBy('box_movements.id'),
            rowMapper: fn (BoxMovement $r): array => self::movementRow($r),
        );
    }

    public function exportPdf(): Response
    {
        abort_unless(static::canAccess(), 403);

        $rows = [];
        /** @var BoxMovement $r */
        foreach ($this->reportQuery()->orderByDesc('movement_date')->limit(5000)->get() as $r) {
            $rows[] = self::movementRow($r);
        }

        return ReportRenderer::renderPdf(
            title: 'Box movement history',
            slug: 'box-movement-history',
            headers: ['Date', 'Document', 'From box', 'To box', 'Reason', 'By'],
            rows: $rows,
        );
    }

    public function exportXlsx(): BinaryFileResponse|StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        $query = $this->getFilteredTableQuery() ?? $this->reportQuery();
        $rows = $this->fetchExportRowsWithCap(
            $query
                ->with([
                    'document:id,identifier',
                    'fromBox:id,box_number',
                    'toBox:id,box_number',
                    'user:id,name,email',
                ])
                ->orderByDesc('movement_date'),
        );

        return ReportRenderer::streamXlsx(
            rows: $rows,
            columns: $this->getXlsxColumns(),
            filename: ReportRenderer::filename($this->getReportSlug(), 'xlsx'),
            title: $this->getReportTitle(),
        );
    }

    /**
     * @return array<string, callable(BoxMovement): mixed>
     */
    public function getXlsxColumns(): array
    {
        return [
            'Date' => fn (BoxMovement $r) => $r->movement_date instanceof \DateTimeInterface
                ? $r->movement_date->format('Y-m-d H:i')
                : null,
            'Document' => fn (BoxMovement $r) => $r->document?->getAttribute('identifier'),
            'From box' => fn (BoxMovement $r) => $r->fromBox?->getAttribute('box_number'),
            'To box' => fn (BoxMovement $r) => $r->toBox?->getAttribute('box_number'),
            'Reason' => fn (BoxMovement $r) => $r->reason,
            'By' => function (BoxMovement $r): ?string {
                $name = $r->user?->getAttribute('name');
                $email = $r->user?->getAttribute('email');

                return is_string($name) && $name !== ''
                    ? $name
                    : (is_string($email) && $email !== '' ? $email : null);
            },
        ];
    }

    public function getReportTitle(): string
    {
        return 'Box movement history';
    }

    public function getReportSlug(): string
    {
        return 'box-movement-history';
    }

    public function mount(): void
    {
        $this->applyTemplateFromQuery();
    }

    protected function reportQuery(): Builder
    {
        return BoxMovement::query()
            ->with([
                'document:id,identifier',
                'fromBox:id,box_number',
                'toBox:id,box_number',
                'user:id,name,email',
            ]);
    }

    /**
     * Flatten one BoxMovement row into the column order this report uses.
     * Pulled into its own helper so the CSV and PDF code paths share the
     * exact same projection — no risk of drift between formats.
     *
     * @return array<int, scalar|null>
     */
    protected static function movementRow(BoxMovement $r): array
    {
        $userName = $r->user?->getAttribute('name');
        $userEmail = $r->user?->getAttribute('email');
        $userLabel = is_string($userName) && $userName !== ''
            ? $userName
            : (is_string($userEmail) ? $userEmail : null);

        return [
            $r->movement_date instanceof \DateTimeInterface
                ? $r->movement_date->format('Y-m-d H:i')
                : null,
            $r->document?->getAttribute('identifier'),
            $r->fromBox?->getAttribute('box_number'),
            $r->toBox?->getAttribute('box_number'),
            $r->reason,
            $userLabel,
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
