<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Models\Box;
use App\Models\BoxMovement;
use App\Models\Scopes\ThroughBoxBatchRepositoryScope;
use App\Support\Reports\ReportRenderer;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RFQ §3.1.10 #5 — Box movement history.
 *
 * Chronological list of `box_movements` rows with optional filters by
 * date range and target box. Movements without a target box (legacy
 * data) still appear so operators don't lose audit-trail visibility.
 *
 * Multi-tenancy: BoxMovement has its own dedicated
 * {@see ThroughBoxBatchRepositoryScope} which
 * follows `box_movements.to_box_id → boxes.batch_id → batches.repository_id`,
 * so this report is automatically tenant-correct for non-admin users.
 */
class BoxMovementHistoryReport extends Page implements HasTable
{
    use InteractsWithTable;

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
            ->filters([
                Tables\Filters\Filter::make('date_range')
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

                Tables\Filters\SelectFilter::make('to_box_id')
                    ->label('Target box')
                    ->options(fn (): array => Box::query()
                        ->orderBy('box_number')
                        ->limit(500)
                        ->pluck('box_number', 'id')
                        ->all())
                    ->searchable(),
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
