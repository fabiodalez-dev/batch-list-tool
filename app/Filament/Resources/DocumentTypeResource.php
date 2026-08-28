<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentTypeResource\Pages;
use App\Filament\Support\CreatorColumn;
use App\Models\DocumentType;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * RFQ §3.1.11 — manage the canonical list of `document_type` values.
 */
class DocumentTypeResource extends Resource
{
    protected static ?string $model = DocumentType::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|\UnitEnum|null $navigationGroup = 'Classifications';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Next consecutive auto-suggested identifier in the `DT00001` format.
     *
     * Scans every existing row (the model carries no global scopes) for the
     * largest identifier matching /^DT(\d+)$/, increments its numeric part and
     * zero-pads to five digits. Returns 'DT00001' when none exist yet.
     */
    public static function nextIdentifier(): string
    {
        $max = 0;

        // Narrow with a portable LIKE (REGEXP is MySQL-only — tests run on
        // SQLite) then validate the exact `DT<digits>` shape in PHP so codes
        // like "DTX" or "DT12A" never leak into the max.
        DocumentType::query()
            ->whereNotNull('identifier')
            ->where('identifier', 'like', 'DT%')
            ->pluck('identifier')
            ->each(function (string $identifier) use (&$max): void {
                if (preg_match('/^DT(\d+)$/', $identifier, $m) === 1) {
                    $max = max($max, (int) $m[1]);
                }
            });

        return 'DT' . str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                // Charlene UX — Identifier is now the primary key users read on
                // records, so it comes first and is required. On create it is
                // pre-filled with the next consecutive DT##### code (still
                // user-editable and uniqueness-validated).
                Forms\Components\TextInput::make('identifier')
                    ->label('Identifier')
                    ->required()
                    ->maxLength(64)
                    ->unique(ignoreRecord: true)
                    ->default(fn () => static::nextIdentifier())
                    ->helperText('Short code in the form DT00001. Pre-filled with the next free code; must be unique.'),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true),
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
                Tables\Columns\TextColumn::make('description')->limit(60)->toggleable()->sortable(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime('Y-m-d H:i')->sortable()->toggleable(),
                CreatorColumn::make(),
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
                    // Charlene UX — allow permanent bulk removal alongside the
                    // soft "deactivate" path. Visibility follows the default
                    // delete policy (only users who can delete see it).
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocumentTypes::route('/'),
            'create' => Pages\CreateDocumentType::route('/create'),
            'edit' => Pages\EditDocumentType::route('/{record}/edit'),
        ];
    }
}
