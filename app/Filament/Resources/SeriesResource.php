<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AppliesFieldPermissions;
use App\Filament\Resources\SeriesResource\Pages;
use App\Filament\Support\CreatorColumn;
use App\Models\Repository;
use App\Models\Series;
use App\Support\CustomFields\CustomFieldSchema;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SeriesResource extends Resource
{
    use AppliesFieldPermissions;

    /** RFQ §3.1.8 — see config/field_permissions.php */
    private const string FIELD_PERMISSIONS_KEY = 'series';

    protected static ?string $model = Series::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static string|\UnitEnum|null $navigationGroup = 'Records';

    protected static ?int $navigationSort = 30;

    // Client 2026-08-31: the entity is presented as "Subseries" throughout the
    // UI (navigation, page title, buttons, breadcrumbs). The model, table,
    // relationships and FK (series_id) are deliberately left as `series` — this
    // is a display rename only.
    protected static ?string $navigationLabel = 'Subseries';

    protected static ?string $modelLabel = 'subseries';

    protected static ?string $pluralModelLabel = 'Subseries';

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        $g = fn (Schemas\Components\Component $c): Schemas\Components\Component => self::gateField($c, self::FIELD_PERMISSIONS_KEY);

        // Layout rule (user mandate): root columns(1) → full-width Sections;
        // atomic-field Sections use ['default' => 1, 'md' => 2]; non-atomic
        // children (Textarea) → columnSpanFull.
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->schema([
                Section::make('Identification')
                    ->columns($twoCols)
                    ->schema([
                        // Feedback1 — Series code must be unique.
                        // A3/D9 — user-facing label is "Identifier"; DB column stays 'code'.
                        $g(Forms\Components\TextInput::make('code')
                            ->label('Identifier')
                            ->required()
                            ->maxLength(16)
                            ->unique(ignoreRecord: true)
                            ->validationMessages([
                                'unique' => 'This series identifier is already in use.',
                            ])),
                        $g(Forms\Components\TextInput::make('title')
                            ->required()
                            ->maxLength(255)),
                        // Feedback1 C1.4 — multi-level hierarchy. A series may
                        // sit under a parent (sub-series, sub-sub-series, …).
                        // Options EXCLUDE the record itself and all of its
                        // descendants so a cycle cannot be formed from the UI;
                        // the closure rule below is the server-side backstop.
                        $g(Forms\Components\Select::make('parent_id')
                            ->label('Parent subseries')
                            ->helperText('Leave empty for a top-level series.')
                            ->native(false)
                            ->searchable()
                            ->preload()
                            ->options(function (?Series $record): array {
                                $query = Series::query()->orderBy('code');
                                if ($record instanceof Series && $record->exists) {
                                    $query->whereNotIn('id', $record->disallowedParentIds());
                                }

                                return $query->get()
                                    ->mapWithKeys(fn (Series $s): array => [
                                        $s->getKey() => $s->qualifiedTitle() . ' — ' . $s->title,
                                    ])
                                    ->all();
                            })
                            ->rule(static fn (?Series $record): \Closure => static function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                                if ($value === null || $value === '' || ! $record instanceof Series || ! $record->exists) {
                                    return;
                                }
                                // Reject self or any descendant as parent.
                                if (in_array((int) $value, $record->disallowedParentIds(), true)) {
                                    $fail('A series cannot be its own ancestor (cycle).');
                                }
                            })),
                        $g(Forms\Components\Toggle::make('is_wills_series')
                            ->required()),
                        $g(Forms\Components\Toggle::make('is_active')
                            ->default(true)   // Bug #21 — a new Series is active by default
                            ->required()),
                    ]),

                // Wave D1 — Repository scope + Document types
                Section::make('Repository & document types')
                    ->columns($twoCols)
                    ->schema([
                        $g(Forms\Components\Select::make('repository_id')
                            ->label('Repository')
                            ->options(function () {
                                $user = auth()->user();
                                $query = Repository::query();
                                if ($user !== null
                                    && method_exists($user, 'hasAnyRole')
                                    && ! $user->hasAnyRole(['super_admin', 'admin'])
                                ) {
                                    $ids = method_exists($user, 'repositories')
                                        ? $user->repositories()->pluck('repositories.id')->all()
                                        : [];
                                    $query->whereIn('id', $ids);
                                }

                                return $query->orderBy('code')->pluck('name', 'id')->all();
                            })
                            ->searchable()
                            ->nullable()
                            ->helperText('Leave empty for a GLOBAL series (visible to every repository).')
                            ->default(fn () => auth()->user()?->default_repository_id)),
                        $g(Forms\Components\Select::make('documentTypes')
                            ->label('Document types')
                            ->relationship('documentTypes', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->columnSpanFull()),
                    ]),

                Section::make('Description')
                    ->columns(1)
                    ->collapsed()
                    ->schema([
                        $g(Forms\Components\Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull()),
                    ]),
                // The columns this repository added itself.
                // Client 2026-09-25: standalone columns reach every entity, so
                // an extra column no longer needs a developer.
                Section::make('Custom fields')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema(static function (Get $get, ?Series $record): array {
                        return CustomFieldSchema::for('series', self::customFieldRepository($get, $record));
                    })
                    ->visible(static function (Get $get, ?Series $record): bool {
                        return CustomFieldSchema::for('series', self::customFieldRepository($get, $record)) !== [];
                    }),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        // Layout rule (user mandate): root columns(1) → full-width Sections;
        // atomic entries on ['default' => 1, 'md' => 2]; non-atomic content
        // (prose Description) → columnSpanFull. Series has no outbound FK
        // relationships — only the inverse (documents).
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->components([
                Section::make('Identification')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('code')
                            ->label('Identifier')
                            ->badge()
                            ->color('primary')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('title')
                            ->label('Title')
                            ->placeholder('—'),
                        // Feedback1 C1.4 — show the full hierarchy path and a
                        // link to the parent series.
                        TextEntry::make('hierarchy_path')
                            ->label('Hierarchy')
                            ->state(fn (?Series $record): string => $record?->qualifiedTitle() ?? '—')
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('parent.code')
                            ->label('Parent subseries')
                            ->url(fn (?Series $record): ?string => $record?->parent_id
                                ? route('filament.admin.resources.series.view', ['record' => $record->parent_id])
                                : null)
                            ->placeholder('Top-level (no parent)'),
                        IconEntry::make('is_wills_series')
                            ->label('Wills subseries')
                            ->boolean(),
                        IconEntry::make('is_active')
                            ->label('Active')
                            ->boolean(),
                    ]),

                Section::make('Description')
                    ->columns(1)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('description')
                            ->hiddenLabel()
                            ->prose()
                            ->placeholder('No description.')
                            ->columnSpanFull(),
                    ]),

                // ISAD(G) metadata imported from the client's sheet.
                Section::make('Archival metadata')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('level_of_description')
                            ->label('Level of description')
                            ->placeholder('—'),
                        TextEntry::make('date_of_creation')
                            ->label('Date of creation')
                            ->placeholder('—'),
                        TextEntry::make('name_of_inputter')
                            ->label('Name of Inputter (from sheet)')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Counts')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('documents_count')
                            ->label('Documents')
                            ->state(fn (?Series $record): int => $record?->documents()->count() ?? 0)
                            ->badge()
                            ->color('gray'),
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
        $gc = fn (mixed $col, ?string $fieldOverride = null): mixed => self::gateColumn($col, self::FIELD_PERMISSIONS_KEY, $fieldOverride);

        return $table
            // Feedback1 Wave B (B1) — persist & defer filters so they survive
            // navigation/refresh (client complaint: "filters seem to reset").
            ->deferFilters()
            ->persistFiltersInSession()
            // Feedback1 Wave A (A6) — drag-and-drop column reordering, mirroring
            // DocumentResource and BoxResource (spec: all main resource lists).
            ->reorderableColumns()
            ->columns([
                $gc(Tables\Columns\TextColumn::make('repository.code')
                    ->label('Repo')
                    ->badge()
                    ->color('gray')
                    ->placeholder('GLOBAL')
                    ->sortable()
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('code')
                    ->label('Identifier')
                    ->searchable()
                    ->sortable()),
                $gc(Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->toggleable()),
                // ISAD(G) metadata from the client's sheet. These used to be
                // visible ONLY on the record's detail page, which is what the
                // cataloguer's three reports were really about: in the list the
                // nearest-looking columns were `created_at` (the moment of the
                // import — hence "today's date" instead of the sheet's date)
                // and the audit-derived Inputter (whoever was logged in during
                // the import, not the name typed in the sheet). Level of
                // description had no column at all. The values were imported
                // correctly the whole time; the list simply never showed them.
                $gc(Tables\Columns\TextColumn::make('level_of_description')
                    ->label('Level of description')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('date_of_creation')
                    ->label('Date of creation')
                    // Deliberately NOT ->dateTime(): the column is free text so
                    // an ISAD(G) span such as "1607-1629" stays readable. Piping
                    // it through a date formatter would either mangle the span
                    // or throw on it.
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('name_of_inputter')
                    ->label('Name of Inputter')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable()),
                Tables\Columns\TextColumn::make('document_types_count')
                    ->label('Doc. types')
                    ->counts('documentTypes')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                // Feedback1 C1.4 — full multi-level hierarchy path
                // (e.g. "R › REG › RWL"), recursive over ancestors. Sorted off
                // by default to keep the default grid compact; toggle on to see
                // where each series sits in the tree.
                Tables\Columns\TextColumn::make('hierarchy_path')
                    ->label('Hierarchy')
                    ->state(fn (Series $record): string => $record->qualifiedTitle())
                    ->toggleable()
                    ->badge()
                    ->color('gray'),
                $gc(Tables\Columns\TextColumn::make('parent.code')
                    ->label('Parent')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)),
                $gc(Tables\Columns\IconColumn::make('is_wills_series')
                    ->boolean()
                    ->sortable()
                    ->toggleable()),
                $gc(Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable()
                    ->toggleable()),
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
                // A9 — audit-derived column: who entered the record in this
                // application. Relabelled "Uploaded by" on Series only, because
                // Series is the one table that ALSO carries the sheet's own
                // "Name of Inputter"; two columns both called Inputter, showing
                // different names for the same row, is what made the audit one
                // look wrong. The shared factory keeps its "Inputter" label for
                // the other 17 resources, which have nothing to confuse it with.
                CreatorColumn::make()
                    ->label('Uploaded by')
                    ->tooltip('The account that imported or created this record — not the cataloguer named in the sheet.'),
            ])
            ->filters([
                // Feedback1 Wave B (B1) — dropdown-driven filters (mechanism #1)
                // alongside the free-text search on code/title (mechanism #2).
                // Series is a small reference table → plain SelectFilter on the
                // distinct codes plus the two boolean flags as TernaryFilters.
                // A3/D9 — filter label matches the column rename: "Identifier".
                SelectFilter::make('code')
                    ->label('Identifier')
                    ->options(fn (): array => Series::query()
                        ->orderBy('code')
                        ->pluck('code', 'code')
                        ->all())
                    ->searchable()
                    ->multiple(),
                TernaryFilter::make('is_wills_series')
                    ->label('Wills subseries')
                    ->placeholder('All')
                    ->trueLabel('Wills subseries only')
                    ->falseLabel('Non-wills only'),
                TernaryFilter::make('is_active')
                    ->label('Active')
                    ->placeholder('All')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),
                // Feedback1 C1.4 — narrow to root series, and pick a parent.
                TernaryFilter::make('top_level')
                    ->label('Top-level only')
                    ->placeholder('All levels')
                    ->trueLabel('Top-level subseries only')
                    ->falseLabel('Child subseries only')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereNull('parent_id'),
                        false: fn (Builder $q): Builder => $q->whereNotNull('parent_id'),
                        blank: fn (Builder $q): Builder => $q,
                    ),
                SelectFilter::make('parent_id')
                    ->label('Parent subseries')
                    ->options(fn (): array => Series::query()
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn (Series $s): array => [$s->getKey() => $s->qualifiedTitle()])
                        ->all())
                    ->searchable(),
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
     * Eager-load the first 'created' audit with its user so CreatorColumn
     * can render the inputter name without N+1 queries.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            // A9 — creator resolution: first 'created' audit with its user.
            'audits' => fn ($q) => $q->oldest('id')->with('user'),
            // Schema audit (N+1): the list renders repository.code and the
            // parent chain per row — eager-load them so the default view does
            // not fire a query per row for the repository and immediate parent.
            'repository',
            'parent',
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
            'index' => Pages\ListSeries::route('/'),
            'create' => Pages\CreateSeries::route('/create'),
            'view' => Pages\ViewSeries::route('/{record}'),
            'edit' => Pages\EditSeries::route('/{record}/edit'),
        ];
    }

    /**
     * Which repository's added columns this form should show.
     *
     * Reads the live form state first, so picking a repository reveals that
     * repository's columns without a reload; falls back to the record being
     * edited, then to the operator's default repository on a blank create form.
     */
    private static function customFieldRepository(Get $get, ?Series $record): ?int
    {
        $repositoryId = (int) $get('repository_id')
            ?: $record?->repository_id
            ?: auth()->user()?->default_repository_id;

        return $repositoryId !== null ? (int) $repositoryId : null;
    }
}
