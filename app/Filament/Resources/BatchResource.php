<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BatchResource\Pages;
use App\Models\Batch;
use App\Models\Repository;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BatchResource extends Resource
{
    protected static ?string $model = Batch::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationGroup = 'Archive';

    protected static ?int $navigationSort = 11;

    protected static ?string $recordTitleAttribute = 'batch_number';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('batch_number')
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
                    }),
                Forms\Components\TextInput::make('description')
                    ->maxLength(255),
                Forms\Components\TextInput::make('type')
                    ->required(),
                Forms\Components\Select::make('repository_id')
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
                    ->disabled(fn () => ! auth()->user()?->hasAnyRole(['super_admin', 'admin']))
                    ->dehydrated()
                    ->searchable()->preload(),
                Forms\Components\Toggle::make('is_active')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('batch_number')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('description')
                    ->searchable(),
                Tables\Columns\TextColumn::make('type'),
                Tables\Columns\TextColumn::make('repository.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
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
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
