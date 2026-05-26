<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BoxMovementResource\Pages;
use App\Models\BoxMovement;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BoxMovementResource extends Resource
{
    protected static ?string $model = BoxMovement::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Archive';

    protected static ?int $navigationSort = 15;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('document.identifier')
                    ->relationship('document', 'identifier')
                    ->required(),
                Forms\Components\Select::make('from_box_id')
                    ->relationship('fromBox', 'box_number'),
                Forms\Components\Select::make('to_box_id')
                    ->relationship('toBox', 'box_number'),
                Forms\Components\DateTimePicker::make('movement_date')
                    ->required(),
                Forms\Components\TextInput::make('reason')
                    ->maxLength(255),
                Forms\Components\Select::make('user.name')
                    ->relationship('user', 'name'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('document.identifier')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('fromBox.box_number')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('toBox.box_number')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('movement_date')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('reason')
                    ->searchable(),
                Tables\Columns\TextColumn::make('user.name')
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
            'index' => Pages\ListBoxMovements::route('/'),
            'create' => Pages\CreateBoxMovement::route('/create'),
            'view' => Pages\ViewBoxMovement::route('/{record}'),
            'edit' => Pages\EditBoxMovement::route('/{record}/edit'),
        ];
    }
}
