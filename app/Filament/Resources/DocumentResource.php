<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentResource\Pages;
use App\Filament\Resources\DocumentResource\RelationManagers;
use App\Models\Document;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('identifier')
                    ->required()
                    ->maxLength(64),
                Forms\Components\TextInput::make('document_type')
                    ->maxLength(64),
                Forms\Components\Select::make('series_id')
                    ->relationship('series', 'title')
                    ->required(),
                Forms\Components\Select::make('accession_id')
                    ->relationship('accession', 'id'),
                Forms\Components\Select::make('current_box_id')
                    ->relationship('currentBox', 'id'),
                Forms\Components\Select::make('batch_id')
                    ->relationship('batch', 'id'),
                Forms\Components\Select::make('repository_id')
                    ->relationship('repository', 'name')
                    ->required(),
                Forms\Components\TextInput::make('volume_label')
                    ->maxLength(64),
                Forms\Components\DatePicker::make('dates_start'),
                Forms\Components\DatePicker::make('dates_end'),
                Forms\Components\TextInput::make('dates_year_start')
                    ->numeric(),
                Forms\Components\TextInput::make('dates_year_end')
                    ->numeric(),
                Forms\Components\DatePicker::make('disinfestation_date'),
                Forms\Components\TextInput::make('extra'),
                Forms\Components\Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('identifier')
                    ->searchable(),
                Tables\Columns\TextColumn::make('document_type')
                    ->searchable(),
                Tables\Columns\TextColumn::make('series.title')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('accession.id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('currentBox.id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('batch.id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('repository.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('volume_label')
                    ->searchable(),
                Tables\Columns\TextColumn::make('dates_start')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('dates_end')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('dates_year_start')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('dates_year_end')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('disinfestation_date')
                    ->date()
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
                //
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
            //
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
