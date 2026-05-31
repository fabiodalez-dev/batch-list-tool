<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Pages\Reports\Concerns\HasReportTemplates;
use App\Filament\Resources\DocumentResource\RelationManagers\FlagsRelationManager;
use App\Models\DocumentFlag;
use App\Models\ReportTemplate;
use App\Models\Repository;
use App\Support\Reports\ReportRenderer;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RFQ APP2-xviii — "Reports can group by flag type".
 *
 * Aggregates `document_flags` rows by `type`, producing one row per
 * type with:
 *   - count_open      — flags in `open` / `acknowledged`
 *   - count_resolved  — flags in `resolved` / `dismissed`
 *   - last_flagged_at — most recent `flagged_at` across that bucket
 *
 * Multi-tenancy: DocumentFlag carries a denormalised `repository_id`
 * mirrored from the parent Document, so the `BelongsToRepository`
 * global scope on the model filters per-tenant automatically. Admin /
 * super_admin see across tenants.
 *
 * Filters mirror the standalone DocumentFlagResource so the operator
 * never has to remember a different vocabulary moving between the
 * alerts dashboard and this report.
 */
class FlagsByTypeReport extends Page implements HasTable
{
    use ExplainsPage;
    use HasReportTemplates;
    use InteractsWithTable;

    /** @see ReportTemplate::SOURCES */
    public const REPORT_SOURCE = ReportTemplate::SOURCE_FLAGS_BY_TYPE;

    protected string $view = 'filament.pages.reports.table';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'Flags by type';

    protected static ?string $navigationLabel = 'Flags by Type';

    protected static ?string $slug = 'reports/flags-by-type';

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
            ->defaultSort('count_open', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->label('Flag type')
                    ->badge()
                    ->sortable()
                    ->searchable()
                    ->formatStateUsing(fn (string $state): string => FlagsRelationManager::typeLabel($state)),

                Tables\Columns\TextColumn::make('severity')
                    ->label('Severity')
                    ->badge()
                    ->sortable()
                    ->color(fn (?string $state): string => match ($state) {
                        'critical' => 'danger',
                        'warning' => 'warning',
                        'info' => 'gray',
                        default => 'gray',
                    })
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('count_open')
                    ->label('# Open')
                    ->alignEnd()
                    ->sortable()
                    ->numeric(),

                Tables\Columns\TextColumn::make('count_resolved')
                    ->label('# Resolved')
                    ->alignEnd()
                    ->sortable()
                    ->numeric(),

                Tables\Columns\TextColumn::make('last_flagged_at')
                    ->label('Last flagged')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Type')
                    ->options(FlagsRelationManager::typeOptions())
                    ->query(fn (Builder $q, array $data): Builder => empty($data['value'])
                        ? $q
                        : $q->where('document_flags.type', $data['value'])),

                SelectFilter::make('severity')
                    ->label('Severity')
                    ->options(FlagsRelationManager::severityOptions())
                    ->query(fn (Builder $q, array $data): Builder => empty($data['value'])
                        ? $q
                        : $q->where('document_flags.severity', $data['value'])),

                SelectFilter::make('status')
                    ->label('Status')
                    ->options(FlagsRelationManager::statusOptions())
                    ->query(fn (Builder $q, array $data): Builder => empty($data['value'])
                        ? $q
                        : $q->where('document_flags.status', $data['value'])),

                SelectFilter::make('repository_id')
                    ->label('Repository')
                    ->options(fn (): array => Repository::query()
                        ->orderBy('code')
                        ->limit(500)
                        ->pluck('code', 'id')
                        ->all())
                    ->query(fn (Builder $q, array $data): Builder => empty($data['value'])
                        ? $q
                        : $q->where('document_flags.repository_id', $data['value'])),

                Filter::make('date_range')
                    ->label('Flagged between')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From date'),
                        Forms\Components\DatePicker::make('to')->label('To date'),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        return $q
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $b, $date): Builder => $b->where('document_flags.flagged_at', '>=', $date),
                            )
                            ->when(
                                $data['to'] ?? null,
                                fn (Builder $b, $date): Builder => $b->where('document_flags.flagged_at', '<=', $date . ' 23:59:59'),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $out = [];
                        if (! empty($data['from'])) {
                            $out[] = 'From: ' . $data['from'];
                        }
                        if (! empty($data['to'])) {
                            $out[] = 'To: ' . $data['to'];
                        }

                        return $out;
                    }),
            ])
            ->paginated([25, 50, 100, 'all']);
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::streamCsvFromRows(
            slug: 'flags-by-type',
            columns: [
                'Flag type' => 'type',
                'Severity' => 'severity',
                '# Open' => 'count_open',
                '# Resolved' => 'count_resolved',
                'Last flagged' => 'last_flagged_at',
            ],
            rows: $this->collectRows(),
        );
    }

    public function exportPdf(): Response
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::renderPdf(
            title: 'Flags by type',
            slug: 'flags-by-type',
            headers: ['Flag type', 'Severity', '# Open', '# Resolved', 'Last flagged'],
            rows: $this->collectRows(),
        );
    }

    public function mount(): void
    {
        $this->applyTemplateFromQuery();
    }

    /**
     * SELECT type, severity,
     *        SUM(CASE WHEN status IN ('open','acknowledged') THEN 1 ELSE 0 END) AS count_open,
     *        SUM(CASE WHEN status IN ('resolved','dismissed') THEN 1 ELSE 0 END) AS count_resolved,
     *        MAX(flagged_at) AS last_flagged_at
     *   FROM document_flags
     *  GROUP BY type, severity
     *
     * Counts use SUM(CASE) instead of COUNT(*) per status so both
     * buckets surface on the same row (one DB round trip vs. two
     * UNIONed queries).
     */
    protected function reportQuery(): Builder
    {
        return DocumentFlag::query()
            ->selectRaw(
                'MIN(document_flags.id) as id,'
                . ' document_flags.type as type,'
                . ' document_flags.severity as severity,'
                . " SUM(CASE WHEN document_flags.status IN ('open', 'acknowledged') THEN 1 ELSE 0 END) as count_open,"
                . " SUM(CASE WHEN document_flags.status IN ('resolved', 'dismissed') THEN 1 ELSE 0 END) as count_resolved,"
                . ' MAX(document_flags.flagged_at) as last_flagged_at'
            )
            ->groupBy('document_flags.type', 'document_flags.severity');
    }

    /**
     * @return array<int, array<int, scalar|null>>
     */
    protected function collectRows(): array
    {
        $rows = [];
        $records = $this->reportQuery()
            ->orderByDesc('count_open')
            ->orderBy('document_flags.type')
            ->get();

        foreach ($records as $r) {
            $attrs = $r->getAttributes();
            $type = isset($attrs['type']) ? (string) $attrs['type'] : null;

            $lastFlagged = $attrs['last_flagged_at'] ?? null;
            if ($lastFlagged instanceof \DateTimeInterface) {
                $lastFlaggedStr = $lastFlagged->format('Y-m-d H:i');
            } elseif (is_string($lastFlagged) && $lastFlagged !== '') {
                $lastFlaggedStr = $lastFlagged;
            } else {
                $lastFlaggedStr = null;
            }

            $rows[] = [
                $type !== null ? FlagsRelationManager::typeLabel($type) : null,
                isset($attrs['severity']) ? (string) $attrs['severity'] : null,
                (int) ($attrs['count_open'] ?? 0),
                (int) ($attrs['count_resolved'] ?? 0),
                $lastFlaggedStr,
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
