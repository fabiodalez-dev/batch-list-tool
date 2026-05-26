<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DocumentFlagResource\Pages;
use App\Filament\Resources\DocumentResource\RelationManagers\FlagsRelationManager;
use App\Filament\Support\SearchableSelects;
use App\Models\DocumentFlag;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Standalone "alerts dashboard" — every open issue flag across every
 * document, in one place (RFQ §3.1.12).
 *
 * The Document → flags RelationManager is the *per-document* timeline; this
 * resource is the *cross-cutting* operators' inbox: "what does my team need
 * to act on right now?", filterable by severity, type, repository, and
 * date range, and supporting bulk resolve / bulk dismiss for batch triage.
 */
class DocumentFlagResource extends Resource
{
    protected static ?string $model = DocumentFlag::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-flag';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 85;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $modelLabel = 'document flag';

    protected static ?string $pluralModelLabel = 'document flags';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Schemas\Components\Section::make('What')
                ->columns(2)
                ->schema([
                    // 3,000+ documents → server-side autocomplete.
                    SearchableSelects::document('document_id')
                        ->required()
                        ->columnSpanFull(),

                    Forms\Components\Select::make('type')
                        ->options(FlagsRelationManager::typeOptions())
                        ->required()
                        ->native(false)
                        ->searchable(),

                    Forms\Components\Select::make('severity')
                        ->options(FlagsRelationManager::severityOptions())
                        ->default('warning')
                        ->required()
                        ->native(false),

                    Forms\Components\TextInput::make('title')
                        ->required()
                        ->maxLength(200)
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('description')
                        ->rows(3)
                        ->maxLength(5000)
                        ->columnSpanFull(),
                ]),

            Schemas\Components\Section::make('Workflow')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('status')
                        ->options(FlagsRelationManager::statusOptions())
                        ->default('open')
                        ->required()
                        ->native(false),

                    Forms\Components\DateTimePicker::make('flagged_at')
                        ->default(now())
                        ->required(),

                    Forms\Components\Textarea::make('resolution_notes')
                        ->rows(2)
                        ->maxLength(5000)
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => in_array(
                            $get('status'),
                            ['resolved', 'dismissed'],
                            true,
                        )),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('flagged_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('severity')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'critical' => 'danger',
                        'warning' => 'warning',
                        'info' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => FlagsRelationManager::typeLabel($state))
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('document.identifier')
                    ->label('Document')
                    ->url(fn (DocumentFlag $record): ?string => $record->document_id
                        ? DocumentResource::getUrl('view', ['record' => $record->document_id])
                        : null)
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('title')
                    ->limit(60)
                    ->wrap()
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'open' => 'warning',
                        'acknowledged' => 'info',
                        'resolved' => 'success',
                        'dismissed' => 'gray',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('repository.code')
                    ->label('Repo')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('flaggedBy.name')
                    ->label('Flagged by')
                    ->default('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('flagged_at')
                    ->label('Flagged')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('resolved_at')
                    ->label('Resolved')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    // Default = open. Operators almost always want the inbox
                    // view, not the archive.
                    ->options(FlagsRelationManager::statusOptions() + ['all' => 'All'])
                    ->default('open')
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? 'open';

                        return match ($value) {
                            'open' => $query->open(),
                            'all', null, '' => $query,
                            default => $query->where('status', $value),
                        };
                    }),

                SelectFilter::make('severity')
                    ->options(FlagsRelationManager::severityOptions())
                    ->multiple(),

                SelectFilter::make('type')
                    ->options(FlagsRelationManager::typeOptions())
                    ->multiple(),

                SelectFilter::make('repository_id')
                    ->label('Repository')
                    ->relationship('repository', 'code'),

                Filter::make('flagged_at_range')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('From'),
                        Forms\Components\DatePicker::make('to')->label('To'),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        return $q
                            ->when(
                                $data['from'] ?? null,
                                fn ($q, $v) => $q->whereDate('flagged_at', '>=', $v),
                            )
                            ->when(
                                $data['to'] ?? null,
                                fn ($q, $v) => $q->whereDate('flagged_at', '<=', $v),
                            );
                    }),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkResolve')
                        ->label('Mark resolved')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->form([
                            Forms\Components\Textarea::make('resolution_notes')
                                ->label('Resolution notes')
                                ->rows(3)
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $records->each(fn (DocumentFlag $r) => $r->markResolved(
                                auth()->user(),
                                $data['resolution_notes'] ?? null,
                            ));
                        }),

                    BulkAction::make('bulkDismiss')
                        ->label('Dismiss')
                        ->icon('heroicon-o-x-circle')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->form([
                            Forms\Components\Textarea::make('resolution_notes')
                                ->label('Reason')
                                ->rows(3)
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $records->each(fn (DocumentFlag $r) => $r->markDismissed(
                                auth()->user(),
                                $data['resolution_notes'] ?? null,
                            ));
                        }),

                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** Live badge — number of open flags in the user's tenant scope. */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->open()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $critical = static::getEloquentQuery()
            ->open()
            ->where('severity', 'critical')
            ->count();

        return $critical > 0 ? 'danger' : 'warning';
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocumentFlags::route('/'),
            'create' => Pages\CreateDocumentFlag::route('/create'),
            'view' => Pages\ViewDocumentFlag::route('/{record}'),
            'edit' => Pages\EditDocumentFlag::route('/{record}/edit'),
        ];
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'type', 'document.identifier'];
    }
}
