<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups;

use App\Filament\Resources\Lookups\BatchTypeResource\Pages;
use App\Filament\Support\CreatorColumn;
use App\Models\Lookup\BatchType;
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
 * RFQ §3.1.11 — Administrator CRUD for the batch-type controlled vocabulary.
 * Supports add / rename / reorder / deactivate; gated to admin & super_admin.
 */
class BatchTypeResource extends Resource
{
    protected static ?string $model = BatchType::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Lookups';

    protected static ?int $navigationSort = 60;

    protected static ?string $navigationLabel = 'Accession Types';

    // NAF Feedback-1 comment #2: the underlying model/table is still `BatchType`
    // for backward-compatibility, but every user-facing label (page heading,
    // breadcrumb, create button) must read "Accession Type(s)". Without these
    // Filament derives the heading from the model class → "Batch Types".
    protected static ?string $modelLabel = 'Accession Type';

    protected static ?string $pluralModelLabel = 'Accession Types';

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
                            ->label('Identifier')
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
                    ->label('Identifier')
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
                // NAF Feedback-1 comment #4 — show who created the record.
                CreatorColumn::make(),
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
                    ->label(fn (BatchType $record) => $record->is_active ? 'Deactivate' : 'Activate')
                    ->icon(fn (BatchType $record) => $record->is_active ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->color(fn (BatchType $record) => $record->is_active ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (BatchType $record) => $record->is_active
                        ? 'Deactivating this value hides it from new records app-wide. Existing records that already use it are unaffected.'
                        : 'Re-activating this value makes it available again for new records.')
                    ->action(fn (BatchType $record) => $record->update(['is_active' => ! $record->is_active])),
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
            'index' => Pages\ListBatchTypes::route('/'),
            'create' => Pages\CreateBatchType::route('/create'),
            'edit' => Pages\EditBatchType::route('/{record}/edit'),
        ];
    }
}
