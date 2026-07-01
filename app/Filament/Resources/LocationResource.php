<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\LocationResource\Pages;
use App\Filament\Support\CreatorColumn;
use App\Models\Location;
use App\Models\LocationType;
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
use Illuminate\Support\Facades\Schema as SchemaFacade;

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

    protected static string|\UnitEnum|null $navigationGroup = 'Reference';

    protected static ?int $navigationSort = 80;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Per-request memoised code => label map from the editable location_types
     * lookup. Resolved once and reused across every typeLabel() call so the
     * table badge (called per row) does not trigger N+1 queries. Reset between
     * tests via {@see flushTypeLabelMemo()} (mirrors EntityResolver::flushMemo).
     *
     * @var array<string, string>|null
     */
    private static ?array $typeLabelMemo = null;

    public static function form(Schema $schema): Schema
    {
        // Layout rule (user mandate): root columns(1) → full-width Sections;
        // atomic-field Sections use ['default' => 1, 'md' => 2]; non-atomic
        // children (helperText-heavy inputs, Textarea) → columnSpanFull.
        //
        // Wave D3 — Simplified form:
        //   • parent_id removed (new locations always root-level, parent_id=null)
        //   • type limited to the 3 canonical values (room/museum/repository)
        //   • repository_id placed first, required
        //   • code is auto-generated when blank; shown read-only with helper text
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->schema([
                Section::make('Identification')
                    ->columns($twoCols)
                    ->schema([
                        // Repository comes first — it determines the code auto-gen scope.
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
                            ->required()
                            ->helperText('Select the repository this location belongs to.')
                            ->default(fn () => auth()->user()?->default_repository_id),
                        Forms\Components\Select::make('type')
                            ->label('Type')
                            // Feedback1 gaps — options come from the editable
                            // location_types lookup (active rows, ordered by
                            // sort_order); stored value stays the lowercase code
                            // so existing rows ('room', …) remain compatible.
                            // C4 trap — merge the record's CURRENT value back in
                            // so an inactive/legacy type stays selectable on edit
                            // (mirrors BatchResource / BoxResource).
                            ->options(fn (?Location $record): array => self::typeOptionsWith($record?->type))
                            ->required()
                            ->native(false),
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(100),
                        // Wave D3: code is auto-generated on create when blank.
                        Forms\Components\TextInput::make('code')
                            ->label('Identifier')
                            ->maxLength(32)
                            ->helperText('Auto-generated if left blank. Must be unique within the repository.')
                            ->columnSpanFull()
                            ->rule(function (?Location $record) {
                                return function (string $attribute, $value, \Closure $fail) use ($record) {
                                    if ($value === null || $value === '') {
                                        return; // Will be auto-generated on save.
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
                                        $fail("The identifier '{$value}' is already used in this repository.");
                                    }
                                };
                            }),
                    ]),

                Section::make('Status')
                    ->columns($twoCols)
                    ->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->default(true)
                            ->required(),
                        // F08 (review finding) — sort_order hidden from the
                        // simple UI per decision D8: "sort_order kept internally
                        // but hidden from the simple UI." DB column and model
                        // field remain intact for programmatic use.
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
                        // D3/D8 — relabel 'Code' → 'Identifier' in the infolist
                        // to match the form input and table column labels.
                        TextEntry::make('code')
                            ->label('Identifier')
                            ->copyable()
                            ->placeholder('—'),
                        // F04/F07 (review findings) — breadcrumb (Path) and
                        // parent entries removed per decision D8: "remove
                        // breadcrumb/parent/depth from UI".
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
                        // F4/F08 (review findings) — depth and sort_order
                        // removed from infolist per decision D8: "Remove depth;
                        // sort_order kept internally but hidden from the simple UI."
                        IconEntry::make('is_active')
                            ->label('Active')
                            ->boolean(),
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
        // Wave D3 — removed depth + breadcrumb columns; added code (Identifier) column.
        return $table
            ->defaultSort('name')
            // Feedback1 Wave A (A6) — drag-and-drop column reordering.
            ->reorderableColumns()
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => self::typeLabel($state))
                    ->color(fn (string $state) => self::typeColor($state))
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('repository.code')
                    ->label('Repo')
                    ->badge()
                    ->color('gray')
                    ->placeholder('GLOBAL')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('code')
                    ->label('Identifier')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('boxes_count')
                    ->label('Boxes')
                    ->counts('boxes')
                    ->alignCenter()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('documents_count')
                    ->label('Documents')
                    ->counts('documents')
                    ->alignCenter()
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                // A9 — inputter column (who created the record).
                CreatorColumn::make()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    // Feedback1 gaps (F006) — options come from the editable
                    // location_types lookup (same source as the form Select)
                    // so operator-added types are filterable. Closure keeps it
                    // lazy (no DB query at class-resolution time).
                    ->options(fn (): array => self::typeOptions())
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
                                ->body('Location "' . $record->name . '" still has children or is referenced by Boxes/Documents. Re-assign them first.')
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
     * Eager-load the first audit entry (for the CreatorColumn / Inputter
     * column) to avoid N+1 in the table. Mirrors BatchResource: the audits
     * sub-load is filtered to event='created' and ordered by id asc so only
     * the relevant row is fetched per location record.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            // A9 — creator resolution: first 'created' audit with its user.
            'audits' => fn ($q) => $q->where('event', 'created')->oldest('id')->with('user'),
        ]);
    }

    /**
     * Feedback1 gaps — the `type` Select options, sourced from the editable
     * location_types lookup (active rows, ordered by sort_order). Falls back
     * to {@see Location::CANONICAL_TYPES} when the table is missing or empty
     * (fresh SQLite test DBs, partially-migrated environments) so the form
     * never renders an empty Select.
     *
     * @return array<string, string> code => label
     */
    public static function typeOptions(): array
    {
        if (! SchemaFacade::hasTable('location_types')) {
            return Location::CANONICAL_TYPES;
        }

        $options = LocationType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->pluck('label', 'code')
            ->all();

        return $options !== [] ? $options : Location::CANONICAL_TYPES;
    }

    /**
     * Like {@see typeOptions()} but merges the record's CURRENT type back into
     * the option set (CodeRabbit C4 / {@see LocationType::optionsWith()}): an
     * inactive lookup row or a pre-lookup legacy code ('shelf', …) must stay
     * selectable on edit, otherwise the Select blanks the stored value and
     * Filament's in-options validation rejects the save. Keeps the same
     * hasTable / empty-table fallbacks as typeOptions().
     *
     * @return array<string, string> code => label
     */
    public static function typeOptionsWith(?string $current): array
    {
        $options = self::typeOptions();

        if ($current === null || $current === '' || array_key_exists($current, $options)) {
            return $options;
        }

        $label = SchemaFacade::hasTable('location_types')
            ? LocationType::query()->where('code', $current)->value('label')
            : null;

        $options[$current] = $label !== null ? $label . ' (inactive)' : self::typeLabel($current);

        return $options;
    }

    /**
     * Flush the per-request typeLabel memo. Tests call this between scenarios
     * so lookup rows created in one case don't bleed into the next.
     */
    public static function flushTypeLabelMemo(): void
    {
        self::$typeLabelMemo = null;
    }

    /**
     * Human-readable label for a Location type code.
     * Centralised so the form Select, the table badge, the infolist badge and
     * the filter all stay in sync.
     *
     * Feedback1 gaps (F007) — consults the editable location_types lookup first
     * (per-request memoised map, guarded by hasTable for fresh SQLite/test DBs)
     * so an admin-configured label ('Cold Storage' for code 'cold_store') is
     * what every UI surface renders. Falls through to the hardcoded match()
     * for canonical codes and when the lookup has no row / no table.
     */
    public static function typeLabel(string $type): string
    {
        if (self::$typeLabelMemo === null) {
            self::$typeLabelMemo = SchemaFacade::hasTable('location_types')
                ? LocationType::query()->pluck('label', 'code')->all()
                : [];
        }

        $label = self::$typeLabelMemo[$type] ?? null;
        if ($label !== null && $label !== '') {
            return $label;
        }

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
