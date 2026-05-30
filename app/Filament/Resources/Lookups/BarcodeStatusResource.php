<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups;

use App\Filament\Resources\Lookups\BarcodeStatusResource\Pages;
use App\Models\Lookup\BarcodeStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Table;

/**
 * RFQ §3.1.11 — Administrator CRUD for the barcode-status controlled vocabulary.
 * Supports add / rename / reorder / deactivate; gated to admin & super_admin.
 */
class BarcodeStatusResource extends Resource
{
    protected static ?string $model = BarcodeStatus::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-qr-code';

    protected static string|\UnitEnum|null $navigationGroup = 'Lookups';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Barcode Statuses';

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
            return true; // CLI / Shield discovery
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
                            ->unique(ignoreRecord: true),
                        Forms\Components\TextInput::make('label')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                        Forms\Components\Toggle::make('is_active')
                            ->default(true),
                    ]),
                Section::make('Metadata')
                    ->collapsed()
                    ->schema([
                        Forms\Components\Textarea::make('metadata')
                            ->rows(3)
                            ->helperText('Optional JSON metadata.')
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
                    ->label(fn (BarcodeStatus $record) => $record->is_active ? 'Deactivate' : 'Activate')
                    ->icon(fn (BarcodeStatus $record) => $record->is_active ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->color(fn (BarcodeStatus $record) => $record->is_active ? 'warning' : 'success')
                    ->action(fn (BarcodeStatus $record) => $record->update(['is_active' => ! $record->is_active])),
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
            'index' => Pages\ListBarcodeStatuses::route('/'),
            'create' => Pages\CreateBarcodeStatus::route('/create'),
            'edit' => Pages\EditBarcodeStatus::route('/{record}/edit'),
        ];
    }
}
