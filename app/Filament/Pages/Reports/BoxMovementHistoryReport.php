<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Filament\Concerns\ExplainsPage;
use App\Filament\Pages\Reports\Concerns\CapsExportRows;
use App\Filament\Pages\Reports\Concerns\ExportsWhatIsOnScreen;
use App\Filament\Pages\Reports\Concerns\HasReportTemplates;
use App\Filament\Pages\Reports\Filters\DateRangeFilter;
use App\Filament\Resources\DocumentResource;
use App\Models\Authority;
use App\Models\Box;
use App\Models\BoxMovement;
use App\Models\Document;
use App\Models\ReportTemplate;
use App\Models\Scopes\RepositoryScope;
use App\Support\DisplayDate;
use App\Support\Reports\ReportRenderer;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RFQ §3.1.10 #5 — Box movement history, renamed "Document movement history"
 * by the client on 2026-11-10: each row is a document moved between boxes,
 * shown with its catalogue identifier, authority codes, volume, creator and
 * dates, and the boxes read as the archive names them ("Batch 4 / Box 54",
 * "NRA 10").
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
    use ExportsWhatIsOnScreen;
    use HasReportTemplates;
    use InteractsWithTable;

    /** @see ReportTemplate::SOURCES */
    public const REPORT_SOURCE = ReportTemplate::SOURCE_BOX_MOVEMENTS;

    /** What a row reads, loaded once per page or export. */
    private const array RELATIONS = [
        'document:id,catalogue_identifier,volume_number,dates',
        'document.authorities',
        'fromBox:id,box_type,box_number,batch_id',
        'fromBox.batch:id,batch_number',
        'toBox:id,box_type,box_number,batch_id',
        'toBox.batch:id,batch_number',
        'user:id,name,email',
    ];

    protected string $view = 'filament.pages.reports.table';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $title = 'Document movement history';

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
                    ->label('Movement date')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('document.catalogue_identifier')
                    ->label('Catalogue Identifier')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('document.authorities.alternative_identifier')
                    ->label('Actual Authority Reference Code')
                    ->listWithLineBreaks()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('document.volume_number')
                    ->label('Volume')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('creator')
                    ->label('Creator')
                    ->state(fn (BoxMovement $record): array => self::creators($record))
                    ->listWithLineBreaks()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('document.dates')
                    ->label('Document dates')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('from_box')
                    ->label('From box')
                    ->state(fn (BoxMovement $record): ?string => self::boxLabel($record->fromBox))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('to_box')
                    ->label('To box')
                    ->state(fn (BoxMovement $record): ?string => self::boxLabel($record->toBox))
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
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['from'] ?? null,
                            fn (Builder $q, $date) => $q->where('movement_date', '>=', $date),
                        )
                        ->when(
                            $data['to'] ?? null,
                            fn (Builder $q, $date) => $q->where('movement_date', '<=', $date . ' 23:59:59'),
                        ))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if (! empty($data['from'])) {
                            $indicators[] = 'From: ' . DisplayDate::date($data['from']);
                        }
                        if (! empty($data['to'])) {
                            $indicators[] = 'To: ' . DisplayDate::date($data['to']);
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
            ->paginated();
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        $labels = array_keys($this->getXlsxColumns());

        return ReportRenderer::streamCsv(
            title: $this->getReportTitle(),
            slug: $this->getReportSlug(),
            columns: array_combine($labels, $labels),
            query: $this->withRelations($this->exportQuery())->orderBy('box_movements.id'),
            rowMapper: fn (BoxMovement $r): array => self::movementRow($r),
        );
    }

    public function exportPdf(): Response
    {
        abort_unless(static::canAccess(), 403);

        $rows = [];
        /** @var BoxMovement $r */
        foreach ($this->withRelations($this->exportQuery())->orderByDesc('movement_date')->limit(5000)->get() as $r) {
            $rows[] = self::movementRow($r);
        }

        return ReportRenderer::renderPdf(
            title: $this->getReportTitle(),
            slug: $this->getReportSlug(),
            headers: array_keys($this->getXlsxColumns()),
            rows: $rows,
        );
    }

    public function exportXlsx(): BinaryFileResponse|StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        $rows = $this->fetchExportRowsWithCap(
            $this->withRelations($this->exportQuery())->orderByDesc('movement_date'),
        );

        return ReportRenderer::streamXlsx(
            rows: $rows,
            columns: $this->getXlsxColumns(),
            filename: ReportRenderer::filename($this->getReportSlug(), 'xlsx'),
            title: $this->getReportTitle(),
        );
    }

    /**
     * The one projection of a movement, shared by the three exports.
     *
     * @return array<string, callable(BoxMovement): mixed>
     */
    public function getXlsxColumns(): array
    {
        return [
            'Movement date' => fn (BoxMovement $r) => $r->movement_date instanceof \DateTimeInterface
                ? $r->movement_date->format(DisplayDate::DATE_TIME)
                : null,
            'Catalogue Identifier' => fn (BoxMovement $r) => $r->document?->getAttribute('catalogue_identifier'),
            'Actual Authority Reference Code' => fn (BoxMovement $r): ?string => self::joined(
                self::authorities($r)->pluck('alternative_identifier')->all(),
            ),
            'Volume' => fn (BoxMovement $r) => $r->document?->getAttribute('volume_number'),
            'Creator' => fn (BoxMovement $r): ?string => self::joined(self::creators($r)),
            'Document dates' => fn (BoxMovement $r) => $r->document?->getAttribute('dates'),
            'From box' => fn (BoxMovement $r): ?string => self::boxLabel($r->fromBox),
            'To box' => fn (BoxMovement $r): ?string => self::boxLabel($r->toBox),
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
        return 'Document movement history';
    }

    public function getReportSlug(): string
    {
        return 'box-movement-history';
    }

    public function mount(): void
    {
        $this->applyTemplateFromQuery();
    }

    /**
     * @return list<string>
     */
    protected static function creators(BoxMovement $r): array
    {
        return self::authorities($r)
            ->map(fn (Authority $a): string => DocumentResource::creatorName($a))
            ->filter(fn (string $name): bool => $name !== '')
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Authority>
     */
    protected static function authorities(BoxMovement $r): Collection
    {
        $document = $r->document;

        if (! $document instanceof Document) {
            return new Collection;
        }

        /** @var Collection<int, Authority> $authorities */
        $authorities = $document->authorities;

        return $authorities;
    }

    protected static function boxLabel(mixed $box): ?string
    {
        return $box instanceof Box ? $box->shortLabel() : null;
    }

    /**
     * @param array<int, mixed> $values
     */
    protected static function joined(array $values): ?string
    {
        $values = array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), $values), static fn (string $v): bool => $v !== ''));

        return $values === [] ? null : implode('; ', $values);
    }

    protected function withRelations(Builder $query): Builder
    {
        return $query->with(self::RELATIONS);
    }

    protected function reportQuery(): Builder
    {
        return BoxMovement::query()->with(self::RELATIONS);
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
        return array_values(array_map(
            static fn (callable $column): mixed => $column($r),
            (new self)->getXlsxColumns(),
        ));
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
