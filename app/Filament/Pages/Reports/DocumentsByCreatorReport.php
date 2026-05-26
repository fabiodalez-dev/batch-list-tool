<?php

declare(strict_types=1);

namespace App\Filament\Pages\Reports;

use App\Models\Authority;
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
 * RFQ §3.1.10 #2 — Documents by Creator (Notary / Authority).
 *
 * `documents.authorities` is a many-to-many via `document_authority`,
 * so a single Document can appear under TWO creators. The COUNT in
 * this report uses `COUNT(DISTINCT document_authority.document_id)`
 * which is what the RFQ wording asks for — "counts per Authority" —
 * but each Document is counted *once per Authority* it is attached
 * to, not deduplicated globally. The dedup test in the suite asserts
 * a Document with 2 authorities appears in BOTH rows (count 1+1=2).
 *
 * Multi-tenancy: Authorities are global reference data and have NO
 * RepositoryScope; the IN-clause against the visible-Document subquery
 * restricts the pivot rows we count to ones whose Document is visible
 * — which is the correct semantic (viewer in tenant A sees 0 docs for
 * an Authority whose docs all sit in tenant B).
 */
class DocumentsByCreatorReport extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $view = 'filament.pages.reports.table';

    protected static ?string $navigationGroup = 'Operations';

    protected static ?string $title = 'Documents by creator / notary';

    protected static ?string $slug = 'reports/documents-by-creator';

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
                Tables\Columns\TextColumn::make('identifier')
                    ->label('Code')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('surname')
                    ->label('Surname')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('given_names')
                    ->label('Given names')
                    ->limit(40)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('document_count')
                    ->label('# Documents')
                    ->alignEnd()
                    ->sortable()
                    ->numeric(),
            ])
            ->filters([
                Tables\Filters\Filter::make('only_with_documents')
                    ->label('Only authorities with documents')
                    ->toggle()
                    ->default()
                    ->query(function (Builder $query, array $data): Builder {
                        if (! ($data['isActive'] ?? false)) {
                            return $query;
                        }

                        return $query->having('document_count', '>', 0);
                    }),
            ])
            ->paginated([25, 50, 100, 'all']);
    }

    public function exportCsv(): StreamedResponse
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::streamCsvFromRows(
            slug: 'documents-by-creator',
            columns: [
                'Code' => 'identifier',
                'Surname' => 'surname',
                'Given names' => 'given_names',
                '# Documents' => 'document_count',
            ],
            rows: $this->collectRows(),
        );
    }

    public function exportPdf(): Response
    {
        abort_unless(static::canAccess(), 403);

        return ReportRenderer::renderPdf(
            title: 'Documents by creator / notary',
            slug: 'documents-by-creator',
            headers: ['Code', 'Surname', 'Given names', '# Documents'],
            rows: $this->collectRows(),
        );
    }

    /**
     * SELECT authorities.*,
     *        COUNT(DISTINCT document_authority.document_id) AS document_count
     *   FROM authorities
     *   LEFT JOIN document_authority ON document_authority.authority_id = authorities.id
     *  WHERE document_authority.document_id IS NULL
     *        OR document_authority.document_id IN (visible Document ids)
     *  GROUP BY authorities.id
     */
    protected function reportQuery(): Builder
    {
        $visibleDocIds = Document::query()->select('documents.id');

        return Authority::query()
            ->leftJoin('document_authority', 'document_authority.authority_id', '=', 'authorities.id')
            ->whereNull('authorities.deleted_at')
            ->where(function (Builder $q) use ($visibleDocIds): void {
                $q->whereNull('document_authority.document_id')
                    ->orWhereIn('document_authority.document_id', $visibleDocIds);
            })
            ->selectRaw(
                'authorities.id as id,'
                . ' authorities.identifier as identifier,'
                . ' authorities.surname as surname,'
                . ' authorities.given_names as given_names,'
                . ' COUNT(DISTINCT document_authority.document_id) as document_count'
            )
            ->groupBy('authorities.id', 'authorities.identifier', 'authorities.surname', 'authorities.given_names');
    }

    /**
     * @return array<int, array<int, scalar|null>>
     */
    protected function collectRows(): array
    {
        $rows = [];
        $records = $this->reportQuery()
            ->orderByDesc('document_count')
            ->orderBy('surname')
            ->get();

        foreach ($records as $r) {
            $attrs = $r->getAttributes();
            $rows[] = [
                isset($attrs['identifier']) ? (string) $attrs['identifier'] : null,
                isset($attrs['surname']) ? (string) $attrs['surname'] : null,
                isset($attrs['given_names']) ? (string) $attrs['given_names'] : null,
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
