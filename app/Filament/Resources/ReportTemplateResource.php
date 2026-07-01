<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReportTemplateResource\Pages;
use App\Models\ReportTemplate;
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
 * RFQ §3.2.2 — Manage user-saved report templates.
 *
 * The actual *creation* of a template happens via the "Save as template"
 * header-action on each individual Report page (it captures the current
 * filter/column/sort state). This Resource is the management surface:
 * list, view, edit metadata (name / description / is_shared) and delete.
 *
 * The `source`, `filters`, `columns` and `sort` columns are intentionally
 * NOT editable from the form — those are produced from a Report page's
 * live state and editing them blind would yield a broken template.
 */
class ReportTemplateResource extends Resource
{
    protected static ?string $model = ReportTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bookmark';

    protected static string|\UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        // Layout rule (user mandate): root columns(1) → full-width Sections;
        // atomic-field Sections use ['default' => 1, 'md' => 2]; non-atomic
        // children (Textarea / list-of-filters preview) → columnSpanFull.
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->schema([
                Section::make('Template details')
                    ->columns($twoCols)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(191),

                        Forms\Components\Select::make('source')
                            ->required()
                            ->options(self::sourceOptions())
                            ->disabledOn('edit')
                            ->helperText('Picks the report this template applies to. Locked after creation.'),

                        Forms\Components\Toggle::make('is_shared')
                            ->label('Share with my repository')
                            ->helperText('Other users in your repository will see this saved view.')
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
                Section::make('Template details')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('name')
                            ->label('Name')
                            ->placeholder('—'),
                        TextEntry::make('source')
                            ->label('Report')
                            ->formatStateUsing(fn (string $state): string => self::sourceOptions()[$state] ?? $state)
                            ->placeholder('—'),
                        IconEntry::make('is_shared')
                            ->label('Shared')
                            ->boolean(),
                        TextEntry::make('user.name')
                            ->label('Owner')
                            ->placeholder('—'),
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

                Section::make('Saved state')
                    ->columns(1)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('filters')
                            ->label('Filters')
                            ->formatStateUsing(fn ($state): string => is_array($state) && $state !== []
                                ? (string) json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                                : '— (no filters)')
                            ->columnSpanFull(),
                        TextEntry::make('sort')
                            ->label('Sort')
                            ->formatStateUsing(fn ($state): string => is_array($state) && $state !== []
                                ? (string) json_encode($state, JSON_UNESCAPED_SLASHES)
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

                Tables\Columns\TextColumn::make('source')
                    ->label('Report')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::sourceOptions()[$state] ?? $state)
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\IconColumn::make('is_shared')
                    ->label('Shared')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Owner')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable()
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
                SelectFilter::make('source')
                    ->label('Report')
                    ->options(self::sourceOptions()),
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
            ->defaultSort('created_at', 'desc');
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
            'index' => Pages\ListReportTemplates::route('/'),
            'view' => Pages\ViewReportTemplate::route('/{record}'),
            'edit' => Pages\EditReportTemplate::route('/{record}/edit'),
        ];
    }

    /**
     * Owner OR admin can edit / delete a template. Non-owners can only
     * read shared templates (the AccessibleBy scope on the table
     * already enforces visibility).
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
     * Human labels for the `source` enum — keep in sync with
     * ReportTemplate::SOURCES.
     *
     * @return array<string, string>
     */
    public static function sourceOptions(): array
    {
        return [
            ReportTemplate::SOURCE_DOCUMENTS => 'Documents',
            ReportTemplate::SOURCE_DOCUMENTS_BY_BATCH => 'Documents by batch',
            ReportTemplate::SOURCE_DOCUMENTS_BY_CREATOR => 'Documents by creator',
            ReportTemplate::SOURCE_DOCUMENTS_BY_SERIES => 'Documents by series',
            ReportTemplate::SOURCE_PENDING_DISINFESTATION => 'Pending disinfestation',
            ReportTemplate::SOURCE_BOX_MOVEMENTS => 'Box movement history',
            ReportTemplate::SOURCE_FLAGS_BY_TYPE => 'Flags by type',
        ];
    }
}
