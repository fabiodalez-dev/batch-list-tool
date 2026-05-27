<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AppliesFieldPermissions;
use App\Filament\Resources\BoxResource\Pages;
use App\Filament\Support\SearchableSelects;
use App\Models\Box;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class BoxResource extends Resource
{
    use AppliesFieldPermissions;

    /** RFQ §3.1.8 — see config/field_permissions.php */
    private const FIELD_PERMISSIONS_KEY = 'box';

    protected static ?string $model = Box::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static string|\UnitEnum|null $navigationGroup = 'Archive';

    protected static ?int $navigationSort = 13;

    protected static ?string $recordTitleAttribute = 'box_number';

    public static function form(Schema $schema): Schema
    {
        // Same wrapping trick as the other resources. NOTE: we do NOT
        // gate `_parent_explicitly_unknown` — it is a transient,
        // control-only toggle with `dehydrated(false)` whose UI rules
        // depend on `box_type`, not on role. Gating would force
        // `dehydrated(true)` and break the bulk-import code path that
        // relies on this toggle being absent from the save payload.
        $g = fn (Schemas\Components\Component $c): Schemas\Components\Component => self::gateField($c, self::FIELD_PERMISSIONS_KEY);

        // Layout rule (user mandate): root columns(1) → full-width Sections;
        // atomic-field Sections use ['default' => 1, 'md' => 2]; non-atomic
        // content (Textarea, helperText-heavy inputs) → columnSpanFull.
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->schema([
                Section::make('Identification')
                    ->columns($twoCols)
                    ->schema([
                        $g(Forms\Components\Select::make('box_type')
                            ->options(collect(Box::TYPES)->mapWithKeys(fn ($t) => [$t => $t]))
                            ->required()
                            ->live()  // re-evaluate visibility/required of dependent fields
                            ->helperText('RAS / IN_SITU / NRA for new boxes. MAV / STVC are legacy-only and cannot be created.')
                            // RFQ Appendix-1 rule #4: legacy box types (MAV, STVC) cannot be
                            // assigned to *new* boxes. Existing legacy records must stay
                            // editable, so we only enforce this on CREATE.
                            ->rule(function (?Box $record) {
                                return function (string $attribute, $value, \Closure $fail) use ($record) {
                                    if ($record !== null && $record->exists) {
                                        return; // edit: legacy stays editable
                                    }
                                    if ($value !== null && in_array((string) $value, Box::LEGACY_TYPES, true)) {
                                        $fail("Box type '{$value}' is a legacy type and cannot be assigned to new boxes (RFQ Appendix-1 rule #4). Allowed for create: " . implode(', ', array_diff(Box::TYPES, Box::LEGACY_TYPES)) . '.');
                                    }
                                };
                            })),
                        $g(Forms\Components\TextInput::make('box_number')
                            ->required()
                            ->maxLength(32)),
                        // Batches dropdown: ~30 rows in production, but kept on the
                        // SearchableSelects helper for label consistency.
                        $g(SearchableSelects::batch('batch_id', 'batch')),
                        $g(Forms\Components\Toggle::make('is_legacy')
                            ->helperText('Flags legacy data; required true when box_type is MAV or STVC.')
                            ->required()),
                    ]),

                Section::make('Provenance (RAS parent)')
                    ->columns($twoCols)
                    ->visible(fn (Get $get) => $get('box_type') === 'IN_SITU')
                    ->schema([
                        // RFQ Appendix-1 rule #3: In Situ boxes must reference a previous
                        // RAS box, unless the user explicitly opts-out via the "no parent
                        // (provenance lost)" toggle. The toggle is the documented escape
                        // hatch for the few legacy records described in Requirements §ii
                        // ("there are only a few exceptions where this rule is broken, as
                        // the provenance of the document was lost ie: Unknown/NULL RAS box").
                        // NOT gated — see comment at the top of this method.
                        Forms\Components\Toggle::make('_parent_explicitly_unknown')
                            ->label('Provenance lost (no parent RAS box)')
                            ->helperText('Only tick this if the RAS box of origin is genuinely unknown — RFQ Appendix-1 rule #3 escape hatch. Use sparingly.')
                            ->dehydrated(false)  // not persisted; control-only field
                            ->default(false)
                            ->columnSpanFull(),
                        // `parent_box_id` keeps its own `visible(IN_SITU)` rule —
                        // the gate trait uses `hidden()` (separate channel) so
                        // the two compose without clobbering each other.
                        //
                        // The RAS-only filter is now applied through the
                        // SearchableSelects helper, which also handles the
                        // server-side autocomplete (600+ boxes in production).
                        $g(SearchableSelects::boxFiltered(
                            'parent_box_id',
                            'parent',
                            fn ($query) => $query->where('box_type', 'RAS'),
                        )
                            ->label('Parent RAS box')
                            ->visible(fn (Get $get) => $get('box_type') === 'IN_SITU')
                            ->required(fn (Get $get) => $get('box_type') === 'IN_SITU' && ! $get('_parent_explicitly_unknown'))
                            ->columnSpanFull()
                            ->rule(function (Get $get) {
                                return function (string $attribute, $value, \Closure $fail) use ($get) {
                                    // Strict enforcement at validation time (defence in depth
                                    // vs the ->required() above; covers API/bulk-import paths
                                    // that don't go through the Filament Required validator).
                                    if ($get('box_type') !== 'IN_SITU') {
                                        return;
                                    }
                                    if (! $get('_parent_explicitly_unknown') && empty($value)) {
                                        $fail('IN_SITU boxes must reference a parent RAS box (RFQ Appendix-1 rule #3). Tick "Provenance lost" only if the origin RAS box is genuinely unknown.');
                                    }
                                };
                            })),
                    ]),

                Section::make('Barcode & status')
                    ->columns($twoCols)
                    ->schema([
                        $g(Forms\Components\TextInput::make('barcode')
                            ->maxLength(64)),
                        $g(Forms\Components\Select::make('barcode_status')
                            ->options(collect(Box::BARCODE_STATUSES)->mapWithKeys(fn ($s) => [$s => $s]))
                            ->required()
                            ->live()
                            ->default('IN')),
                        // RFQ Appendix-1 rule #2: a record cannot be marked PERM OUT
                        // unless it has a disinfestation_date.
                        $g(Forms\Components\DatePicker::make('disinfestation_date')
                            ->required(fn (Get $get) => $get('barcode_status') === 'PERM_OUT')
                            ->helperText(fn (Get $get) => $get('barcode_status') === 'PERM_OUT'
                                ? 'Required when status is PERM OUT (RFQ Appendix-1 rule #2).'
                                : null)
                            ->columnSpanFull()
                            ->rule(function (Get $get) {
                                return function (string $attribute, $value, \Closure $fail) use ($get) {
                                    if ($get('barcode_status') === 'PERM_OUT' && empty($value)) {
                                        $fail('Disinfestation date is required when status is PERM OUT (RFQ Appendix-1 rule #2).');
                                    }
                                };
                            })),
                    ]),

                Section::make('Location')
                    ->columns(1)
                    ->schema([
                        // RFQ §3.1.9 — Configurable Location Hierarchies.
                        // Boxes may be pinned to a configurable Location (room /
                        // work-area / shelf / showcase / temp-holding / …). The
                        // option list is scoped to the user's default repository AND
                        // global locations (repository_id IS NULL) — see
                        // Location::scopeForRepository().
                        $g(SearchableSelects::location(
                            'location_id',
                            fn ($query) => $query
                                ->active()
                                ->forRepository(auth()->user()?->default_repository_id),
                        )
                            ->label('Location (RFQ §3.1.9)')
                            ->nullable()
                            ->helperText('Repository / room / shelf / showcase / temp-holding hierarchy.')
                            ->columnSpanFull()),
                    ]),

                Section::make('Notes')
                    ->columns(1)
                    ->collapsed()
                    ->schema([
                        $g(Forms\Components\Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull()),
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
                        TextEntry::make('box_number')
                            ->label('Box number')
                            ->badge()
                            ->color('primary')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('box_type')
                            ->label('Type')
                            ->badge()
                            ->color('gray')
                            ->placeholder('—'),
                        TextEntry::make('batch.batch_number')
                            ->label('Batch')
                            ->badge()
                            ->color('gray')
                            ->url(fn (?Box $record): ?string => $record?->batch_id
                                ? route('filament.admin.resources.batches.view', ['record' => $record->batch_id])
                                : null)
                            ->openUrlInNewTab(false)
                            ->placeholder('—'),
                        IconEntry::make('is_legacy')
                            ->label('Legacy')
                            ->boolean(),
                    ]),

                Section::make('Provenance (RAS parent)')
                    ->columns($twoCols)
                    ->visible(fn (?Box $record): bool => $record?->box_type === 'IN_SITU')
                    ->schema([
                        TextEntry::make('parent.box_number')
                            ->label('Parent RAS box')
                            ->badge()
                            ->color('warning')
                            ->url(fn (?Box $record): ?string => $record?->parent_box_id
                                ? route('filament.admin.resources.boxes.view', ['record' => $record->parent_box_id])
                                : null)
                            ->openUrlInNewTab(false)
                            ->placeholder('Provenance lost'),
                        TextEntry::make('parent.batch.batch_number')
                            ->label('Parent batch')
                            ->badge()
                            ->color('gray')
                            ->placeholder('—'),
                    ]),

                Section::make('Barcode & status')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('barcode')
                            ->label('Barcode')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('barcode_status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'IN' => 'success',
                                'OUT' => 'warning',
                                'PERM_OUT' => 'danger',
                                default => 'gray',
                            })
                            ->placeholder('—'),
                        TextEntry::make('disinfestation_date')
                            ->label('Disinfestation date')
                            ->date()
                            ->badge()
                            ->color(fn (?string $state): string => $state ? 'success' : 'warning')
                            ->placeholder('Pending')
                            ->columnSpanFull(),
                    ]),

                Section::make('Location')
                    ->columns(1)
                    ->schema([
                        TextEntry::make('location.full_path')
                            ->label('Location')
                            ->url(fn (?Box $record): ?string => $record?->location_id
                                ? route('filament.admin.resources.locations.view', ['record' => $record->location_id])
                                : null)
                            ->openUrlInNewTab(false)
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Counts')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('documents_count')
                            ->label('Documents')
                            ->state(fn (?Box $record): int => $record?->documents()->count() ?? 0)
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('children_count')
                            ->label('Child IN_SITU boxes')
                            ->state(fn (?Box $record): int => $record?->children()->count() ?? 0)
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
        $gc = fn (mixed $col, ?string $fieldOverride = null): mixed => self::gateColumn($col, self::FIELD_PERMISSIONS_KEY, $fieldOverride);

        return $table
            ->columns([
                $gc(Tables\Columns\TextColumn::make('box_type')),
                $gc(Tables\Columns\TextColumn::make('box_number')
                    ->searchable()),
                $gc(Tables\Columns\TextColumn::make('batch.batch_number')
                    ->numeric()
                    ->sortable(), 'batch_id'),
                $gc(Tables\Columns\TextColumn::make('parent_box_id')
                    ->numeric()
                    ->sortable()),
                $gc(Tables\Columns\TextColumn::make('barcode')
                    ->searchable()),
                $gc(Tables\Columns\TextColumn::make('barcode_status')),
                $gc(Tables\Columns\TextColumn::make('disinfestation_date')
                    ->date()
                    ->sortable()),
                $gc(Tables\Columns\IconColumn::make('is_legacy')
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
            BoxResource\RelationManagers\BarcodeHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBoxes::route('/'),
            'create' => Pages\CreateBox::route('/create'),
            'view' => Pages\ViewBox::route('/{record}'),
            'edit' => Pages\EditBox::route('/{record}/edit'),
        ];
    }
}
