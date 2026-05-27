<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Pages\ImportWizard;
use App\Filament\Resources\ImportProfileResource\Pages;
use App\Models\ImportProfile;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * RFQ §3.1.3 — Manage user-saved Import Wizard column-mapping profiles.
 *
 * Profiles are produced by ticking "Save as profile" on the final step of
 * the {@see ImportWizard}. This Resource is the
 * management surface: list / view / rename / share / delete. The actual
 * `column_map` is intentionally NOT editable from this form — editing
 * blind would yield a profile that no longer matches any real
 * spreadsheet. Re-running the Wizard and saving a fresh profile is the
 * supported workflow for tweaking a mapping.
 */
class ImportProfileResource extends Resource
{
    protected static ?string $model = ImportProfile::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-square-3-stack-3d';

    protected static string|\UnitEnum|null $navigationGroup = 'Archive';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Import Profiles';

    public static function form(Schema $schema): Schema
    {
        // Layout rule (user mandate): root columns(1) → full-width Sections;
        // atomic-field Sections use ['default' => 1, 'md' => 2]; non-atomic
        // children (Textarea / map preview) → columnSpanFull.
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->schema([
                Section::make('Profile details')
                    ->columns($twoCols)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(191),

                        Forms\Components\Select::make('import_type')
                            ->required()
                            ->options(self::importTypeOptions())
                            ->disabledOn('edit')
                            ->helperText('Which entity this mapping applies to. Locked after creation.'),

                        Forms\Components\Toggle::make('is_shared')
                            ->label('Share with my repository')
                            ->helperText('Other users in your repository will see this saved mapping.')
                            ->inline(false),

                        Forms\Components\Textarea::make('description')
                            ->maxLength(500)
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->components([
                Section::make('Profile details')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('name')
                            ->label('Name')
                            ->placeholder('—'),
                        TextEntry::make('import_type')
                            ->label('Imports')
                            ->formatStateUsing(fn (string $state): string => self::importTypeOptions()[$state] ?? $state)
                            ->placeholder('—'),
                        IconEntry::make('is_shared')
                            ->label('Shared')
                            ->boolean(),
                        TextEntry::make('user.name')
                            ->label('Owner')
                            ->placeholder('—'),
                        TextEntry::make('use_count')
                            ->label('Times used')
                            ->numeric(),
                        TextEntry::make('last_used_at')
                            ->label('Last used')
                            ->dateTime()
                            ->placeholder('Never'),
                    ]),

                Section::make('Description')
                    ->columns(1)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('description')
                            ->hiddenLabel()
                            ->prose()
                            ->placeholder('No description.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Column mapping (read-only)')
                    ->columns(1)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('column_map')
                            ->hiddenLabel()
                            ->formatStateUsing(fn ($state): string => is_array($state) && $state !== []
                                ? (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                                : '— (no columns)')
                            ->columnSpanFull(),
                        TextEntry::make('synonyms')
                            ->label('Custom synonyms')
                            ->formatStateUsing(fn ($state): string => is_array($state) && $state !== []
                                ? (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                                : '—')
                            ->columnSpanFull(),
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
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                $user = auth()->user();
                if ($user === null) {
                    return $query->whereRaw('1 = 0');
                }

                /** @var User $user */
                return $query->accessibleBy($user);
            })
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->limit(50),

                Tables\Columns\TextColumn::make('import_type')
                    ->label('Imports')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::importTypeOptions()[$state] ?? $state)
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_shared')
                    ->label('Shared')
                    ->boolean(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Owner')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('use_count')
                    ->label('Used')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('last_used_at')
                    ->label('Last used')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Never')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('import_type')
                    ->label('Imports')
                    ->options(self::importTypeOptions()),
                TernaryFilter::make('is_shared')
                    ->label('Shared')
                    ->placeholder('All')
                    ->trueLabel('Shared')
                    ->falseLabel('Private'),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (Model $record): bool => self::canManage($record)),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn (): bool => (bool) auth()->user()?->hasAnyRole(['super_admin', 'admin'])),
                ]),
            ])
            ->defaultSort('last_used_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListImportProfiles::route('/'),
            'view' => Pages\ViewImportProfile::route('/{record}'),
            'edit' => Pages\EditImportProfile::route('/{record}/edit'),
        ];
    }

    /**
     * Owner OR admin can edit / delete a profile. Non-owners can only read
     * shared profiles (the `accessibleBy` scope on the table already
     * enforces visibility).
     */
    public static function canManage(Model $record): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        if ($user->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return (int) $record->getAttribute('user_id') === (int) $user->getKey();
    }

    /**
     * Human labels for the `import_type` enum — keep in sync with
     * {@see ImportProfile::TYPES} and {@see ImportWizard::IMPORTERS}.
     *
     * @return array<string, string>
     */
    public static function importTypeOptions(): array
    {
        return [
            ImportProfile::TYPE_SERIES => 'Series',
            ImportProfile::TYPE_AUTHORITIES => 'Authorities (notaries)',
            ImportProfile::TYPE_BATCHES => 'Batches',
            ImportProfile::TYPE_BOXES => 'Boxes',
            ImportProfile::TYPE_DOCUMENTS => 'Documents',
        ];
    }
}
