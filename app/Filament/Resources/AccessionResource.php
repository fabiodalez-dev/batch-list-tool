<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AccessionResource\Pages;
use App\Models\Accession;
use App\Models\Repository;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AccessionResource extends Resource
{
    protected static ?string $model = Accession::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-plus';

    protected static ?string $navigationGroup = 'Archive';

    protected static ?int $navigationSort = 12;

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('code')
                    ->required()
                    ->maxLength(64),
                Forms\Components\DatePicker::make('accession_date'),
                Forms\Components\Select::make('authority.surname')
                    ->relationship('authority', 'surname'),
                Forms\Components\Select::make('batch.batch_number')
                    ->relationship('batch', 'batch_number'),
                Forms\Components\Select::make('repository_id')
                    ->label('Repository')
                    ->relationship(
                        'repository',
                        'name',
                        fn ($query) => $query->whereIn(
                            'id',
                            auth()->user()?->hasAnyRole(['super_admin', 'admin'])
                                ? \App\Models\Repository::query()->pluck('id')->all()
                                : (auth()->user()?->repositories()->pluck('repositories.id')->all() ?? [])
                        )
                    )
                    ->required()
                    ->default(fn () => auth()->user()?->default_repository_id)
                    ->disabled(fn () => ! auth()->user()?->hasAnyRole(['super_admin', 'admin']))
                    ->dehydrated()
                    ->searchable()->preload(),
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
            'index' => Pages\ListAccessions::route('/'),
            'create' => Pages\CreateAccession::route('/create'),
            'view' => Pages\ViewAccession::route('/{record}'),
            'edit' => Pages\EditAccession::route('/{record}/edit'),
        ];
    }
}
