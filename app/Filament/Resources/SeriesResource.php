<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AppliesFieldPermissions;
use App\Filament\Resources\SeriesResource\Pages;
use App\Filament\Support\CreatorColumn;
use App\Models\Repository;
use App\Models\Series;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SeriesResource extends Resource
{
    use AppliesFieldPermissions;

    /** RFQ §3.1.8 — see config/field_permissions.php */
    private const FIELD_PERMISSIONS_KEY = 'series';

    protected static ?string $model = Series::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static string|\UnitEnum|null $navigationGroup = 'Reference';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        $g = fn (Schemas\Components\Component $c): Schemas\Components\Component => self::gateField($c, self::FIELD_PERMISSIONS_KEY);

        // Layout rule (user mandate): root columns(1) → full-width Sections;
        // atomic-field Sections use ['default' => 1, 'md' => 2]; non-atomic
        // children (Textarea) → columnSpanFull.
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->schema([
                Section::make('Identification')
                    ->columns($twoCols)
                    ->schema([
                        // Feedback1 — Series code must be unique.
                        // A3/D9 — user-facing label is "Identifier"; DB column stays 'code'.
                        $g(Forms\Components\TextInput::make('code')
                            ->label('Identifier')
                            ->required()
                            ->maxLength(16)
                            ->unique(ignoreRecord: true)
                            ->validationMessages([
                                'unique' => 'This series identifier is already in use.',
                            ])),
                        $g(Forms\Components\TextInput::make('title')
                            ->required()
                            ->maxLength(255)),
                        // Feedback1 C1.4 — multi-level hierarchy. A series may
                        // sit under a parent (sub-series, sub-sub-series, …).
                        // Options EXCLUDE the record itself and all of its
                        // descendants so a cycle cannot be formed from the UI;
                        // the closure rule below is the server-side backstop.
                        $g(Forms\Components\Select::make('parent_id')
                            ->label('Parent series')
                            ->helperText('Leave empty for a top-level series.')
                            ->native(false)
                            ->searchable()
                            ->preload()
                            ->options(function (?Series $record): array {
                                $query = Series::query()->orderBy('code');
                                if ($record !== null && $record->exists) {
                                    $query->whereNotIn('id', $record->disallowedParentIds());
                                }

                                return $query->get()
                                    ->mapWithKeys(fn (Series $s): array => [
                                        $s->getKey() => $s->qualifiedTitle() . ' — ' . $s->title,
                                    ])
                                    ->all();
                            })
                            ->rule(static function (?Series $record): \Closure {
                                return static function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                                    if ($value === null || $value === '' || $record === null || ! $record->exists) {
                                        return;
                                    }
                                    // Reject self or any descendant as parent.
                                    if (in_array((int) $value, $record->disallowedParentIds(), true)) {
                                        $fail('A series cannot be its own ancestor (cycle).');
                                    }
                                };
                            })),
                        $g(Forms\Components\Toggle::make('is_wills_series')
                            ->required()),
                        $g(Forms\Components\Toggle::make('is_active')
                            ->default(true)   // Bug #21 — a new Series is active by default
                            ->required()),
                    ]),

                // Wave D1 — Repository scope + Document types
                Section::make('Repository & document types')
                    ->columns($twoCols)
                    ->schema([
                        $g(Forms\Components\Select::make('repository_id')
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
                            ->helperText('Leave empty for a GLOBAL series (visible to every repository).')
                            ->default(fn () => auth()->user()?->default_repository_id)),
                        $g(Forms\Components\Select::make('documentTypes')
                            ->label('Document types')
                            ->relationship('documentTypes', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->columnSpanFull()),
                    ]),

                Section::make('Description')
                    ->columns(1)
                    ->collapsed()
                    ->schema([
                        $g(Forms\Components\Textarea::make('description')
                            ->rows(3)
                            ->columnSpanFull()),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        // Layout rule (user mandate): root columns(1) → full-width Sections;
        // atomic entries on ['default' => 1, 'md' => 2]; non-atomic content
        // (prose Description) → columnSpanFull. Series has no outbound FK
        // relationships — only the inverse (documents).
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->components([
                Section::make('Identification')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('code')
                            ->label('Identifier')
                            ->badge()
                            ->color('primary')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('title')
                            ->label('Title')
                            ->placeholder('—'),
                        // Feedback1 C1.4 — show the full hierarchy path and a
                        // link to the parent series.
                        TextEntry::make('hierarchy_path')
                            ->label('Hierarchy')
                            ->state(fn (?Series $record): string => $record?->qualifiedTitle() ?? '—')
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('parent.code')
                            ->label('Parent series')
                            ->url(fn (?Series $record): ?string => $record?->parent_id
                                ? route('filament.admin.resources.series.view', ['record' => $record->parent_id])
                                : null)
                            ->placeholder('Top-level (no parent)'),
                        IconEntry::make('is_wills_series')
                            ->label('Wills series')
                            ->boolean(),
                        IconEntry::make('is_active')
                            ->label('Active')
                            ->boolean(),
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

                Section::make('Counts')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('documents_count')
                            ->label('Documents')
                            ->state(fn (?Series $record): int => $record?->documents()->count() ?? 0)
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
            // Feedback1 Wave B (B1) — persist & defer filters so they survive
            // navigation/refresh (client complaint: "filters seem to reset").
            ->deferFilters()
            ->persistFiltersInSession()
            // Feedback1 Wave A (A6) — drag-and-drop column reordering, mirroring
            // DocumentResource and BoxResource (spec: all main resource lists).
            ->reorderableColumns()
            ->columns([
                $gc(Tables\Columns\TextColumn::make('repository.code')
                    ->label('Repo')
                    ->badge()
                    ->color('gray')
                    ->placeholder('GLOBAL')
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('code')
                    ->label('Identifier')
                    ->searchable()
                    ->sortable()),
                $gc(Tables\Columns\TextColumn::make('title')
                    ->searchable()
                    ->sortable()
                    ->toggleable()),
                Tables\Columns\TextColumn::make('document_types_count')
                    ->label('Doc. types')
                    ->counts('documentTypes')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                // Feedback1 C1.4 — full multi-level hierarchy path
                // (e.g. "R › REG › RWL"), recursive over ancestors. Sorted off
                // by default to keep the default grid compact; toggle on to see
                // where each series sits in the tree.
                Tables\Columns\TextColumn::make('hierarchy_path')
                    ->label('Hierarchy')
                    ->state(fn (Series $record): string => $record->qualifiedTitle())
                    ->toggleable()
                    ->badge()
                    ->color('gray'),
                $gc(Tables\Columns\TextColumn::make('parent.code')
                    ->label('Parent')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)),
                $gc(Tables\Columns\IconColumn::make('is_wills_series')
                    ->boolean()
                    ->toggleable()),
                $gc(Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->toggleable()),
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
                // A9 — inputter column (who created the record).
                CreatorColumn::make(),
            ])
            ->filters([
                // Feedback1 Wave B (B1) — dropdown-driven filters (mechanism #1)
                // alongside the free-text search on code/title (mechanism #2).
                // Series is a small reference table → plain SelectFilter on the
                // distinct codes plus the two boolean flags as TernaryFilters.
                // A3/D9 — filter label matches the column rename: "Identifier".
                SelectFilter::make('code')
                    ->label('Identifier')
                    ->options(fn (): array => Series::query()
                        ->orderBy('code')
                        ->pluck('code', 'code')
                        ->all())
                    ->searchable()
                    ->multiple(),
                TernaryFilter::make('is_wills_series')
                    ->label('Wills series')
                    ->placeholder('All')
                    ->trueLabel('Wills series only')
                    ->falseLabel('Non-wills only'),
                TernaryFilter::make('is_active')
                    ->label('Active')
                    ->placeholder('All')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only'),
                // Feedback1 C1.4 — narrow to root series, and pick a parent.
                TernaryFilter::make('top_level')
                    ->label('Top-level only')
                    ->placeholder('All levels')
                    ->trueLabel('Top-level series only')
                    ->falseLabel('Sub-series only')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereNull('parent_id'),
                        false: fn (Builder $q): Builder => $q->whereNotNull('parent_id'),
                        blank: fn (Builder $q): Builder => $q,
                    ),
                SelectFilter::make('parent_id')
                    ->label('Parent series')
                    ->options(fn (): array => Series::query()
                        ->orderBy('code')
                        ->get()
                        ->mapWithKeys(fn (Series $s): array => [$s->getKey() => $s->qualifiedTitle()])
                        ->all())
                    ->searchable(),
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

    /**
     * Eager-load the first 'created' audit with its user so CreatorColumn
     * can render the inputter name without N+1 queries.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            // A9 — creator resolution: first 'created' audit with its user.
            'audits' => fn ($q) => $q->where('event', 'created')->oldest('id')->with('user'),
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
            'index' => Pages\ListSeries::route('/'),
            'create' => Pages\CreateSeries::route('/create'),
            'view' => Pages\ViewSeries::route('/{record}'),
            'edit' => Pages\EditSeries::route('/{record}/edit'),
        ];
    }
}
