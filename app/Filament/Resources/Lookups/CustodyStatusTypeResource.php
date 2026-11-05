<?php

declare(strict_types=1);

namespace App\Filament\Resources\Lookups;

use App\Filament\Resources\Lookups\CustodyStatusTypeResource\Pages;
use App\Filament\Support\CreatorColumn;
use App\Filament\Support\ListRules;
use App\Filament\Support\MatchQueryBuilder;
use App\Models\Lookup\CustodyStatusType;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\LazyCollection;

/**
 * Client 2026-11-05: "Add Custody Status Types. A new page to be able to
 * add/edit/view/delete." Gated to admin & super_admin, like the other
 * vocabularies. What must not happen to a status lives on the model; here it is
 * only hidden or explained.
 */
class CustodyStatusTypeResource extends Resource
{
    protected static ?string $model = CustodyStatusType::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box-arrow-down';

    protected static string|\UnitEnum|null $navigationGroup = 'Classifications';

    protected static ?int $navigationSort = 85;

    protected static ?string $navigationLabel = 'Custody Status Types';

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
                            ->disabled(fn (?CustodyStatusType $record): bool => $record?->codeIsLocked() ?? false)
                            ->helperText('Machine key stored on documents — it cannot change while documents hold it.'),
                        Forms\Components\TextInput::make('label')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('sort_order')
                            ->numeric()
                            ->default(0)
                            ->minValue(0)
                            ->helperText('Controls display order in dropdowns (lower numbers first).'),
                        Forms\Components\Toggle::make('is_active')
                            ->default(true)
                            ->disabled(fn (?CustodyStatusType $record): bool => $record?->isProtected() ?? false),
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
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('sort_order')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                CreatorColumn::make()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->filters([
                MatchQueryBuilder::make()
                    ->constraints(ListRules::lookup()),
            ])
            ->checkIfRecordIsSelectableUsing(fn (CustodyStatusType $record): bool => ! $record->isProtected())
            ->actions([
                Action::make('toggle_active')
                    ->label(fn (CustodyStatusType $record) => $record->is_active ? 'Deactivate' : 'Activate')
                    ->icon(fn (CustodyStatusType $record) => $record->is_active ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->color(fn (CustodyStatusType $record) => $record->is_active ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (CustodyStatusType $record) => $record->is_active
                        ? 'Deactivating this value hides it from new records app-wide. Documents that already have it are unaffected.'
                        : 'Re-activating this value makes it available again for new records.')
                    ->hidden(fn (CustodyStatusType $record): bool => $record->isProtected())
                    ->action(fn (CustodyStatusType $record) => $record->update(['is_active' => ! $record->is_active])),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    // A status documents still have is kept and reported, as the
                    // Authorities bulk delete does for a creator with documents.
                    DeleteBulkAction::make()
                        ->action(function (EloquentCollection|LazyCollection $records): void {
                            $deleted = 0;
                            $refused = [];

                            foreach ($records as $record) {
                                /** @var CustodyStatusType $record */
                                $reason = $record->deleteBlockedBecause();
                                if ($reason !== null) {
                                    $refused[] = $reason;

                                    continue;
                                }
                                $record->delete();
                                $deleted++;
                            }

                            $notification = Notification::make()->title("Deleted {$deleted} custody status type(s).");

                            ($refused === []
                                ? $notification->success()
                                : $notification->warning()->body(implode(' ', $refused)))->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    // ── Pages ─────────────────────────────────────────────────────────────────

    public static function getRelations(): array
    {
        return [];
    }

    public static function getEloquentQuery(): Builder
    {
        // CreatorColumn resolves the inputter from the first 'created' audit;
        // eager-load it (with its user) so the table does not run one audit
        // query per row.
        return parent::getEloquentQuery()
            ->with(['audits' => fn ($q) => $q->oldest('id')->with('user')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustodyStatusTypes::route('/'),
            'create' => Pages\CreateCustodyStatusType::route('/create'),
            'view' => Pages\ViewCustodyStatusType::route('/{record}'),
            'edit' => Pages\EditCustodyStatusType::route('/{record}/edit'),
        ];
    }
}
