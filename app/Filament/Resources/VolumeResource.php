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
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
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
        // Layout rule (user mandate): root columns(1) → full-width Sections;
        // atomic-field Sections use ['default' => 1, 'md' => 2]; non-atomic
        // children (Textarea) → columnSpanFull.
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->schema([
                Section::make('Identification')
                    ->columns($twoCols)
                    ->schema([
                        // Searchable autocomplete (no preload): the documents table has
                        // 3,000+ rows in production, so a `<select>` with that many
                        // options is unusable. See App\Filament\Support\SearchableSelects.
                        SearchableSelects::documentVia('document_id', 'document')
                            ->required()
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('volume_number')
                            ->required()
                            ->maxLength(32)
                            ->columnSpanFull(),
                    ]),

                Section::make('Dates')
                    ->columns($twoCols)
                    ->schema([
                        Forms\Components\DatePicker::make('dates_start'),
                        Forms\Components\DatePicker::make('dates_end'),
                    ]),

                Section::make('Notes')
                    ->columns(1)
                    ->collapsed()
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->rows(3)
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
                Section::make('Identification')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('document.identifier')
                            ->label('Document')
                            ->badge()
                            ->color('primary')
                            ->url(fn (?Volume $record): ?string => $record?->document_id
                                ? route('filament.admin.resources.documents.view', ['record' => $record->document_id])
                                : null)
                            ->openUrlInNewTab(false)
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('volume_number')
                            ->label('Volume number')
                            ->copyable()
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Dates')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('dates_start')
                            ->label('From')
                            ->date()
                            ->placeholder('—'),
                        TextEntry::make('dates_end')
                            ->label('To')
                            ->date()
                            ->placeholder('—'),
                    ]),

                Section::make('Notes')
                    ->columns(1)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('notes')
                            ->hiddenLabel()
                            ->prose()
                            ->placeholder('No notes.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Audit info')
                    ->columns($twoCols)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('created_at')->dateTime()->label('Created'),
                        TextEntry::make('updated_at')->dateTime()->label('Updated'),
                        TextEntry::make('deleted_at')->dateTime()->label('Trashed')->placeholder('—')->columnSpanFull(),
                    ])
                    ->visible(fn (): bool => (bool) auth()->user()?->hasRole('super_admin')),
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
