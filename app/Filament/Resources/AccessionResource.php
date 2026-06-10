<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AccessionResource\Pages;
use App\Filament\Support\CreatorColumn;
use App\Filament\Support\SearchableSelects;
use App\Models\Accession;
use App\Models\Repository;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccessionResource extends Resource
{
    protected static ?string $model = Accession::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-plus';

    protected static string|\UnitEnum|null $navigationGroup = 'Archive';

    protected static ?int $navigationSort = 12;

    protected static ?string $recordTitleAttribute = 'code';

    // Feedback1 — present Accessions as "Notary Accession" across the UI
    // (nav, breadcrumbs, page titles). The DB table/model name is unchanged.
    protected static ?string $navigationLabel = 'Notary Accessions';

    protected static ?string $modelLabel = 'Notary Accession';

    protected static ?string $pluralModelLabel = 'Notary Accessions';

    public static function form(Schema $schema): Schema
    {
        // Layout rule (user mandate, do NOT regress): root columns(1) so every
        // top-level Section is a full-width band; atomic-field Sections use
        // ['default' => 1, 'md' => 2]; non-atomic children take columnSpanFull.
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->schema([
                Section::make('Identification')
                    ->columns($twoCols)
                    ->schema([
                        // A3 — "Code" field renamed to "Title" in the UI.
                        // The DB column and attribute name remain `code`.
                        Forms\Components\TextInput::make('code')
                            ->label('Title')
                            ->required()
                            ->maxLength(64),
                        // A3 — "Accession date" → "Accession Date" (capitalised).
                        Forms\Components\DatePicker::make('accession_date')
                            ->label('Accession Date'),
                        // A3 — "Notary Accession Number" → "Accession Number".
                        // Feedback1 C1.3 — YYYY-NNN format; optional/nullable.
                        Forms\Components\TextInput::make('accession_number')
                            ->label('Accession Number')
                            ->maxLength(32)
                            ->placeholder('2025-124')
                            ->helperText('Format: YYYY-NNN (e.g. 2025-124)')
                            ->rules(['nullable', 'regex:/^\d{4}-\d{1,}$/'])
                            ->validationMessages([
                                'regex' => 'Use the format YYYY-NNN, e.g. 2025-124.',
                            ]),
                    ]),

                Section::make('Provenance & scope')
                    ->columns($twoCols)
                    ->schema([
                        // F04 (feedback review) — Authority removed from the
                        // Accession form per client request: "The Authority should
                        // be included with the Document." The authority_id DB
                        // column is kept for backward-compatibility but is no
                        // longer exposed in the create/edit form.
                        // Wave B (B4) — multi-select for the N:N Batch relation.
                        // One accession may span several batches; Batch 50 can collect
                        // wills from many accessions. No guard forbids sharing a batch
                        // between accessions (the "different accessions on same batch"
                        // restriction is explicitly REMOVED per spec B4).
                        SearchableSelects::batchesMulti('batches')
                            ->label('Batches')
                            ->columnSpanFull(),
                        // Repository dropdown: scoped to the user's assigned tenants.
                        // Same tenant-scoping closure as before; only the search/label
                        // wiring is new.
                        SearchableSelects::repository(
                            'repository_id',
                            fn ($query) => $query->whereIn(
                                'id',
                                auth()->user()?->hasAnyRole(['super_admin', 'admin'])
                                    ? Repository::query()->pluck('id')->all()
                                    : (auth()->user()?->repositories()->pluck('repositories.id')->all() ?? [])
                            ),
                        )
                            ->label('Repository')
                            ->required()
                            ->default(fn () => auth()->user()?->default_repository_id)
                            ->disabled(fn () => ! auth()->user()?->hasAnyRole(['super_admin', 'admin']))
                            ->dehydrated()
                            ->columnSpanFull(),
                    ]),

                Section::make('Notes')
                    ->columns(1)
                    ->collapsed()
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        // Layout rule (user mandate): root columns(1) → full-width Sections;
        // atomic entries on ['default' => 1, 'md' => 2]; non-atomic content
        // (prose Notes, audit info span) → columnSpanFull. Every relationship
        // gets a ->url() to its own Resource view.
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->components([
                Section::make('Identification')
                    ->columns($twoCols)
                    ->schema([
                        // A3 — "Code" → "Title" in the infolist view.
                        TextEntry::make('code')
                            ->label('Title')
                            ->badge()
                            ->color('primary')
                            ->copyable()
                            ->placeholder('—'),
                        // A3 — "Notary Accession Number" → "Accession Number".
                        TextEntry::make('accession_number')
                            ->label('Accession Number')
                            ->copyable()
                            ->placeholder('—'),
                        // A3 — "Accession date" → "Accession Date" (capitalised).
                        TextEntry::make('accession_date')
                            ->label('Accession Date')
                            ->date()
                            ->placeholder('—'),
                    ]),

                Section::make('Provenance & scope')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('authority.surname')
                            ->label('Authority')
                            ->badge()
                            ->color('gray')
                            ->url(fn (?Accession $record): ?string => $record?->authority_id
                                ? route('filament.admin.resources.authorities.view', ['record' => $record->authority_id])
                                : null)
                            ->openUrlInNewTab(false)
                            ->placeholder('—'),
                        // Wave B (B4) — accession may span many batches (N:N).
                        // Render as comma-separated badge list from the pivot.
                        TextEntry::make('batches_list')
                            ->label('Batches')
                            ->badge()
                            ->color('gray')
                            ->state(fn (?Accession $record): string => $record?->batches->isEmpty() ?? true
                                ? '—'
                                : $record->batches
                                    ->sortBy('batch_number')
                                    ->map(fn ($b) => (string) $b->batch_number)
                                    ->join(', '))
                            ->placeholder('—'),
                        TextEntry::make('repository.code')
                            ->label('Repository')
                            ->badge()
                            ->color('info')
                            ->url(fn (?Accession $record): ?string => $record?->repository_id
                                ? route('filament.admin.resources.repositories.view', ['record' => $record->repository_id])
                                : null)
                            ->openUrlInNewTab(false)
                            ->placeholder('—'),
                        TextEntry::make('documents_count')
                            ->label('Documents')
                            ->state(fn (?Accession $record): int => $record?->documents()->count() ?? 0)
                            ->badge()
                            ->color('gray'),
                    ]),

                Section::make('Notes')
                    ->columns(1)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('notes')
                            ->hiddenLabel()
                            ->prose()
                            ->placeholder('No notes.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Audit info')
                    ->columns($twoCols)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('created_at')->dateTime()->label('Created'),
                        TextEntry::make('updated_at')->dateTime()->label('Updated'),
                        TextEntry::make('deleted_at')->dateTime()->label('Trashed')->placeholder('—')->columnSpanFull(),
                    ])
                    ->visible(fn (): bool => (bool) auth()->user()?->hasRole('super_admin')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Feedback1 Wave B (B1) / A7 — persist & defer filters so they
            // survive navigation/refresh. deferFilters() renders an explicit
            // "Apply" button in the filter panel, satisfying A7. The filter
            // panel remains visible regardless of the result count (Filament 5
            // default: the panel is not hidden when results are empty).
            ->deferFilters()
            ->persistFiltersInSession()
            // Feedback1 Wave A (A6) — drag-and-drop column reordering, mirroring
            // DocumentResource and BoxResource (spec: all main resource lists).
            ->reorderableColumns()
            // A8 / no-whole-row-link — do NOT set ->recordUrl() here so that
            // the entire row is NOT a hyperlink. Only the "Title" (code) cell
            // carries a ->url() pointing to the view page (see column below).
            ->columns([
                // A3 — "Code" → "Title" in the table header.
                // A8 — Only this cell is the hyperlink to the view page.
                // A5 — sortable.
                Tables\Columns\TextColumn::make('code')
                    ->label('Title')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Accession $record): string => AccessionResource::getUrl('view', ['record' => $record])),
                // A3 — "Notary Accession Number" → "Accession Number".
                // Feedback1 C1.3 — searchable + sortable.
                Tables\Columns\TextColumn::make('accession_number')
                    ->label('Accession Number')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                // A3 — label is already "Accession Date" (capitalised); A5 — sortable.
                Tables\Columns\TextColumn::make('accession_date')
                    ->label('Accession Date')
                    ->date()
                    ->sortable(),
                // A5 — sortable on the related column.
                Tables\Columns\TextColumn::make('authority.surname')
                    ->label('Authority')
                    ->sortable(),
                // Wave B (B4) — accession may be linked to multiple batches (N:N).
                // The list is rendered as comma-separated batch numbers. Not sortable
                // (no single column to order by across the N:N) but togglable.
                Tables\Columns\TextColumn::make('batches_list')
                    ->label('Batch Numbers')
                    ->state(fn (Accession $record): string => $record->batches->isEmpty()
                        ? '—'
                        : $record->batches
                            ->sortBy('batch_number')
                            ->map(fn ($b) => (string) $b->batch_number)
                            ->join(', '))
                    ->toggleable(),
                // A5 — sortable on repository name.
                Tables\Columns\TextColumn::make('repository.name')
                    ->label('Repository')
                    ->sortable(),
                // A9 — Inputter column (record creator via audit trail).
                // Toggleable, default visible, not sortable (cross-table sort).
                CreatorColumn::make(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // A7 / Wave B (B1) — dropdown-driven filters. The panel stays
                // visible when the result set is empty (Filament 5 default).
                // Server-side searchable relationship selects (no preload):
                // production carries 800+ authorities / 600+ batches.
                SelectFilter::make('authority')
                    ->label('Authority')
                    ->relationship('authority', 'surname')
                    ->searchable()
                    ->multiple(),
                // Wave B (B4) — filter by any of the batches linked via the pivot.
                SelectFilter::make('batches')
                    ->label('Batch')
                    ->relationship('batches', 'batch_number')
                    ->searchable()
                    ->multiple(),
                SelectFilter::make('repository')
                    ->label('Repository')
                    ->relationship('repository', 'code')
                    ->searchable()
                    ->preload(),
                // Feedback1 C1.3 — quickly isolate accessions that do / do not
                // carry an Accession Number.
                TernaryFilter::make('has_accession_number')
                    ->label('Has Accession Number')
                    ->placeholder('All accessions')
                    ->trueLabel('Has a number')
                    ->falseLabel('No number')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereNotNull('accession_number')
                            ->where('accession_number', '!=', ''),
                        false: fn (Builder $q): Builder => $q->where(
                            fn (Builder $q) => $q->whereNull('accession_number')
                                ->orWhere('accession_number', '=', '')
                        ),
                        blank: fn (Builder $q): Builder => $q,
                    ),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Eager-load the audit trail (for CreatorColumn) and related models to
     * avoid N+1 on the list page.
     *
     * Active-repository scoping is handled automatically by the RepositoryScope
     * global scope attached to Accession via BelongsToRepository.  The scope
     * reads from App\Support\ActiveRepository (session-backed topbar switcher)
     * and narrows the query to the selected repository when one is active.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'authority',
                // Wave B — accession now links to many batches; eager-load the
                // pivot so the batches_list computed column is O(1) per page.
                'batches',
                'repository',
                // CreatorColumn resolves the first `created` audit entry.
                // Eager-loading here keeps it O(1) per page instead of one
                // query per row.
                'audits' => fn ($q) => $q->where('event', 'created')->oldest('id')->with('user'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAccessions::route('/'),
            'create' => Pages\CreateAccession::route('/create'),
            'view' => Pages\ViewAccession::route('/{record}'),
            'edit' => Pages\EditAccession::route('/{record}/edit'),
        ];
    }
}
