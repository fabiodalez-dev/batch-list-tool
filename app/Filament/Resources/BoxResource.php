<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AppliesFieldPermissions;
use App\Filament\Resources\BoxResource\Pages;
use App\Models\Box;
use App\Models\Location;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BoxResource extends Resource
{
    use AppliesFieldPermissions;

    /** RFQ §3.1.8 — see config/field_permissions.php */
    private const FIELD_PERMISSIONS_KEY = 'box';

    protected static ?string $model = Box::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';

    protected static ?string $navigationGroup = 'Archive';

    protected static ?int $navigationSort = 13;

    protected static ?string $recordTitleAttribute = 'box_number';

    public static function form(Form $form): Form
    {
        // Same wrapping trick as the other resources. NOTE: we do NOT
        // gate `_parent_explicitly_unknown` — it is a transient,
        // control-only toggle with `dehydrated(false)` whose UI rules
        // depend on `box_type`, not on role. Gating would force
        // `dehydrated(true)` and break the bulk-import code path that
        // relies on this toggle being absent from the save payload.
        $g = fn (Forms\Components\Component $c): Forms\Components\Component => self::gateField($c, self::FIELD_PERMISSIONS_KEY);

        return $form
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
                $g(Forms\Components\Select::make('batch_id')
                    ->relationship('batch', 'batch_number')
                    ->searchable()
                    ->preload()),

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
                    ->visible(fn (Forms\Get $get) => $get('box_type') === 'IN_SITU'),
                // `parent_box_id` keeps its own `visible(IN_SITU)` rule —
                // the gate trait uses `hidden()` (separate channel) so
                // the two compose without clobbering each other.
                $g(Forms\Components\Select::make('parent_box_id')
                    ->label('Parent RAS box')
                    ->relationship(
                        'parent',
                        'box_number',
                        fn ($query) => $query->where('box_type', 'RAS')
                    )
                    ->getOptionLabelFromRecordUsing(fn (Box $r) => "RAS Box #{$r->box_number} (batch " . ($r->batch?->batch_number ?? '?') . ', id ' . $r->id . ')')
                    ->searchable()
                    ->preload()
                    ->visible(fn (Forms\Get $get) => $get('box_type') === 'IN_SITU')
                    ->required(fn (Forms\Get $get) => $get('box_type') === 'IN_SITU' && ! $get('_parent_explicitly_unknown'))
                    ->rule(function (Forms\Get $get) {
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
                    ->required(fn (Forms\Get $get) => $get('barcode_status') === 'PERM_OUT')
                    ->helperText(fn (Forms\Get $get) => $get('barcode_status') === 'PERM_OUT'
                        ? 'Required when status is PERM OUT (RFQ Appendix-1 rule #2).'
                        : null)
                    ->rule(function (Forms\Get $get) {
                        return function (string $attribute, $value, \Closure $fail) use ($get) {
                            if ($get('barcode_status') === 'PERM_OUT' && empty($value)) {
                                $fail('Disinfestation date is required when status is PERM OUT (RFQ Appendix-1 rule #2).');
                            }
                        };
                    })),

                // RFQ §3.1.9 — Configurable Location Hierarchies.
                // Boxes may be pinned to a configurable Location (room /
                // work-area / shelf / showcase / temp-holding / …). The
                // option list is scoped to the user's default repository AND
                // global locations (repository_id IS NULL) — see
                // Location::scopeForRepository().
                $g(Forms\Components\Select::make('location_id')
                    ->label('Location (RFQ §3.1.9)')
                    ->relationship(
                        'location',
                        'name',
                        fn ($query) => $query
                            ->active()
                            ->forRepository(auth()->user()?->default_repository_id),
                    )
                    ->getOptionLabelFromRecordUsing(fn (Location $r) => $r->breadcrumb())
                    ->searchable(['name', 'code'])
                    ->preload()
                    ->nullable()
                    ->helperText('Repository / room / shelf / showcase / temp-holding hierarchy.')),

                $g(Forms\Components\Toggle::make('is_legacy')
                    ->helperText('Flags legacy data; required true when box_type is MAV or STVC.')
                    ->required()),
                $g(Forms\Components\Textarea::make('notes')
                    ->columnSpanFull()),
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
