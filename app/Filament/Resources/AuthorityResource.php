<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AppliesFieldPermissions;
use App\Filament\Resources\AuthorityResource\Pages;
use App\Models\Authority;
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

class AuthorityResource extends Resource
{
    use AppliesFieldPermissions;

    /** RFQ §3.1.8 — see config/field_permissions.php */
    private const FIELD_PERMISSIONS_KEY = 'authority';

    protected static ?string $model = Authority::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-circle';

    protected static string|\UnitEnum|null $navigationGroup = 'Reference';

    protected static ?int $navigationSort = 21;

    protected static ?string $recordTitleAttribute = 'surname';

    public static function form(Schema $schema): Schema
    {
        $g = fn (Schemas\Components\Component $c): Schemas\Components\Component => self::gateField($c, self::FIELD_PERMISSIONS_KEY);

        return $schema
            ->schema([
                $g(Forms\Components\TextInput::make('identifier')
                    ->required()
                    ->maxLength(32)),
                $g(Forms\Components\TextInput::make('alternative_identifier')
                    ->maxLength(32)),
                $g(Forms\Components\TextInput::make('surname')
                    ->required()
                    ->maxLength(255)),
                $g(Forms\Components\TextInput::make('given_names')
                    ->maxLength(255)),
                $g(Forms\Components\TextInput::make('entity_type')
                    ->required()
                    ->maxLength(16)
                    ->default('PERSON')),
                $g(Forms\Components\TextInput::make('practice_dates_start')
                    ->numeric()),
                $g(Forms\Components\TextInput::make('practice_dates_end')
                    ->numeric()),
                $g(Forms\Components\Textarea::make('notes')
                    ->columnSpanFull()),
            ]);
    }

    public static function table(Table $table): Table
    {
        $gc = fn (mixed $col, ?string $fieldOverride = null): mixed => self::gateColumn($col, self::FIELD_PERMISSIONS_KEY, $fieldOverride);

        return $table
            ->columns([
                $gc(Tables\Columns\TextColumn::make('identifier')
                    ->searchable()),
                $gc(Tables\Columns\TextColumn::make('alternative_identifier')
                    ->searchable()),
                $gc(Tables\Columns\TextColumn::make('surname')
                    ->searchable()),
                $gc(Tables\Columns\TextColumn::make('given_names')
                    ->searchable()),
                $gc(Tables\Columns\TextColumn::make('entity_type')
                    ->searchable()),
                $gc(Tables\Columns\TextColumn::make('practice_dates_start')
                    ->numeric()
                    ->sortable()),
                $gc(Tables\Columns\TextColumn::make('practice_dates_end')
                    ->numeric()
                    ->sortable()),
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
            'index' => Pages\ListAuthorities::route('/'),
            'create' => Pages\CreateAuthority::route('/create'),
            'view' => Pages\ViewAuthority::route('/{record}'),
            'edit' => Pages\EditAuthority::route('/{record}/edit'),
        ];
    }
}
