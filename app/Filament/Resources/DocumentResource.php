<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentResource\Pages;
use App\Filament\Resources\DocumentResource\RelationManagers;
use App\Models\Document;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

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
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('identifier')
                            ->required()
                            ->maxLength(64)
                            ->helperText('Document identifier (e.g. R1, R12-V3).'),
                        Forms\Components\TextInput::make('document_type')
                            ->maxLength(64),
                        Forms\Components\Select::make('series_id')
                            ->relationship('series', 'code')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('RFQ series classification.'),
                        Forms\Components\Select::make('repository_id')
                            ->relationship('repository', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Forms\Components\TextInput::make('volume_label')
                            ->label('Volume')
                            ->maxLength(64),
                    ]),

                Forms\Components\Section::make('Location')
                    ->columns(3)
                    ->schema([
                        Forms\Components\Select::make('batch_id')
                            ->relationship('batch', 'batch_number')
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('current_box_id')
                            ->label('Current box')
                            ->relationship('currentBox', 'box_number')
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('accession_id')
                            ->relationship('accession', 'code')
                            ->searchable()
                            ->preload(),
                    ]),

                Forms\Components\Section::make('Authorities (Creators)')
                    ->schema([
                        Forms\Components\Select::make('authorities')
                            ->multiple()
                            ->relationship('authorities', 'surname')
                            ->searchable()
                            ->preload()
                            ->helperText('Multi-select notaries / creators.'),
                    ]),

                Forms\Components\Section::make('Dates')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('dates_year_start')
                            ->label('Year start')
                            ->numeric()
                            ->minValue(1000)
                            ->maxValue(2100),
                        Forms\Components\TextInput::make('dates_year_end')
                            ->label('Year end')
                            ->numeric()
                            ->minValue(1000)
                            ->maxValue(2100),
                        Forms\Components\DatePicker::make('dates_start')
                            ->label('Date start (precise)'),
                        Forms\Components\DatePicker::make('dates_end')
                            ->label('Date end (precise)'),
                        Forms\Components\DatePicker::make('disinfestation_date'),
                    ]),

                Forms\Components\Section::make('Tags & Notes')
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->columnSpanFull()
                            ->rows(3),
                        Forms\Components\KeyValue::make('extra')
                            ->label('Extra metadata (schemaless)')
                            ->columnSpanFull(),
                    ])
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('identifier')
            ->columns([
                Tables\Columns\TextColumn::make('identifier')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('document_type')
                    ->searchable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('series.code')
                    ->label('Series')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('batch.batch_number')
                    ->label('Batch')
                    ->sortable()
                    ->alignCenter(),
                Tables\Columns\TextColumn::make('currentBox.box_number')
                    ->label('Box')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('repository.code')
                    ->label('Repo')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('volume_label')
                    ->label('Vol.')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('dates_year_start')
                    ->label('From')
                    ->numeric(thousandsSeparator: '')
                    ->sortable()
                    ->alignEnd(),
                Tables\Columns\TextColumn::make('dates_year_end')
                    ->label('To')
                    ->numeric(thousandsSeparator: '')
                    ->sortable()
                    ->alignEnd(),
                Tables\Columns\TextColumn::make('disinfestation_date')
                    ->label('Disinfested')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('series')
                    ->relationship('series', 'code')
                    ->searchable()
                    ->preload()
                    ->multiple(),
                SelectFilter::make('batch')
                    ->relationship('batch', 'batch_number')
                    ->searchable()
                    ->preload()
                    ->multiple(),
                SelectFilter::make('repository')
                    ->relationship('repository', 'code')
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('disinfestation_date')
                    ->label('Disinfested')
                    ->nullable()
                    ->trueLabel('Yes')
                    ->falseLabel('No')
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('disinfestation_date'),
                        false: fn ($q) => $q->whereNull('disinfestation_date'),
                    ),
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

    public static function getRelations(): array
    {
        return [
            // Add RelationManagers in W5 per plan.md (Authorities, Volumes, Movements, Audits)
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
