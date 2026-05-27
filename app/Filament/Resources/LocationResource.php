<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\LocationResource\Pages;
use App\Filament\Support\SearchableSelects;
use App\Models\Location;
use App\Models\Repository;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * RFQ §3.1.9 — admin UI for configurable Location hierarchies.
 *
 * Sits in the new "Reference data" navigation group alongside Series and
 * Authorities. Only admins / editors edit it; viewers see read-only.
 *
 * Notable form rules:
 *  - `parent_id` is optional but, if set, its option list is rendered with
 *    the breadcrumb path so the user can tell "Room 3 under Repository A"
 *    apart from "Room 3 under Repository B".
 *  - The (repository_id, code) uniqueness is enforced at the DB level
 *    (locations migration) AND in the form rule below so the user gets a
 *    friendly inline error instead of a 500.
 *  - Delete is refused (with a Notification) when the location has either
 *    children or attached Boxes/Documents — see {@see Location::isReferenced()}.
 */
class LocationResource extends Resource
{
    protected static ?string $model = Location::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static string|\UnitEnum|null $navigationGroup = 'Reference data';

    protected static ?int $navigationSort = 80;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        // Layout rule (user mandate): root columns(1) → full-width Sections;
        // atomic-field Sections use ['default' => 1, 'md' => 2]; non-atomic
        // children (helperText-heavy inputs, Textarea) → columnSpanFull.
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->schema([
                Section::make('Identification')
                    ->columns($twoCols)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(100),
                        Forms\Components\Select::make('type')
                            ->options(collect(Location::TYPES)
                                ->mapWithKeys(fn (string $t) => [$t => self::typeLabel($t)])
                                ->all())
                            ->required()
                            ->native(false)
                            ->helperText('Drives the icon/badge in the table and the type filters on Box/Document.'),
                        // Locations can grow large in big archives; server-side
                        // autocomplete keeps the picker usable. Breadcrumb
                        // label distinguishes "Room 3 under Repo A" from
                        // "Room 3 under Repo B".
                        SearchableSelects::location(
                            'parent_id',
                            null,
                            'parent',
                        )
                            ->label('Parent location')
                            ->nullable()
                            ->helperText('Leave empty for a root node. Cycles are rejected; max depth is '
                                . Location::MAX_DEPTH . '.')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('code')
                            ->maxLength(32)
                            ->helperText('Optional short code. Must be unique within the same repository.')
                            ->columnSpanFull()
                            ->rule(function (?Location $record) {
                                return function (string $attribute, $value, \Closure $fail) use ($record) {
                                    if ($value === null || $value === '') {
                                        return;
                                    }
                                    $repoId = request()->input('data.repository_id') ?? optional($record)->repository_id;
                                    $q = Location::query()
                                        ->withoutGlobalScopes()
                                        ->where('code', $value);
                                    $repoId === null
                                        ? $q->whereNull('repository_id')
                                        : $q->where('repository_id', $repoId);
                                    if ($record !== null) {
                                        $q->whereKeyNot($record->getKey());
                                    }
                                    if ($q->exists()) {
                                        $fail("The code '{$value}' is already used in this repository scope.");
                                    }
                                };
                            }),
                    ]),

                Section::make('Scope & status')
                    ->columns($twoCols)
                    ->schema([
                        Forms\Components\Select::make('repository_id')
                            ->label('Repository')
                            ->options(function () {
                                $user = auth()->user();
                                $query = Repository::query();
                                if ($user !== null
                                    && method_exists($user, 'hasAnyRole')
                                    && ! $user->hasAnyRole(['super_admin', 'admin'])
                                ) {
                                    $ids = method_exists($user, 'repositories')
                                        ? $user->repositories()->pluck('repositories.id')->all()
                                        : [];
                                    $query->whereIn('id', $ids);
                                }

                                return $query->orderBy('code')->pluck('name', 'id')->all();
                            })
                            ->searchable()
                            ->nullable()
                            ->helperText('Leave empty for a GLOBAL location (visible to every repository).')
                            ->default(fn () => auth()->user()?->default_repository_id)
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('is_active')
                            ->default(true)
                            ->required(),
                        Forms\Components\TextInput::make('sort_order')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('Sibling display order under the same parent.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Notes')
                    ->columns(1)
                    ->collapsed()
                    ->schema([
                        Forms\Components\Textarea::make('notes')->rows(3)->columnSpanFull(),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        // Layout rule (user mandate): root columns(1) → full-width Sections;
        // atomic entries on ['default' => 1, 'md' => 2]; non-atomic content
        // → columnSpanFull. Every FK gets a ->url() to its Resource view.
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->components([
                Section::make('Identification')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('name')
                            ->label('Name')
                            ->placeholder('—'),
                        TextEntry::make('type')
                            ->label('Type')
                            ->badge()
                            ->color(fn (?string $state): string => $state ? self::typeColor($state) : 'gray')
                            ->formatStateUsing(fn (?string $state): string => $state
                                ? self::typeLabel($state)
                                : '—')
                            ->placeholder('—'),
                        TextEntry::make('code')
                            ->label('Code')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('breadcrumb')
                            ->label('Path')
                            ->state(fn (?Location $record): string => $record?->breadcrumb() ?? '—')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('parent.name')
                            ->label('Parent')
                            ->url(fn (?Location $record): ?string => $record?->parent_id
                                ? route('filament.admin.resources.locations.view', ['record' => $record->parent_id])
                                : null)
                            ->openUrlInNewTab(false)
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
                            ->url(fn (?Location $record): ?string => $record?->repository_id
                                ? route('filament.admin.resources.repositories.view', ['record' => $record->repository_id])
                                : null)
                            ->openUrlInNewTab(false)
                            ->placeholder('GLOBAL'),
                        TextEntry::make('depth')
                            ->label('Depth')
                            ->badge()
                            ->color('gray')
                            ->placeholder('—'),
                        IconEntry::make('is_active')
                            ->label('Active')
                            ->boolean(),
                        TextEntry::make('sort_order')
                            ->label('Sort order')
                            ->placeholder('—'),
                    ]),

                Section::make('Counts')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('boxes_count')
                            ->label('Boxes')
                            ->state(fn (?Location $record): int => $record?->boxes()->count() ?? 0)
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('documents_count')
                            ->label('Documents')
                            ->state(fn (?Location $record): int => $record?->documents()->count() ?? 0)
                            ->badge()
                            ->color('gray'),
                    ]),

                Section::make('Notes')
                    ->columns(1)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('notes')
                            ->hiddenLabel()
                            ->prose()
                            ->placeholder('No notes.')
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
            ->defaultSort('path')
            ->columns([
                Tables\Columns\TextColumn::make('breadcrumb')
                    ->label('Path')
                    ->state(fn (Location $r) => $r->breadcrumb())
                    ->searchable(query: function (Builder $q, string $search): Builder {
                        $needle = '%' . trim($search) . '%';

                        return $q->where(function (Builder $q) use ($needle) {
                            $q->where('name', 'like', $needle)
                                ->orWhere('code', 'like', $needle);
                        });
                    })
                    ->wrap(),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::typeLabel($state))
                    ->color(fn (string $state) => self::typeColor($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('repository.code')
                    ->label('Repo')
                    ->badge()
                    ->color('gray')
                    ->placeholder('GLOBAL'),
                Tables\Columns\TextColumn::make('depth')
                    ->numeric()
                    ->alignCenter()
                    ->sortable(),
                Tables\Columns\TextColumn::make('boxes_count')
                    ->label('Boxes')
                    ->counts('boxes')
                    ->alignCenter()
                    ->sortable(),
                Tables\Columns\TextColumn::make('documents_count')
                    ->label('Documents')
                    ->counts('documents')
                    ->alignCenter()
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(collect(Location::TYPES)
                        ->mapWithKeys(fn (string $t) => [$t => self::typeLabel($t)])
                        ->all())
                    ->multiple(),
                SelectFilter::make('repository_id')
                    ->label('Repository')
                    ->relationship('repository', 'code')
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('is_active')
                    ->placeholder('Any')
                    ->trueLabel('Active')
                    ->falseLabel('Inactive'),
                TernaryFilter::make('orphan')
                    ->label('Root nodes only')
                    ->placeholder('Any')
                    ->trueLabel('Yes (no parent)')
                    ->falseLabel('No (has parent)')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNull('parent_id'),
                        false: fn (Builder $q) => $q->whereNotNull('parent_id'),
                    ),
                TrashedFilter::make(),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()
                    ->before(function (DeleteAction $action, Location $record) {
                        if ($record->hasChildren() || $record->isReferenced()) {
                            Notification::make()
                                ->danger()
                                ->title('Cannot delete location')
                                ->body('Location "' . $record->breadcrumb() . '" still has children or is referenced by Boxes/Documents. Re-assign them first.')
                                ->send();
                            $action->cancel();
                        }
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    // No bulk-delete: enforcing the "no children, no
                    // references" guard per-row inside a bulk action is too
                    // surprising — admins should delete one Location at a
                    // time and see WHY each deletion failed.
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
            'index' => Pages\ListLocations::route('/'),
            'create' => Pages\CreateLocation::route('/create'),
            'view' => Pages\ViewLocation::route('/{record}'),
            'edit' => Pages\EditLocation::route('/{record}/edit'),
        ];
    }

    /**
     * Human-readable label for a Location::TYPES value.
     * Centralised so the form Select, the table badge and the filter all
     * stay in sync.
     */
    public static function typeLabel(string $type): string
    {
        return match ($type) {
            'repository' => 'Repository',
            'room' => 'Room',
            'work_area' => 'Work area',
            'shelf' => 'Shelf',
            'museum' => 'Museum',
            'showcase' => 'Showcase',
            'conservation' => 'Conservation',
            'temp_holding' => 'Temp. holding',
            'other' => 'Other',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }

    /** Filament badge color per Location type. */
    public static function typeColor(string $type): string
    {
        return match ($type) {
            'repository' => 'primary',
            'room' => 'info',
            'shelf' => 'gray',
            'work_area' => 'warning',
            'museum', 'showcase' => 'success',
            'conservation' => 'danger',
            'temp_holding' => 'warning',
            default => 'gray',
        };
    }
}
