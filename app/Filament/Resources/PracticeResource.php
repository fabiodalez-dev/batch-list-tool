<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PracticeResource\Pages;
use App\Filament\Support\CreatorColumn;
use App\Models\Practice;
use App\Models\Repository;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * RFQ §3.1.11 — manage the canonical list of `practice` values
 * (NTG, PrivatePractice, mixed, etc.).
 *
 * D4 (Feedback1 Wave D) — identifier and repository_id added per client
 * request: "Should an identifier be uniquely created for each practice and
 * then used during importation?" and "Different Practices may be associated
 * with different Repositories."
 */
class PracticeResource extends Resource
{
    protected static ?string $model = Practice::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-briefcase';

    protected static string|\UnitEnum|null $navigationGroup = 'Reference';

    protected static ?int $navigationSort = 60;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                // D4 — identifier for import resolution (unique, optional).
                Forms\Components\TextInput::make('identifier')
                    ->label('Identifier')
                    ->maxLength(64)
                    ->nullable()
                    ->unique(ignoreRecord: true)
                    ->helperText('Optional unique code used during bulk import to resolve this practice by key.'),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(100)
                    ->unique(ignoreRecord: true),
                Forms\Components\Textarea::make('description')
                    ->maxLength(500)
                    ->rows(3),
                Forms\Components\Toggle::make('is_active')
                    ->default(true),
                // D4 — optional repository scope (NULL = global).
                Forms\Components\Select::make('repository_id')
                    ->label('Repository')
                    ->options(fn () => Repository::query()->orderBy('code')->pluck('name', 'id')->all())
                    ->searchable()
                    ->nullable()
                    ->helperText('Leave empty for a global practice (visible to every repository).'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Feedback1 Wave A (A6) — drag-and-drop column reordering.
            ->reorderableColumns()
            ->columns([
                // D4 — identifier column, toggleable (off by default to keep
                // the default grid compact for day-to-day use).
                Tables\Columns\TextColumn::make('identifier')
                    ->label('Identifier')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('description')->limit(60)->sortable()->toggleable(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->sortable()->toggleable(),
                // D4 — repository column, toggleable (off by default).
                Tables\Columns\TextColumn::make('repository.code')
                    ->label('Repository')
                    ->badge()
                    ->color('gray')
                    ->placeholder('GLOBAL')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                    BulkAction::make('deactivate')
                        ->label('Deactivate selected')
                        ->icon('heroicon-o-no-symbol')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalDescription('Selected vocabulary entries will be hidden from new Document forms but historical references stay readable.')
                        ->action(fn ($records) => Practice::query()
                            ->whereKey($records->modelKeys())
                            ->update(['is_active' => false])),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['audits' => fn ($q) => $q->where('event', 'created')->with('user')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPractices::route('/'),
            'create' => Pages\CreatePractice::route('/create'),
            'edit' => Pages\EditPractice::route('/{record}/edit'),
        ];
    }
}
