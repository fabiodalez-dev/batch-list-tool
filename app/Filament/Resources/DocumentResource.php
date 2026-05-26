<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentResource\Pages;
use App\Models\Document;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Archive';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'identifier';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identification')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('identifier')->required()->maxLength(64),
                        Forms\Components\TextInput::make('catalogue_identifier')->maxLength(191),
                        Forms\Components\TextInput::make('document_type')->maxLength(100),
                        Forms\Components\Select::make('series_id')
                            ->label('Series')
                            ->relationship('series', 'code')
                            ->searchable()->preload()->required(),
                        Forms\Components\Select::make('repository_id')
                            ->label('Repository')
                            ->relationship(
                                'repository',
                                'name',
                                fn ($query) => $query->whereIn(
                                    'id',
                                    auth()->user()?->hasAnyRole(['super_admin', 'admin'])
                                        ? \App\Models\Repository::query()->pluck('id')->all()
                                        : (auth()->user()?->repositories()->pluck('repositories.id')->all() ?? [])
                                )
                            )
                            ->required()
                            ->default(fn () => auth()->user()?->default_repository_id)
                            ->disabled(fn () => ! auth()->user()?->hasAnyRole(['super_admin', 'admin']))
                            ->dehydrated() // keep value submitted even when disabled
                            ->searchable()->preload(),
                        Forms\Components\TextInput::make('volume_label')->label('Volume label')->maxLength(64),
                        Forms\Components\TextInput::make('practice')->maxLength(100),
                        Forms\Components\TextInput::make('dates')->label('Dates (text)')->maxLength(191)
                            ->helperText('Free-text dates as in POC, e.g. "1607-1629" or "Jun 1997 - Nov 1998"'),
                        Forms\Components\TextInput::make('deeds')->maxLength(2000),
                    ]),

                Forms\Components\Section::make('Authorities (Creators)')
                    ->schema([
                        Forms\Components\Select::make('authorities')
                            ->multiple()
                            ->relationship('authorities', 'surname')
                            ->searchable()->preload(),
                    ]),

                Forms\Components\Section::make('Current location')
                    ->columns(3)
                    ->schema([
                        Forms\Components\Select::make('batch_id')->relationship('batch', 'batch_number')->searchable()->preload(),
                        Forms\Components\Select::make('current_box_id')->relationship('currentBox', 'box_number')->searchable()->preload(),
                        Forms\Components\Select::make('accession_id')->relationship('accession', 'code')->searchable()->preload(),
                        Forms\Components\TextInput::make('current_box_type')->maxLength(50),
                        Forms\Components\TextInput::make('nra_location')->maxLength(500),
                        Forms\Components\TextInput::make('museum_location')->maxLength(500),
                    ]),

                Forms\Components\Section::make('Legacy box history (RAS / In Situ)')
                    ->collapsed()
                    ->columns(4)
                    ->schema([
                        Forms\Components\TextInput::make('ras_batch_1')->label('RAS Batch 1')->maxLength(50),
                        Forms\Components\TextInput::make('ras_box_1')->label('RAS Box 1')->maxLength(50),
                        Forms\Components\TextInput::make('ras_1_box_destroyed')->label('RAS 1 Destroyed?')->maxLength(10),
                        Forms\Components\TextInput::make('in_situ_box_1')->label('In Situ Box 1')->maxLength(50),
                        Forms\Components\TextInput::make('ras_batch_2')->label('RAS Batch 2')->maxLength(50),
                        Forms\Components\TextInput::make('ras_box_2')->label('RAS Box 2')->maxLength(50),
                        Forms\Components\TextInput::make('ras_2_box_destroyed')->label('RAS 2 Destroyed?')->maxLength(10),
                        Forms\Components\TextInput::make('in_situ_box_2')->label('In Situ Box 2')->maxLength(50),
                        Forms\Components\TextInput::make('in_situ_box_1_destroyed')->label('In Situ 1 Destroyed?')->maxLength(10),
                        Forms\Components\TextInput::make('in_situ_box_2_destroyed')->label('In Situ 2 Destroyed?')->maxLength(10),
                        Forms\Components\TextInput::make('in_situ_box_3')->label('In Situ Box 3')->maxLength(50),
                        Forms\Components\TextInput::make('in_situ_box_3_destroyed')->label('In Situ 3 Destroyed?')->maxLength(10),
                    ]),

                Forms\Components\Section::make('Legacy barcodes & status')
                    ->collapsed()
                    ->columns(4)
                    ->schema([
                        Forms\Components\TextInput::make('barcode_in')->label('Barcode (IN)')->maxLength(50),
                        Forms\Components\TextInput::make('barcode_ras_1')->label('Barcode RAS 1')->maxLength(50),
                        Forms\Components\TextInput::make('status_1')->label('Status 1')->maxLength(20),
                        Forms\Components\TextInput::make('barcode_ras_2')->label('Barcode RAS 2')->maxLength(50),
                        Forms\Components\TextInput::make('status_2')->label('Status 2')->maxLength(20),
                        Forms\Components\TextInput::make('barcode_ras_3')->label('Barcode RAS 3')->maxLength(50),
                        Forms\Components\TextInput::make('status_3')->label('Status 3')->maxLength(20),
                        Forms\Components\TextInput::make('barcode_ras_4')->label('Barcode RAS 4')->maxLength(50),
                        Forms\Components\TextInput::make('status_4')->label('Status 4')->maxLength(20),
                        Forms\Components\TextInput::make('barcode_in_2')->label('Barcode (IN) #2')->maxLength(50),
                        Forms\Components\TextInput::make('barcode_ras_2_alt')->label('Barcode RAS 2 alt')->maxLength(50),
                        Forms\Components\TextInput::make('status_1_alt')->label('Status 1 alt')->maxLength(20),
                        Forms\Components\TextInput::make('barcode_ras_2_alt2')->label('Barcode RAS 2 alt 2')->maxLength(50),
                        Forms\Components\TextInput::make('status_2_alt')->label('Status 2 alt')->maxLength(20),
                    ]),

                Forms\Components\Section::make('Seal & disinfestation')
                    ->columns(4)
                    ->schema([
                        Forms\Components\TextInput::make('seal_number')->maxLength(50),
                        Forms\Components\DatePicker::make('disinfestation_date_1')->label('Disinfestation 1'),
                        Forms\Components\DatePicker::make('disinfestation_date_2')->label('Disinfestation 2'),
                        Forms\Components\DatePicker::make('disinfestation_date_3')->label('Disinfestation 3'),
                        Forms\Components\DatePicker::make('disinfestation_date')->label('Disinfestation (current)'),
                    ]),

                Forms\Components\Section::make('Dates (precise)')
                    ->columns(4)
                    ->schema([
                        Forms\Components\TextInput::make('dates_year_start')->label('Year start')->numeric(),
                        Forms\Components\TextInput::make('dates_year_end')->label('Year end')->numeric(),
                        Forms\Components\DatePicker::make('dates_start')->label('Date start'),
                        Forms\Components\DatePicker::make('dates_end')->label('Date end'),
                    ]),

                Forms\Components\Section::make('Cataloguing extras')
                    ->collapsed()
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('colour_code')->maxLength(32),
                        Forms\Components\TextInput::make('digitised')->maxLength(100),
                        Forms\Components\Toggle::make('torre'),
                        Forms\Components\TextInput::make('accession_code_legacy')->label('Accession (legacy text)')->maxLength(191),
                        Forms\Components\TextInput::make('object_reference_number')->maxLength(500),
                        Forms\Components\TextInput::make('tracking')->maxLength(500),
                        Forms\Components\TextInput::make('museum_reference')->maxLength(500),
                    ]),

                Forms\Components\Section::make('Notes & custom fields')
                    ->collapsed()
                    ->schema([
                        Forms\Components\Textarea::make('notes')->columnSpanFull()->rows(3),
                        Forms\Components\KeyValue::make('extra')->label('Extra (schemaless)')->columnSpanFull(),
                        Forms\Components\KeyValue::make('custom_fields')->label('Custom fields (POC json)')->columnSpanFull(),
                        Forms\Components\KeyValue::make('metadata')->label('Metadata (POC json)')->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('identifier')
            ->columns([
                Tables\Columns\TextColumn::make('identifier')->searchable()->sortable()->copyable(),
                Tables\Columns\TextColumn::make('document_type')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('series.code')->label('Series')->badge()->sortable(),
                Tables\Columns\TextColumn::make('batch.batch_number')->label('Batch')->sortable()->alignCenter(),
                Tables\Columns\TextColumn::make('currentBox.box_number')->label('Box')->toggleable(),
                Tables\Columns\TextColumn::make('practice')->toggleable(),
                Tables\Columns\TextColumn::make('volume_label')->label('Vol.')->toggleable(),
                Tables\Columns\TextColumn::make('dates')->label('Dates')->toggleable()->limit(30),
                Tables\Columns\TextColumn::make('dates_year_start')->label('From')->numeric(thousandsSeparator: '')->sortable()->alignEnd(),
                Tables\Columns\TextColumn::make('dates_year_end')->label('To')->numeric(thousandsSeparator: '')->sortable()->alignEnd(),
                Tables\Columns\TextColumn::make('barcode_in')->label('Barcode (IN)')->toggleable(isToggledHiddenByDefault: true)->searchable(),
                Tables\Columns\TextColumn::make('catalogue_identifier')->label('Catalogue ID')->toggleable(isToggledHiddenByDefault: true)->searchable(),
                Tables\Columns\TextColumn::make('repository.code')->label('Repo')->badge()->color('gray')->toggleable(),
                Tables\Columns\TextColumn::make('disinfestation_date')->label('Disinfested')->date()->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('torre')->boolean()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
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

                // Free-text search per field (POC-style filtri puntuali)
                self::likeFilter('barcode_in',           'Search in Barcode (IN)'),
                self::likeFilter('catalogue_identifier', 'Search in Catalogue ID'),
                self::likeFilter('practice',             'Search in Practice'),
                self::likeFilter('notes',                'Search in Notes'),

                // volume_label is special — also searches the JSON path extra->volume; kept inline.
                Filter::make('volume_label')
                    ->form([
                        Forms\Components\TextInput::make('value')->label('Search in Volume'),
                    ])
                    ->query(fn (Builder $q, array $data) =>
                        $q->when($data['value'] ?? null,
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
                            ->when($data['year_from'] ?? null, fn ($q, $v) =>
                                $q->where(fn ($q) => $q->whereNull('dates_year_end')
                                                        ->orWhere('dates_year_end', '>=', (int) $v)))
                            ->when($data['year_to'] ?? null, fn ($q, $v) =>
                                $q->where(fn ($q) => $q->whereNull('dates_year_start')
                                                        ->orWhere('dates_year_start', '<=', (int) $v)));
                    })
                    ->indicateUsing(function (array $data): array {
                        $i = [];
                        if (! empty($data['year_from'])) $i[] = "Year ≥ {$data['year_from']}";
                        if (! empty($data['year_to'])) $i[] = "Year ≤ {$data['year_to']}";
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
                            ->when($data['disinfested_from'] ?? null,
                                fn ($q, $v) => $q->whereDate('disinfestation_date', '>=', $v))
                            ->when($data['disinfested_to'] ?? null,
                                fn ($q, $v) => $q->whereDate('disinfestation_date', '<=', $v));
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
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
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
            ->query(fn (Builder $q, array $data) =>
                $q->when($data['value'] ?? null, fn ($q, $v) =>
                    $q->where($col, 'like', '%' . trim($v) . '%')));
    }

    public static function getRelations(): array
    {
        return [];
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
}
