<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BoxMovementResource\Pages;
use App\Models\BoxMovement;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class BoxMovementResource extends Resource
{
    protected static ?string $model = BoxMovement::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string|\UnitEnum|null $navigationGroup = 'Archive';

    protected static ?int $navigationSort = 15;

    public static function form(Schema $schema): Schema
    {
        return $schema
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
            'index' => Pages\ListBoxMovements::route('/'),
            'create' => Pages\CreateBoxMovement::route('/create'),
            'view' => Pages\ViewBoxMovement::route('/{record}'),
            'edit' => Pages\EditBoxMovement::route('/{record}/edit'),
        ];
    }
}
