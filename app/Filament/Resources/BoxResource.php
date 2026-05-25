<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BoxResource\Pages;
use App\Filament\Resources\BoxResource\RelationManagers;
use App\Models\Box;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BoxResource extends Resource
{
    protected static ?string $model = Box::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationGroup = 'Archive';

    protected static ?int $navigationSort = 13;

    protected static ?string $recordTitleAttribute = 'box_number';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('box_type')
                    ->required(),
                Forms\Components\TextInput::make('box_number')
                    ->required()
                    ->maxLength(32),
                Forms\Components\Select::make('batch.batch_number')
                    ->relationship('batch', 'batch_number'),
                Forms\Components\TextInput::make('parent_box_id')
                    ->numeric(),
                Forms\Components\TextInput::make('barcode')
                    ->maxLength(64),
                Forms\Components\TextInput::make('barcode_status')
                    ->required(),
                Forms\Components\DatePicker::make('disinfestation_date'),
                Forms\Components\Toggle::make('is_legacy')
                    ->required(),
                Forms\Components\Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('box_type'),
                Tables\Columns\TextColumn::make('box_number')
                    ->searchable(),
                Tables\Columns\TextColumn::make('batch.batch_number')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('parent_box_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('barcode')
                    ->searchable(),
                Tables\Columns\TextColumn::make('barcode_status'),
                Tables\Columns\TextColumn::make('disinfestation_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_legacy')
                    ->boolean(),
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
            'index' => Pages\ListBoxes::route('/'),
            'create' => Pages\CreateBox::route('/create'),
            'view' => Pages\ViewBox::route('/{record}'),
            'edit' => Pages\EditBox::route('/{record}/edit'),
        ];
    }
}
