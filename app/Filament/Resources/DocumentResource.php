<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AppliesFieldPermissions;
use App\Filament\Resources\DocumentResource\Pages;
use App\Models\Document;
use App\Models\Location;
use App\Models\Repository;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DocumentResource extends Resource
{
    use AppliesFieldPermissions;

    /**
     * Config key used by App\Support\FieldPermissions to look up
     * the per-field, per-role read/write/hidden matrix (RFQ §3.1.8).
     */
    private const FIELD_PERMISSIONS_KEY = 'document';

    protected static ?string $model = Document::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Archive';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'identifier';

    public static function form(Schema $schema): Schema
    {
        // Local alias so each gate call stays a one-liner instead of
        // repeating the resource key constant 40+ times.
        $g = fn (Schemas\Components\Component $c): Schemas\Components\Component => self::gateField($c, self::FIELD_PERMISSIONS_KEY);

        return $schema
            ->schema([
                Schemas\Components\Section::make('Identification')
                    ->columns(3)
                    ->schema([
                        $g(Forms\Components\TextInput::make('identifier')->required()->maxLength(64)),
                        $g(Forms\Components\TextInput::make('catalogue_identifier')->maxLength(191)),
                        $g(Forms\Components\TextInput::make('document_type')->maxLength(100)),
                        $g(Forms\Components\Select::make('series_id')
                            ->label('Series')
                            ->relationship('series', 'code')
                            ->searchable()->preload()->required()),
                        // NOTE: this Select kept its existing role-aware
                        // `disabled()` closure (tenant scoping for admins
                        // who legitimately pick a target Repository). The
                        // field-level gate adds an ADDITIONAL layer: if
                        // the role is also denied by the matrix, the gate
                        // wins (both closures must allow for the input to
                        // be writable). The visible/hidden side comes
                        // entirely from the gate.
                        $g(Forms\Components\Select::make('repository_id')
                            ->label('Repository')
                            ->relationship(
                                'repository',
                                'name',
                                fn ($query) => $query->whereIn(
                                    'id',
                                    auth()->user()?->hasAnyRole(['super_admin', 'admin'])
                                        ? Repository::query()->pluck('id')->all()
                                        : (auth()->user()?->repositories()->pluck('repositories.id')->all() ?? [])
                                )
                            )
                            ->required()
                            ->default(fn () => auth()->user()?->default_repository_id)
                            ->searchable()->preload()),
                        $g(Forms\Components\TextInput::make('volume_label')->label('Volume label')->maxLength(64)),
                        $g(Forms\Components\TextInput::make('practice')->maxLength(100)),
                        $g(Forms\Components\TextInput::make('dates')->label('Dates (text)')->maxLength(191)
                            ->helperText('Free-text dates as in POC, e.g. "1607-1629" or "Jun 1997 - Nov 1998"')),
                        $g(Forms\Components\TextInput::make('deeds')->maxLength(2000)),
                    ]),

                Schemas\Components\Section::make('Authorities (Creators)')
                    ->schema([
                        // `authorities` is a BelongsToMany relation, not a
                        // column on documents — it has no matrix entry and
                        // falls back to the `_default` (allow all editors).
                        $g(Forms\Components\Select::make('authorities')
                            ->multiple()
                            ->relationship('authorities', 'surname')
                            ->searchable()->preload()),
                    ]),

                Schemas\Components\Section::make('Current location')
                    ->columns(3)
                    ->schema([
                        $g(Forms\Components\Select::make('batch_id')->relationship('batch', 'batch_number')->searchable()->preload()),
                        $g(Forms\Components\Select::make('current_box_id')->relationship('currentBox', 'box_number')->searchable()->preload()),
                        $g(Forms\Components\Select::make('accession_id')->relationship('accession', 'code')->searchable()->preload()),
                        // RFQ §3.1.9 — configurable Location hierarchy.
                        // Lives next to the legacy `nra_location` / `museum_location` free-text
                        // columns kept for POC parity; from now on this Select is the source of
                        // truth and the legacy strings are slated for retirement (see PR body).
                        $g(Forms\Components\Select::make('location_id')
                            ->label('Location (RFQ §3.1.9)')
                            ->relationship(
                                'location',
                                'name',
                                fn ($query) => $query
                                    ->active()
                                    ->forRepository(auth()->user()?->default_repository_id),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Location $r) => $r->breadcrumb())
                            ->searchable(['name', 'code'])
                            ->preload()
                            ->nullable()
                            ->helperText('Repository / room / shelf / showcase / temp-holding hierarchy.')),
                        $g(Forms\Components\TextInput::make('current_box_type')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('nra_location')->maxLength(500)
                            ->helperText('Legacy free-text. New records should use the Location Select above.')),
                        $g(Forms\Components\TextInput::make('museum_location')->maxLength(500)
                            ->helperText('Legacy free-text. New records should use the Location Select above.')),
                    ]),

                Schemas\Components\Section::make('Legacy box history (RAS / In Situ)')
                    ->collapsed()
                    ->columns(4)
                    ->schema([
                        $g(Forms\Components\TextInput::make('ras_batch_1')->label('RAS Batch 1')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('ras_box_1')->label('RAS Box 1')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('ras_1_box_destroyed')->label('RAS 1 Destroyed?')->maxLength(10)),
                        $g(Forms\Components\TextInput::make('in_situ_box_1')->label('In Situ Box 1')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('ras_batch_2')->label('RAS Batch 2')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('ras_box_2')->label('RAS Box 2')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('ras_2_box_destroyed')->label('RAS 2 Destroyed?')->maxLength(10)),
                        $g(Forms\Components\TextInput::make('in_situ_box_2')->label('In Situ Box 2')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('in_situ_box_1_destroyed')->label('In Situ 1 Destroyed?')->maxLength(10)),
                        $g(Forms\Components\TextInput::make('in_situ_box_2_destroyed')->label('In Situ 2 Destroyed?')->maxLength(10)),
                        $g(Forms\Components\TextInput::make('in_situ_box_3')->label('In Situ Box 3')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('in_situ_box_3_destroyed')->label('In Situ 3 Destroyed?')->maxLength(10)),
                    ]),

                Schemas\Components\Section::make('Legacy barcodes & status')
                    ->collapsed()
                    ->columns(4)
                    ->schema([
                        $g(Forms\Components\TextInput::make('barcode_in')->label('Barcode (IN)')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('barcode_ras_1')->label('Barcode RAS 1')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('status_1')->label('Status 1')->maxLength(20)),
                        $g(Forms\Components\TextInput::make('barcode_ras_2')->label('Barcode RAS 2')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('status_2')->label('Status 2')->maxLength(20)),
                        $g(Forms\Components\TextInput::make('barcode_ras_3')->label('Barcode RAS 3')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('status_3')->label('Status 3')->maxLength(20)),
                        $g(Forms\Components\TextInput::make('barcode_ras_4')->label('Barcode RAS 4')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('status_4')->label('Status 4')->maxLength(20)),
                        $g(Forms\Components\TextInput::make('barcode_in_2')->label('Barcode (IN) #2')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('barcode_ras_2_alt')->label('Barcode RAS 2 alt')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('status_1_alt')->label('Status 1 alt')->maxLength(20)),
                        $g(Forms\Components\TextInput::make('barcode_ras_2_alt2')->label('Barcode RAS 2 alt 2')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('status_2_alt')->label('Status 2 alt')->maxLength(20)),
                    ]),

                Schemas\Components\Section::make('Seal & disinfestation')
                    ->columns(4)
                    ->schema([
                        $g(Forms\Components\TextInput::make('seal_number')->maxLength(50)),
                        $g(Forms\Components\DatePicker::make('disinfestation_date_1')->label('Disinfestation 1')),
                        $g(Forms\Components\DatePicker::make('disinfestation_date_2')->label('Disinfestation 2')),
                        $g(Forms\Components\DatePicker::make('disinfestation_date_3')->label('Disinfestation 3')),
                        $g(Forms\Components\DatePicker::make('disinfestation_date')->label('Disinfestation (current)')),
                    ]),

                Schemas\Components\Section::make('Dates (precise)')
                    ->columns(4)
                    ->schema([
                        $g(Forms\Components\TextInput::make('dates_year_start')->label('Year start')->numeric()),
                        $g(Forms\Components\TextInput::make('dates_year_end')->label('Year end')->numeric()),
                        $g(Forms\Components\DatePicker::make('dates_start')->label('Date start')),
                        $g(Forms\Components\DatePicker::make('dates_end')->label('Date end')),
                    ]),

                Schemas\Components\Section::make('Cataloguing extras')
                    ->collapsed()
                    ->columns(3)
                    ->schema([
                        $g(Forms\Components\TextInput::make('colour_code')->maxLength(32)),
                        $g(Forms\Components\TextInput::make('digitised')->maxLength(100)),
                        $g(Forms\Components\Toggle::make('torre')),
                        $g(Forms\Components\TextInput::make('accession_code_legacy')->label('Accession (legacy text)')->maxLength(191)),
                        $g(Forms\Components\TextInput::make('object_reference_number')->maxLength(500)),
                        $g(Forms\Components\TextInput::make('tracking')->maxLength(500)),
                        $g(Forms\Components\TextInput::make('museum_reference')->maxLength(500)),
                    ]),

                Schemas\Components\Section::make('Notes & custom fields')
                    ->collapsed()
                    ->schema([
                        $g(Forms\Components\Textarea::make('notes')->columnSpanFull()->rows(3)),
                        $g(Forms\Components\KeyValue::make('extra')->label('Extra (schemaless)')->columnSpanFull()),
                        $g(Forms\Components\KeyValue::make('custom_fields')->label('Custom fields (POC json)')->columnSpanFull()),
                        $g(Forms\Components\KeyValue::make('metadata')->label('Metadata (POC json)')->columnSpanFull()),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        // Same wrapping trick as form(): keep the column declarations
        // single-line, route through the gate. For relationship columns
        // (`series.code`, `repository.code`) we pass the local FK column
        // name explicitly so the matrix can still gate it.
        $gc = fn (mixed $col, ?string $fieldOverride = null): mixed => self::gateColumn($col, self::FIELD_PERMISSIONS_KEY, $fieldOverride);

        return $table
            ->defaultSort('identifier')
            ->columns([
                $gc(Tables\Columns\TextColumn::make('identifier')->searchable()->sortable()->copyable()),
                $gc(Tables\Columns\TextColumn::make('document_type')->searchable()->toggleable()),
                $gc(Tables\Columns\TextColumn::make('series.code')->label('Series')->badge()->sortable(), 'series_id'),
                $gc(Tables\Columns\TextColumn::make('batch.batch_number')->label('Batch')->sortable()->alignCenter(), 'batch_id'),
                $gc(Tables\Columns\TextColumn::make('currentBox.box_number')->label('Box')->toggleable(), 'current_box_id'),
                $gc(Tables\Columns\TextColumn::make('practice')->toggleable()),
                $gc(Tables\Columns\TextColumn::make('volume_label')->label('Vol.')->toggleable()),
                $gc(Tables\Columns\TextColumn::make('dates')->label('Dates')->toggleable()->limit(30)),
                $gc(Tables\Columns\TextColumn::make('dates_year_start')->label('From')->numeric(thousandsSeparator: '')->sortable()->alignEnd()),
                $gc(Tables\Columns\TextColumn::make('dates_year_end')->label('To')->numeric(thousandsSeparator: '')->sortable()->alignEnd()),
                $gc(Tables\Columns\TextColumn::make('barcode_in')->label('Barcode (IN)')->toggleable(isToggledHiddenByDefault: true)->searchable()),
                $gc(Tables\Columns\TextColumn::make('catalogue_identifier')->label('Catalogue ID')->toggleable(isToggledHiddenByDefault: true)->searchable()),
                $gc(Tables\Columns\TextColumn::make('repository.code')->label('Repo')->badge()->color('gray')->toggleable(), 'repository_id'),
                $gc(Tables\Columns\TextColumn::make('disinfestation_date')->label('Disinfested')->date()->sortable()->toggleable(isToggledHiddenByDefault: true)),
                $gc(Tables\Columns\IconColumn::make('torre')->boolean()->toggleable(isToggledHiddenByDefault: true)),
                $gc(Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true)),
            ])
            ->filtersFormColumns(3)
            ->filters([
                // Relationship multi-selects (parity with POC creators/series/batch filters)
                SelectFilter::make('series')
                    ->relationship('series', 'code')->searchable()->preload()->multiple(),

                SelectFilter::make('batch')
                    ->relationship('batch', 'batch_number')->searchable()->preload()->multiple(),

                SelectFilter::make('repository')
                    ->relationship('repository', 'code')->searchable()->preload(),

                SelectFilter::make('current_box_id')
                    ->label('Current box')
                    ->relationship('currentBox', 'box_number')->searchable()->preload()->multiple(),

                SelectFilter::make('accession_id')
                    ->label('Accession')
                    ->relationship('accession', 'code')->searchable()->preload(),

                SelectFilter::make('authorities')
                    ->label('Creators')
                    ->relationship('authorities', 'surname')->searchable()->preload()->multiple(),

                // Free-text search per field (POC-style filtri puntuali).
                // For columns covered by a single-column FULLTEXT index
                // (notes, deeds, museum_reference) we use the model scope:
                // on MySQL it expands to MATCH(...) AGAINST(... IN NATURAL
                // LANGUAGE MODE) and uses the FT index added by migration
                // 2026_05_18_100000; on other drivers it transparently falls
                // back to the same LIKE chain.
                // Short-string indexed columns (barcode_in, catalogue_identifier,
                // practice) keep the LIKE filter because they're already covered
                // by B-tree indexes and a FULLTEXT index on a VARCHAR(50) gives
                // no measurable gain.
                self::likeFilter('barcode_in', 'Search in Barcode (IN)'),
                self::likeFilter('catalogue_identifier', 'Search in Catalogue ID'),
                self::likeFilter('practice', 'Search in Practice'),
                self::fullTextFilter('notes', 'Search in Notes'),
                self::fullTextFilter('deeds', 'Search in Deeds'),
                self::fullTextFilter('museum_reference', 'Search in Museum Reference'),

                // volume_label is special — also searches the JSON path extra->volume; kept inline.
                Filter::make('volume_label')
                    ->form([
                        Forms\Components\TextInput::make('value')->label('Search in Volume'),
                    ])
                    ->query(
                        fn (Builder $q, array $data) => $q->when(
                            $data['value'] ?? null,
                            fn ($q, $v) => $q->where(function ($q) use ($v) {
                                $needle = '%' . trim($v) . '%';
                                $q->where('volume_label', 'like', $needle)
                                    ->orWhere('extra->volume', 'like', $needle);
                            })
                        )
                    ),

                // Year range filter
                Filter::make('year_range')
                    ->form([
                        Forms\Components\TextInput::make('year_from')->label('Year from')->numeric(),
                        Forms\Components\TextInput::make('year_to')->label('Year to')->numeric(),
                    ])
                    ->query(function (Builder $q, array $data) {
                        return $q
                            ->when($data['year_from'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->whereNull('dates_year_end')
                                ->orWhere('dates_year_end', '>=', (int) $v)))
                            ->when($data['year_to'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->whereNull('dates_year_start')
                                ->orWhere('dates_year_start', '<=', (int) $v)));
                    })
                    ->indicateUsing(function (array $data): array {
                        $i = [];
                        if (! empty($data['year_from'])) {
                            $i[] = "Year ≥ {$data['year_from']}";
                        }
                        if (! empty($data['year_to'])) {
                            $i[] = "Year ≤ {$data['year_to']}";
                        }

                        return $i;
                    }),

                // Disinfestation date range
                Filter::make('disinfestation_range')
                    ->form([
                        Forms\Components\DatePicker::make('disinfested_from')->label('Disinfested from'),
                        Forms\Components\DatePicker::make('disinfested_to')->label('Disinfested to'),
                    ])
                    ->query(function (Builder $q, array $data) {
                        return $q
                            ->when(
                                $data['disinfested_from'] ?? null,
                                fn ($q, $v) => $q->whereDate('disinfestation_date', '>=', $v)
                            )
                            ->when(
                                $data['disinfested_to'] ?? null,
                                fn ($q, $v) => $q->whereDate('disinfestation_date', '<=', $v)
                            );
                    }),

                // Ternary filters
                TernaryFilter::make('torre')
                    ->placeholder('Any')->trueLabel('Torre = yes')->falseLabel('Torre = no'),

                TernaryFilter::make('disinfestation_date')
                    ->label('Disinfested?')->nullable()
                    ->trueLabel('Yes')->falseLabel('No')
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('disinfestation_date'),
                        false: fn ($q) => $q->whereNull('disinfestation_date'),
                    ),

                TernaryFilter::make('has_barcode')
                    ->label('Has barcode?')
                    ->placeholder('Any')->trueLabel('Yes')->falseLabel('No')
                    ->queries(
                        true: fn ($q) => $q->whereRaw("TRIM(COALESCE(barcode_in, '')) <> ''"),
                        false: fn ($q) => $q->whereRaw("TRIM(COALESCE(barcode_in, '')) = ''"),
                    ),

                TernaryFilter::make('has_box')
                    ->label('Assigned to box?')
                    ->placeholder('Any')->trueLabel('Yes')->falseLabel('No')
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('current_box_id'),
                        false: fn ($q) => $q->whereNull('current_box_id'),
                    ),

                TernaryFilter::make('has_notes')
                    ->label('Has notes?')
                    ->placeholder('Any')->trueLabel('Yes')->falseLabel('No')
                    ->queries(
                        true: fn ($q) => $q->whereRaw("TRIM(COALESCE(notes, '')) <> ''"),
                        false: fn ($q) => $q->whereRaw("TRIM(COALESCE(notes, '')) = ''"),
                    ),

                // Soft-deleted records filter
                TrashedFilter::make(),
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
            DocumentResource\RelationManagers\IdentifierHistoryRelationManager::class,
            DocumentResource\RelationManagers\SealNumberHistoryRelationManager::class,
        ];
    }

    /**
     * Eager-load identifierHistory so the global search can match on
     * previous identifiers without N+1 queries.
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('identifierHistory');
    }

    /**
     * Apply conditional eager-loading to the base query.
     *
     * NOTE on timing: Filament evaluates `getEloquentQuery()` BEFORE the
     * table's filters run (see `Filament\Tables\Concerns\HasRecords::filterTableQuery()`
     * — the eloquent builder returned here is the one filters are then
     * stacked onto). That means the `conditionallyWith()` count probes
     * the full table, not the post-filter subset. For the production
     * archive (~50k+ docs) the count will always cross the 200 threshold,
     * so the eager load is effectively always-on — which is the SAFE
     * default and matches the previous behaviour. For smaller installs
     * (e.g. a development copy with < 200 documents) the scope skips
     * the eager load and lets Filament fall back to lazy access per row,
     * which is cheaper for the dev case.
     *
     * If a future page wants true post-filter conditional preloading it
     * should override `ListDocuments::getTableRecords()` and call
     * `loadMissing(...)` on the paginated collection — Filament does not
     * expose a post-filter hook on the resource itself.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->conditionallyWith([
                'series',
                'batch',
                'currentBox.batch',
                'repository',
                'authorities',
            ]);
    }

    /** Extend the global search bar (top-right of Filament panel) — POC parity. */
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'identifier',
            'catalogue_identifier',
            'document_type',
            'practice',
            'volume_label',
            'dates',
            'notes',
            'barcode_in',
            'series.code',
            'series.title',
            'authorities.surname',
            'authorities.identifier',
            // Identifier history (PR #8) — searching for "R7-old" finds the document
            // whose identifier was previously "R7-old", even after re-classification.
            'identifierHistory.previous_identifier',
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocuments::route('/'),
            'create' => Pages\CreateDocument::route('/create'),
            'view' => Pages\ViewDocument::route('/{record}'),
            'edit' => Pages\EditDocument::route('/{record}/edit'),
        ];
    }

    /**
     * Build a leading-/trailing-wildcard LIKE filter on a single column.
     * Centralises the form + query shape shared by all "Search in X" filters.
     */
    private static function likeFilter(string $name, string $label, ?string $column = null): Filter
    {
        $col = $column ?? $name;

        return Filter::make($name)
            ->form([Forms\Components\TextInput::make('value')->label($label)])
            ->query(fn (Builder $q, array $data) => $q->when($data['value'] ?? null, fn ($q, $v) => $q->where($col, 'like', '%' . trim($v) . '%')));
    }

    /**
     * Build a FULLTEXT-backed filter on a single column. Delegates to
     * Document::scopeSearchFullText() which handles the MySQL/non-MySQL
     * driver split (MATCH...AGAINST vs LIKE) and the empty-term no-op.
     *
     * One column per filter is intentional: MySQL only uses a FULLTEXT
     * index when the MATCH() column list exactly matches the index's
     * column list, and the migration creates one single-column index
     * per searchable column.
     */
    private static function fullTextFilter(string $name, string $label, ?string $column = null): Filter
    {
        $col = $column ?? $name;

        return Filter::make($name)
            ->form([Forms\Components\TextInput::make('value')->label($label)])
            ->query(fn (Builder $q, array $data) => $q->when(
                $data['value'] ?? null,
                fn (Builder $q, string $v) => $q->searchFullText($v, [$col]),
            ));
    }
}
