<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AppliesFieldPermissions;
use App\Filament\Resources\BatchResource\Pages;
use App\Models\Batch;
use App\Models\Repository;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas;
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

        return $schema
            ->schema([
                $g(Forms\Components\TextInput::make('batch_number')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    // RFQ rule #1: batches 33, 34, 36 are reserved/forbidden.
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
                $g(Forms\Components\TextInput::make('description')
                    ->maxLength(255)),
                $g(Forms\Components\TextInput::make('type')
                    ->required()),
                // NOTE: tenant-scoping `disabled()` closure stays on the
                // Select; the field-level gate adds a second layer. Both
                // must allow for the input to be writable.
                $g(Forms\Components\Select::make('repository_id')
                    ->label('Repository')
                    ->relationship(
                        'repository',
                        'name',
                        fn ($query) => $query->whereIn(
                            'id',
                            auth()->user()?->hasAnyRole(['super_admin', 'admin'])
                                ? Repository::query()->pluck('id')->all()
                                : (auth()->user()?->repositories()->pluck('repositories.id')->all() ?? [])
                        )
                    )
                    ->required()
                    ->default(fn () => auth()->user()?->default_repository_id)
                    ->searchable()->preload()),
                $g(Forms\Components\Toggle::make('is_active')
                    ->required()),
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
