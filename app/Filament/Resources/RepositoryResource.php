<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RepositoryResource\Pages;
use App\Filament\Resources\RepositoryResource\RelationManagers;
use App\Filament\Support\CompactLayout;
use App\Filament\Support\CreatorColumn;
use App\Models\Repository;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RepositoryResource extends Resource
{
    protected static ?string $model = Repository::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static string|\UnitEnum|null $navigationGroup = 'Records';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        // Client 2026-10-30: one compact section, laid out like the view so a
        // field is found in the same place on both.
        return $schema
            ->columns(1)
            ->schema([
                Section::make(CompactLayout::DETAILS)
                    ->columns(CompactLayout::GRID)
                    ->schema([
                        Forms\Components\TextInput::make('code')
                            ->required()
                            ->maxLength(32),
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(CompactLayout::HALF),
                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->required(),
                        Forms\Components\Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        // Client 2026-10-30: Identification, Description and Counts held very
        // few fields each; they are one compact section now, with Audit info
        // apart and closed.
        return $schema
            ->columns(1)
            ->components([
                Section::make(CompactLayout::DETAILS)
                    ->columns(CompactLayout::GRID)
                    ->schema([
                        TextEntry::make('code')
                            ->label('Code')
                            ->badge()
                            ->color('primary')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('name')
                            ->label('Name')
                            ->placeholder('—')
                            ->columnSpan(CompactLayout::HALF),
                        IconEntry::make('is_active')
                            ->label('Active')
                            ->boolean(),
                        TextEntry::make('batches_count')
                            ->label('Batches')
                            ->state(fn (?Repository $record): int => $record?->batches()->count() ?? 0)
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('documents_count')
                            ->label('Documents')
                            ->state(fn (?Repository $record): int => $record?->documents()->count() ?? 0)
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('accessions_count')
                            ->label('Accessions')
                            ->state(fn (?Repository $record): int => $record?->accessions()->count() ?? 0)
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('users_count')
                            ->label('Assigned users')
                            ->state(fn (?Repository $record): int => $record?->users()->count() ?? 0)
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('description')
                            ->label('Description')
                            ->prose()
                            ->placeholder('No description.')
                            ->columnSpanFull(),
                    ]),

                CompactLayout::auditInfolist(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
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
                CreatorColumn::make(),
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'audits' => fn ($q) => $q->oldest('id')->with('user'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\UsersRelationManager::class,
            RelationManagers\CustomFieldsRelationManager::class,
            RelationManagers\ColumnNamesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRepositories::route('/'),
            'create' => Pages\CreateRepository::route('/create'),
            'view' => Pages\ViewRepository::route('/{record}'),
            'edit' => Pages\EditRepository::route('/{record}/edit'),
        ];
    }
}
