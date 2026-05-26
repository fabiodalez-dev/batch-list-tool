<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AccessionResource\Pages;
use App\Filament\Support\SearchableSelects;
use App\Models\Accession;
use App\Models\Repository;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class AccessionResource extends Resource
{
    protected static ?string $model = Accession::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-plus';

    protected static string|\UnitEnum|null $navigationGroup = 'Archive';

    protected static ?int $navigationSort = 12;

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Forms\Components\TextInput::make('code')
                    ->required()
                    ->maxLength(64),
                Forms\Components\DatePicker::make('accession_date'),
                // Authority dropdown: 808 rows in production → server-side
                // search ("Abela" → top 50 matches by surname/identifier).
                SearchableSelects::authority('authority_id', 'authority'),
                // Batch dropdown: showing `Batch <N> — <type>` so operators
                // can distinguish RAS_BATCH/NOTARY_ACCESSION at a glance.
                SearchableSelects::batch('batch_id', 'batch'),
                // Repository dropdown: scoped to the user's assigned tenants.
                // Same tenant-scoping closure as before; only the search/label
                // wiring is new.
                SearchableSelects::repository(
                    'repository_id',
                    fn ($query) => $query->whereIn(
                        'id',
                        auth()->user()?->hasAnyRole(['super_admin', 'admin'])
                            ? Repository::query()->pluck('id')->all()
                            : (auth()->user()?->repositories()->pluck('repositories.id')->all() ?? [])
                    ),
                )
                    ->label('Repository')
                    ->required()
                    ->default(fn () => auth()->user()?->default_repository_id)
                    ->disabled(fn () => ! auth()->user()?->hasAnyRole(['super_admin', 'admin']))
                    ->dehydrated(),
                Forms\Components\Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable(),
                Tables\Columns\TextColumn::make('accession_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('authority.surname')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('batch.batch_number')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('repository.name')
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
            'index' => Pages\ListAccessions::route('/'),
            'create' => Pages\CreateAccession::route('/create'),
            'view' => Pages\ViewAccession::route('/{record}'),
            'edit' => Pages\EditAccession::route('/{record}/edit'),
        ];
    }
}
