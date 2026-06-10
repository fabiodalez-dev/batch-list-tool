<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentTypeResource\Pages;
use App\Models\DocumentType;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * RFQ §3.1.11 — manage the canonical list of `document_type` values.
 */
class DocumentTypeResource extends Resource
{
    protected static ?string $model = DocumentType::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|\UnitEnum|null $navigationGroup = 'Reference';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true),
                // Wave D2 — optional machine-readable identifier (distinct from the
                // human-readable name). Unique where non-NULL; NULL for legacy entries.
                Forms\Components\TextInput::make('identifier')
                    ->label('Identifier')
                    ->maxLength(64)
                    ->unique(ignoreRecord: true)
                    ->nullable()
                    ->helperText('Optional short code, e.g. "REG" or "ORIG". Must be unique if set.'),
                Forms\Components\Textarea::make('description')
                    ->maxLength(500)
                    ->rows(3),
                Forms\Components\Toggle::make('is_active')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Feedback1 Wave A (A6) — drag-and-drop column reordering.
            ->reorderableColumns()
            ->columns([
                Tables\Columns\TextColumn::make('identifier')
                    ->label('Identifier')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('description')->limit(60)->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->sortable(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime('Y-m-d H:i')->sortable()->toggleable(),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Reference vocabulary — never hard-delete. Documents in
                    // production may still reference these names by string;
                    // deactivation hides them from new picks while keeping
                    // historical references readable.
                    BulkAction::make('deactivate')
                        ->label('Deactivate selected')
                        ->icon('heroicon-o-no-symbol')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalDescription('Selected vocabulary entries will be hidden from new Document forms but historical documents that reference them stay readable.')
                        ->action(fn ($records) => DocumentType::query()
                            ->whereKey($records->modelKeys())
                            ->update(['is_active' => false])),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocumentTypes::route('/'),
            'create' => Pages\CreateDocumentType::route('/create'),
            'edit' => Pages\EditDocumentType::route('/{record}/edit'),
        ];
    }
}
