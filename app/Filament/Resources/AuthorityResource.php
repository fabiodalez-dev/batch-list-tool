<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuthorityResource\Pages;
use App\Models\Authority;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AuthorityResource extends Resource
{
    protected static ?string $model = Authority::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationGroup = 'Reference';

    protected static ?int $navigationSort = 21;

    protected static ?string $recordTitleAttribute = 'surname';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('identifier')
                    ->required()
                    ->maxLength(32),
                Forms\Components\TextInput::make('alternative_identifier')
                    ->maxLength(32),
                Forms\Components\TextInput::make('surname')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('given_names')
                    ->maxLength(255),
                Forms\Components\TextInput::make('entity_type')
                    ->required()
                    ->maxLength(16)
                    ->default('PERSON'),
                Forms\Components\TextInput::make('practice_dates_start')
                    ->numeric(),
                Forms\Components\TextInput::make('practice_dates_end')
                    ->numeric(),
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
                Tables\Columns\TextColumn::make('alternative_identifier')
                    ->searchable(),
                Tables\Columns\TextColumn::make('surname')
                    ->searchable(),
                Tables\Columns\TextColumn::make('given_names')
                    ->searchable(),
                Tables\Columns\TextColumn::make('entity_type')
                    ->searchable(),
                Tables\Columns\TextColumn::make('practice_dates_start')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('practice_dates_end')
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
            'index' => Pages\ListAuthorities::route('/'),
            'create' => Pages\CreateAuthority::route('/create'),
            'view' => Pages\ViewAuthority::route('/{record}'),
            'edit' => Pages\EditAuthority::route('/{record}/edit'),
        ];
    }
}
