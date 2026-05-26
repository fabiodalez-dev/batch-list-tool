<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VolumeResource\Pages;
use App\Filament\Support\SearchableSelects;
use App\Models\Volume;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class VolumeResource extends Resource
{
    protected static ?string $model = Volume::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static string|\UnitEnum|null $navigationGroup = 'Archive';

    protected static ?int $navigationSort = 14;

    protected static ?string $recordTitleAttribute = 'volume_number';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                // Searchable autocomplete (no preload): the documents table has
                // 3,000+ rows in production, so a `<select>` with that many
                // options is unusable. See App\Filament\Support\SearchableSelects.
                SearchableSelects::documentVia('document_id', 'document')
                    ->required(),
                Forms\Components\TextInput::make('volume_number')
                    ->required()
                    ->maxLength(32),
                Forms\Components\DatePicker::make('dates_start'),
                Forms\Components\DatePicker::make('dates_end'),
                Forms\Components\Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('document.identifier')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('volume_number')
                    ->searchable(),
                Tables\Columns\TextColumn::make('dates_start')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('dates_end')
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
            'index' => Pages\ListVolumes::route('/'),
            'create' => Pages\CreateVolume::route('/create'),
            'view' => Pages\ViewVolume::route('/{record}'),
            'edit' => Pages\EditVolume::route('/{record}/edit'),
        ];
    }
}
