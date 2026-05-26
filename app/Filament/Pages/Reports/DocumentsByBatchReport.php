<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Models\Concerns\BelongsToRepository;
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
 * RFQ §3.1.10 #1 — Documents by Batch.
 *
 * Aggregates `documents` by `batch_id`, joined to `batches` so the row
 * shows the human-readable `batch_number` instead of the surrogate id.
 * Documents that are NOT yet associated with a batch are surfaced as a
 * single "(unassigned)" row so operators can see un-batched backlog at
 * a glance.
 *
 * Multi-tenancy: the COUNT subquery runs against {@see Document},
 * which carries the {@see BelongsToRepository}
 * global scope — admin / super_admin bypass, editor / viewer are
 * restricted to their tenant automatically.
 */
class DocumentsByBatchReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $view = 'filament.pages.reports.table';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $title = 'Documents by batch';

    protected static ?string $slug = 'reports/documents-by-batch';

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
                Tables\Columns\TextColumn::make('batch_number')
                    ->label('Batch #')
                    ->sortable()
                    ->searchable()
                    ->formatStateUsing(fn ($state): string => $state === null ? '(unassigned)' : (string) $state),

                Tables\Columns\TextColumn::make('batch_description')
                    ->label('Description')
                    ->limit(60)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('batch_type')
                    ->label('Type')
                    ->badge()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('document_count')
                    ->label('# Documents')
                    ->alignEnd()
                    ->sortable()
                    ->numeric(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('batch_type')
                    ->label('Batch type')
                    ->options([
                        'MAIN_COLLECTION' => 'Main collection',
                        'NOTARY_ACCESSION' => 'Notary accession',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (empty($data['value'])) {
                            return $query;
                        }

                        return $query->where('batches.type', $data['value']);
                    }),
            ])
            ->paginated([25, 50, 100, 'all']);
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::streamCsvFromRows(
            slug: 'documents-by-batch',
            columns: [
                'Batch #' => 'batch_number',
                'Description' => 'batch_description',
                'Type' => 'batch_type',
                '# Documents' => 'document_count',
            ],
            rows: $this->collectRows(),
        );
    }

    public function exportPdf(): Response
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::renderPdf(
            title: 'Documents by batch',
            slug: 'documents-by-batch',
            headers: ['Batch #', 'Description', 'Type', '# Documents'],
            rows: $this->collectRows(),
        );
    }

    /**
     * SELECT documents.batch_id, batches.*, COUNT(documents.id)
     * FROM documents LEFT JOIN batches ON batches.id = documents.batch_id
     * GROUP BY documents.batch_id, batches.batch_number, batches.description, batches.type
     */
    protected function reportQuery(): Builder
    {
        return Document::query()
            ->leftJoin('batches', 'batches.id', '=', 'documents.batch_id')
            ->selectRaw(
                'documents.batch_id as id,'
                . ' batches.batch_number as batch_number,'
                . ' batches.description as batch_description,'
                . ' batches.type as batch_type,'
                . ' COUNT(documents.id) as document_count'
            )
            ->groupBy('documents.batch_id', 'batches.batch_number', 'batches.description', 'batches.type');
    }

    /**
     * Materialise the aggregate query into flat rows for export.
     * Reads via getAttributes() because the selectRaw column aliases
     * are not declared on the Document model (PHPStan-friendly).
     *
     * @return array<int, array<int, scalar|null>>
     */
    protected function collectRows(): array
    {
        $rows = [];
        $records = $this->reportQuery()->orderByDesc('document_count')->get();

        foreach ($records as $r) {
            $attrs = $r->getAttributes();
            $batchNumber = $attrs['batch_number'] ?? null;

            $rows[] = [
                $batchNumber === null ? '(unassigned)' : (string) $batchNumber,
                isset($attrs['batch_description']) ? (string) $attrs['batch_description'] : null,
                isset($attrs['batch_type']) ? (string) $attrs['batch_type'] : null,
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
