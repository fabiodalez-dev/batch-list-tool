<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AccessionResource\Pages;
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
                        Forms\Components\TextInput::make('code')
                            ->required()
                            ->maxLength(64),
                        Forms\Components\DatePicker::make('accession_date'),
                        // Feedback1 C1.3 — Notary Accession Number, e.g.
                        // "2025-124" (YYYY-NNN). Optional/nullable so existing
                        // rows without a number keep loading and saving. The
                        // mask guides input; the regex rule rejects malformed
                        // values server-side with a friendly message.
                        Forms\Components\TextInput::make('accession_number')
                            ->label('Notary Accession Number')
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
                        // Authority dropdown: 808 rows in production → server-side
                        // search ("Abela" → top 50 matches by surname/identifier).
                        SearchableSelects::authority('authority_id', 'authority'),
                        // Batch dropdown: showing `Batch <N> — <type>` so operators
                        // can distinguish RAS_BATCH/NOTARY_ACCESSION at a glance.
                        SearchableSelects::batch('batch_id', 'batch'),
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
                        TextEntry::make('code')
                            ->label('Code')
                            ->badge()
                            ->color('primary')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('accession_number')
                            ->label('Notary Accession Number')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('accession_date')
                            ->label('Accession date')
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
                        TextEntry::make('batch.batch_number')
                            ->label('Batch')
                            ->badge()
                            ->color('gray')
                            ->url(fn (?Accession $record): ?string => $record?->batch_id
                                ? route('filament.admin.resources.batches.view', ['record' => $record->batch_id])
                                : null)
                            ->openUrlInNewTab(false)
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
            // Feedback1 Wave B (B1) — persist & defer filters so they survive
            // navigation/refresh.
            ->deferFilters()
            ->persistFiltersInSession()
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable(),
                // Feedback1 C1.3 — Notary Accession Number, searchable.
                Tables\Columns\TextColumn::make('accession_number')
                    ->label('Notary Accession Number')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('accession_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('authority.surname')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('batch.batch_number')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('repository.name')
                    ->numeric()
                    ->sortable(),
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
                // Feedback1 Wave B (B1) — dropdown-driven filters (mechanism #1)
                // next to the free-text search on `code` (mechanism #2).
                // Server-side searchable relationship selects (no preload):
                // production carries 800+ authorities / 600+ batches.
                SelectFilter::make('authority')
                    ->label('Authority')
                    ->relationship('authority', 'surname')
                    ->searchable()
                    ->multiple(),
                SelectFilter::make('batch')
                    ->label('Batch')
                    ->relationship('batch', 'batch_number')
                    ->searchable()
                    ->multiple(),
                SelectFilter::make('repository')
                    ->label('Repository')
                    ->relationship('repository', 'code')
                    ->searchable()
                    ->preload(),
                // Feedback1 C1.3 — quickly isolate accessions that do / do not
                // carry a Notary Accession Number.
                TernaryFilter::make('has_accession_number')
                    ->label('Has Notary Accession Number')
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
