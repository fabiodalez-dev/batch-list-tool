<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups;

use App\Filament\Resources\Lookups\CurrentBoxTypeResource\Pages;
use App\Models\Lookup\CurrentBoxType;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * RFQ §3.1.11 — Administrator CRUD for the current-box-type controlled vocabulary.
 * `counts_as` controls disinfestation cycle weighting (e.g. Big Brown Box = 2).
 * Supports add / rename / reorder / deactivate; gated to admin & super_admin.
 */
class CurrentBoxTypeResource extends Resource
{
    protected static ?string $model = CurrentBoxType::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Lookups';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'Current Box Types';

    protected static ?string $recordTitleAttribute = 'label';

    // ── Access control ────────────────────────────────────────────────────────

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->hasAnyRole(['super_admin', 'admin']);
    }

    public static function canViewAny(): bool
    {
        return static::canAccess();
    }

    public static function shouldRegisterNavigation(): bool
    {
        if (auth()->guest()) {
            return true;
        }

        return static::canAccess();
    }

    // ── Form ──────────────────────────────────────────────────────────────────

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->schema([
                Section::make('Vocabulary entry')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        Forms\Components\TextInput::make('code')
                            ->required()
                            ->maxLength(32)
                            ->unique(ignoreRecord: true)
                            ->helperText('Machine key stored on records — renaming an in-use code does not update existing rows.'),
                        Forms\Components\TextInput::make('label')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                        Forms\Components\Toggle::make('is_active')
                            ->default(true),
                        Forms\Components\TextInput::make('counts_as')
                            ->numeric()
                            ->default(1)
                            ->minValue(1)
                            ->helperText('Disinfestation weighting (1 = standard; Big Brown Box = 2).'),
                    ]),
                Section::make('Metadata')
                    ->collapsed()
                    ->schema([
                        Forms\Components\Textarea::make('metadata')
                            ->rows(3)
                            ->helperText('Optional JSON metadata.')
                            ->rules(['nullable', 'json'])
                            ->rules(['nullable', 'json']) // C10 — reject malformed JSON so the array cast never breaks on read
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    // ── Table ─────────────────────────────────────────────────────────────────

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('label')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('sort_order')
                    ->sortable(),
                Tables\Columns\TextColumn::make('counts_as')
                    ->label('Counts as')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
            ])
            ->actions([
                Action::make('toggle_active')
                    ->label(fn (CurrentBoxType $record) => $record->is_active ? 'Deactivate' : 'Activate')
                    ->icon(fn (CurrentBoxType $record) => $record->is_active ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->color(fn (CurrentBoxType $record) => $record->is_active ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (CurrentBoxType $record) => $record->is_active
                        ? 'Deactivating this value hides it from new records app-wide. Existing records that already use it are unaffected.'
                        : 'Re-activating this value makes it available again for new records.')
                    ->action(fn (CurrentBoxType $record) => $record->update(['is_active' => ! $record->is_active])),
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    // ── Pages ─────────────────────────────────────────────────────────────────

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCurrentBoxTypes::route('/'),
            'create' => Pages\CreateCurrentBoxType::route('/create'),
            'edit' => Pages\EditCurrentBoxType::route('/{record}/edit'),
        ];
    }
}
