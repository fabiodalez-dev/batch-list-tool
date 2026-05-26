<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AppliesFieldPermissions;
use App\Filament\Resources\SeriesResource\Pages;
use App\Models\Series;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class SeriesResource extends Resource
{
    use AppliesFieldPermissions;

    /** RFQ §3.1.8 — see config/field_permissions.php */
    private const FIELD_PERMISSIONS_KEY = 'series';

    protected static ?string $model = Series::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static string|\UnitEnum|null $navigationGroup = 'Reference';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        $g = fn (Schemas\Components\Component $c): Schemas\Components\Component => self::gateField($c, self::FIELD_PERMISSIONS_KEY);

        return $schema
            ->schema([
                $g(Forms\Components\TextInput::make('code')
                    ->required()
                    ->maxLength(16)),
                $g(Forms\Components\TextInput::make('title')
                    ->required()
                    ->maxLength(255)),
                $g(Forms\Components\Textarea::make('description')
                    ->columnSpanFull()),
                $g(Forms\Components\Toggle::make('is_wills_series')
                    ->required()),
                $g(Forms\Components\Toggle::make('is_active')
                    ->required()),
            ]);
    }

    public static function table(Table $table): Table
    {
        $gc = fn (mixed $col, ?string $fieldOverride = null): mixed => self::gateColumn($col, self::FIELD_PERMISSIONS_KEY, $fieldOverride);

        return $table
            ->columns([
                $gc(Tables\Columns\TextColumn::make('code')
                    ->searchable()),
                $gc(Tables\Columns\TextColumn::make('title')
                    ->searchable()),
                $gc(Tables\Columns\IconColumn::make('is_wills_series')
                    ->boolean()),
                $gc(Tables\Columns\IconColumn::make('is_active')
                    ->boolean()),
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
            'index' => Pages\ListSeries::route('/'),
            'create' => Pages\CreateSeries::route('/create'),
            'view' => Pages\ViewSeries::route('/{record}'),
            'edit' => Pages\EditSeries::route('/{record}/edit'),
        ];
    }
}
