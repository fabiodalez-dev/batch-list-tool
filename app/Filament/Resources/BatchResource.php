<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AppliesFieldPermissions;
use App\Filament\Resources\BatchResource\Pages;
use App\Filament\Support\SearchableSelects;
use App\Models\Batch;
use App\Models\Lookup\BatchType;
use App\Models\Repository;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class BatchResource extends Resource
{
    use AppliesFieldPermissions;

    /** RFQ §3.1.8 — see config/field_permissions.php */
    private const FIELD_PERMISSIONS_KEY = 'batch';

    protected static ?string $model = Batch::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static string|\UnitEnum|null $navigationGroup = 'Archive';

    protected static ?int $navigationSort = 11;

    protected static ?string $recordTitleAttribute = 'batch_number';

    public static function form(Schema $schema): Schema
    {
        $g = fn (Schemas\Components\Component $c): Schemas\Components\Component => self::gateField($c, self::FIELD_PERMISSIONS_KEY);

        // Layout rule (user mandate): root columns(1), atomic Sections use
        // ['default' => 1, 'md' => 2]; non-atomic content uses columnSpanFull.
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->schema([
                Section::make('Identification')
                    ->columns($twoCols)
                    ->schema([
                        $g(Forms\Components\TextInput::make('batch_number')
                            ->required()
                            ->numeric()
                            ->minValue(1)
                            // RFQ rule #1: batches 34 and 36 are forbidden (unused,
                            // never to be used); batch 33 is reserved for old MAV
                            // boxes and remains a valid number.
                            // The Batch model defines FORBIDDEN_NUMBERS — we use the
                            // model helper so the rule has a single source of truth
                            // (model const + form validator + DB CHECK on MySQL).
                            ->rule(function () {
                                return function (string $attribute, $value, \Closure $fail) {
                                    if ($value === null || $value === '') {
                                        return;
                                    }
                                    $candidate = new Batch(['batch_number' => (int) $value]);
                                    if ($candidate->isForbidden()) {
                                        $fail("Batch number {$value} is reserved/forbidden (RFQ rule).");
                                    }
                                };
                            })),
                        // RFQ §3.1.11 — expose the batch_types lookup as form
                        // options. batches.type retains its DB ENUM
                        // (MAIN_COLLECTION / NOTARY_ACCESSION) and is NOT given a
                        // strict model-level lookup guard, so existing data /
                        // tests are unaffected; the Select simply surfaces the
                        // editable controlled vocabulary in the UI.
                        $g(Forms\Components\Select::make('type')
                            ->options(fn (): array => BatchType::options())
                            ->required()),
                        $g(Forms\Components\TextInput::make('description')
                            ->maxLength(255)
                            ->columnSpanFull()),
                    ]),

                Section::make('Scope & status')
                    ->columns($twoCols)
                    ->schema([
                        // NOTE: tenant-scoping `disabled()` closure stays on the
                        // Select; the field-level gate adds a second layer. Both
                        // must allow for the input to be writable.
                        //
                        // Server-side search (no preload) — see SearchableSelects.
                        $g(SearchableSelects::repository(
                            'repository_id',
                            fn ($query) => $query->whereIn(
                                'id',
                                auth()->user()?->hasAnyRole(['super_admin', 'admin'])
                                    ? Repository::query()->pluck('id')->all()
                                    : (auth()->user()?->repositories()->pluck('repositories.id')->all() ?? [])
                            ),
                        )
                            ->label('Repository')
                            ->required()
                            ->default(fn () => auth()->user()?->default_repository_id)),
                        $g(Forms\Components\Toggle::make('is_active')
                            ->required()),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        // Layout rule (user mandate): root columns(1), atomic Sections on
        // ['default' => 1, 'md' => 2]; non-atomic content uses columnSpanFull.
        // Every FK gets a clickable URL to its Resource view.
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->components([
                Section::make('Identification')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('batch_number')
                            ->label('Batch number')
                            ->badge()
                            ->color('primary')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('type')
                            ->label('Type')
                            ->badge()
                            ->color('gray')
                            ->placeholder('—'),
                        TextEntry::make('description')
                            ->label('Description')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Scope & status')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('repository.code')
                            ->label('Repository')
                            ->badge()
                            ->color('info')
                            ->url(fn (?Batch $record): ?string => $record?->repository_id
                                ? route('filament.admin.resources.repositories.view', ['record' => $record->repository_id])
                                : null)
                            ->openUrlInNewTab(false)
                            ->placeholder('—'),
                        IconEntry::make('is_active')
                            ->label('Active')
                            ->boolean(),
                        TextEntry::make('boxes_count')
                            ->label('Boxes')
                            ->state(fn (?Batch $record): int => $record?->boxes()->count() ?? 0)
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('documents_count')
                            ->label('Documents')
                            ->state(fn (?Batch $record): int => $record?->documents()->count() ?? 0)
                            ->badge()
                            ->color('gray'),
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
        $gc = fn (mixed $col, ?string $fieldOverride = null): mixed => self::gateColumn($col, self::FIELD_PERMISSIONS_KEY, $fieldOverride);

        return $table
            ->columns([
                $gc(Tables\Columns\TextColumn::make('batch_number')
                    ->numeric()
                    ->sortable()),
                $gc(Tables\Columns\TextColumn::make('description')
                    ->searchable()),
                $gc(Tables\Columns\TextColumn::make('type')),
                $gc(Tables\Columns\TextColumn::make('repository.name')
                    ->numeric()
                    ->sortable(), 'repository_id'),
                $gc(Tables\Columns\IconColumn::make('is_active')
                    ->boolean()),
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
            'index' => Pages\ListBatches::route('/'),
            'create' => Pages\CreateBatch::route('/create'),
            'view' => Pages\ViewBatch::route('/{record}'),
            'edit' => Pages\EditBatch::route('/{record}/edit'),
        ];
    }
}
