<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BoxMovementResource\Pages;
use App\Filament\Support\CreatorColumn;
use App\Filament\Support\SearchableSelects;
use App\Models\BoxMovement;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BoxMovementResource extends Resource
{
    protected static ?string $model = BoxMovement::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static string|\UnitEnum|null $navigationGroup = 'Archive';

    protected static ?int $navigationSort = 15;

    public static function form(Schema $schema): Schema
    {
        // Layout rule (user mandate): root columns(1) → full-width Sections;
        // atomic-field Sections use ['default' => 1, 'md' => 2]; non-atomic
        // children → columnSpanFull.
        $twoCols = ['default' => 1, 'md' => 2];

        // All four FK Selects below use server-side autocomplete with
        // `preload(false)` — the documents (3,000+) and boxes (600+) tables
        // are too large to render as a flat `<select>` on the production
        // dataset. See App\Filament\Support\SearchableSelects.
        return $schema
            ->columns(1)
            ->schema([
                Section::make('Document')
                    ->columns(1)
                    ->schema([
                        SearchableSelects::documentVia('document_id', 'document')
                            ->required()
                            ->columnSpanFull(),
                    ]),

                Section::make('Movement')
                    ->columns($twoCols)
                    ->schema([
                        SearchableSelects::box('from_box_id', 'fromBox')
                            ->label('From box'),
                        SearchableSelects::box('to_box_id', 'toBox')
                            ->label('To box')
                            ->helperText('If the target box does not exist yet, create it first in Boxes.'),
                        Forms\Components\DateTimePicker::make('movement_date')
                            ->required(),
                        SearchableSelects::user('user_id', 'user'),
                        Forms\Components\TextInput::make('reason')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        // Layout rule (user mandate): root columns(1) → full-width Sections;
        // atomic entries on ['default' => 1, 'md' => 2]; non-atomic content
        // → columnSpanFull. Every FK gets a ->url() to its Resource view.
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->components([
                Section::make('Document')
                    ->columns(1)
                    ->schema([
                        TextEntry::make('document.identifier')
                            ->label('Document')
                            ->badge()
                            ->color('primary')
                            ->url(fn (?BoxMovement $record): ?string => $record?->document_id
                                ? route('filament.admin.resources.documents.view', ['record' => $record->document_id])
                                : null)
                            ->openUrlInNewTab(false)
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Movement')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('fromBox.box_number')
                            ->label('From box')
                            ->badge()
                            ->color('gray')
                            ->url(fn (?BoxMovement $record): ?string => $record?->from_box_id
                                ? route('filament.admin.resources.boxes.view', ['record' => $record->from_box_id])
                                : null)
                            ->openUrlInNewTab(false)
                            ->placeholder('—'),
                        TextEntry::make('toBox.box_number')
                            ->label('To box')
                            ->badge()
                            ->color('success')
                            ->url(fn (?BoxMovement $record): ?string => $record?->to_box_id
                                ? route('filament.admin.resources.boxes.view', ['record' => $record->to_box_id])
                                : null)
                            ->openUrlInNewTab(false)
                            ->placeholder('—'),
                        TextEntry::make('movement_date')
                            ->label('When')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('user.name')
                            ->label('By user')
                            ->placeholder('—'),
                        TextEntry::make('reason')
                            ->label('Reason')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Audit info')
                    ->columns($twoCols)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('created_at')->dateTime()->label('Created'),
                        TextEntry::make('updated_at')->dateTime()->label('Updated'),
                    ])
                    ->visible(fn (): bool => (bool) auth()->user()?->hasRole('super_admin')),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('document.identifier')
                    ->label('Document')
                    ->sortable(),
                Tables\Columns\TextColumn::make('fromBox.box_number')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('toBox.box_number')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('movement_date')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('reason')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('user.name')
                    ->numeric()
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
            ->with(['audits' => fn ($q) => $q->where('event', 'created')->with('user')]);
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
