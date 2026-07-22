<?php

namespace App\Filament\Resources;

use App\Filament\Actions\Boxes\DestroyBoxAction;
use App\Filament\Actions\Boxes\MoveBoxToLocationAction;
use App\Filament\Concerns\AppliesFieldPermissions;
use App\Filament\Resources\BoxResource\Pages;
use App\Filament\Support\CreatorColumn;
use App\Filament\Support\SearchableSelects;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Location;
use App\Models\Lookup\BarcodeStatus;
use App\Models\Lookup\BoxType;
use App\Support\CustomFields\CustomFieldSchema;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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
        // Same wrapping trick as the other resources. NOTE: `provenance_unknown`
        // IS gated via $g() — it is a real persisted column (added in A1.3) whose
        // visibility depends on box_type. The old transient `_parent_explicitly_unknown`
        // has been superseded by this persistent flag.
        $g = fn (Schemas\Components\Component $c): Schemas\Components\Component => self::gateField($c, self::FIELD_PERMISSIONS_KEY);

        // Layout rule (user mandate): root columns(1) → full-width Sections;
        // atomic-field Sections use ['default' => 1, 'md' => 2]; non-atomic
        // content (Textarea, helperText-heavy inputs) → columnSpanFull.
        $twoCols = ['default' => 1, 'md' => 2];

        // Feedback1 Wave C2.1 — conditional box input form.
        //   - RAS  → Batch + Box number + Barcode required; Seal optional;
        //            status (IN/OUT/PERM OUT) defaulting to IN.
        //   - IN_SITU / NRA → a Box IDENTIFIER (mapped to box_number, e.g.
        //            "NRA1") + Location required; Batch / Barcode / Seal are
        //            NOT required (they describe a RAS box, not an in-situ one).
        //   - MAV / STVC are legacy types — kept editable but treated like RAS
        //            for field presence so historical rows never break.
        // The box_type Select is ->live() so these visible()/required() closures
        // re-evaluate as the operator picks a type.
        $isRas = fn (Get $get): bool => in_array($get('box_type'), ['RAS', 'MAV', 'STVC'], true);
        $isInSitu = fn (Get $get): bool => in_array($get('box_type'), ['IN_SITU', 'NRA'], true);

        return $schema
            ->columns(1)
            ->schema([
                Section::make('Identification')
                    ->columns($twoCols)
                    ->schema([
                        $g(Forms\Components\Select::make('box_type')
                            // C4 — keep the record's current (possibly inactive) value selectable on edit.
                            ->options(fn (?Box $record): array => BoxType::optionsWith($record?->box_type))
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
                        // Batches dropdown: ~30 rows in production, but kept on the
                        // SearchableSelects helper for label consistency.
                        // Feedback1 Wave B (B5) — `->live()` so box_number's
                        // helper text + uniqueness re-evaluate when the batch
                        // changes.
                        $g(SearchableSelects::batch('batch_id', 'batch')
                            ->live()
                            // C2.1 — Batch is required for RAS boxes only; an
                            // IN_SITU / NRA box is identified by its own
                            // identifier + location, not by a batch.
                            ->required($isRas)
                            ->helperText(fn (Get $get): ?string => $isInSitu($get)
                                ? 'Optional for IN_SITU / NRA boxes.'
                                : 'Required for RAS boxes.')),
                        // Feedback1 Wave B (B5) — box_number is unique *within its
                        // batch* (a batch may not have two boxes numbered "5",
                        // but batch A and batch B may each have a box "5"). The
                        // helper lists the numbers already taken in the selected
                        // batch so the operator can pick a free one.
                        $g(Forms\Components\TextInput::make('box_number')
                            ->required()
                            ->maxLength(32)
                            ->live(onBlur: true)
                            // C2.1 — label + meaning differ by type. For RAS it
                            // is a numeric box number unique within its batch;
                            // for IN_SITU / NRA it is the box IDENTIFIER (e.g.
                            // "NRA1", "MAV1") stored in the same column.
                            ->label(fn (Get $get): string => $isInSitu($get) ? 'Box identifier' : 'Box number')
                            // C2.1 — RAS box numbers are numeric only; IN_SITU /
                            // NRA identifiers are free-text (alphanumeric).
                            ->numeric(fn (Get $get): bool => $isRas($get))
                            ->helperText(fn (Get $get, ?Box $record): string => $isInSitu($get)
                                ? 'Identifier for this in-situ box, e.g. "NRA1" or "MAV1".'
                                : self::usedBoxNumbersHelper(
                                    $get('batch_id') !== null && $get('batch_id') !== '' ? (int) $get('batch_id') : null,
                                    $record?->getKey(),
                                ))
                            ->rule(static function (Get $get, ?Box $record): \Closure {
                                return static function (string $attribute, $value, \Closure $fail) use ($get, $record): void {
                                    // Reject a box_number already used by another box
                                    // in the same batch; ignore the current record on
                                    // edit. No batch selected yet → nothing to check.
                                    $batchId = $get('batch_id');
                                    if ($batchId === null || $batchId === '' || $value === null || $value === '') {
                                        return;
                                    }

                                    $exists = Box::query()
                                        ->where('batch_id', (int) $batchId)
                                        ->where('box_number', (string) $value)
                                        ->when($record?->getKey(), fn ($q, $id) => $q->whereKeyNot($id))
                                        ->exists();

                                    if ($exists) {
                                        $fail("Box number {$value} is already used in this batch. Box numbers must be unique within a batch.");
                                    }
                                };
                            })),
                        // Feedback1 gaps (misc) — client asked "what is the purpose of
                        // Is legacy?": it only matters for legacy box types (MAV / STVC,
                        // flagged is_legacy on the box_types lookup), so hide it for
                        // everything else instead of showing a mandatory toggle on every
                        // box. The DB column defaults to false, so hidden = false. Kept
                        // visible on edit when the record is already flagged legacy so
                        // historical rows still render the value.
                        $g(Forms\Components\Toggle::make('is_legacy')
                            ->helperText('Marks data migrated from the legacy spreadsheet; must be on for legacy box types (MAV / STVC).')
                            ->default(false)
                            ->visible(fn (Get $get, ?Box $record): bool => in_array($get('box_type'), self::legacyBoxTypeCodes(), true)
                                || (bool) $record?->is_legacy)),
                    ]),

                Section::make('Provenance (RAS parent)')
                    ->columns($twoCols)
                    // RFQ App.1 #3 applies to BOTH IN_SITU and NRA boxes — they
                    // each require a parent RAS box — so the provenance section
                    // is shown for either type, not IN_SITU alone.
                    ->visible(fn (Get $get) => in_array($get('box_type'), ['IN_SITU', 'NRA'], true))
                    ->schema([
                        // RFQ A1.3 — explicit-NULL exception: the `provenance_unknown`
                        // Toggle is the documented escape hatch for IN_SITU / NRA boxes
                        // whose origin RAS box is genuinely unknown. Persisted to the DB
                        // column of the same name; the model guard reads it to allow a
                        // null `parent_box_id` only when this flag is set.
                        // NOT gated — see comment at the top of this method.
                        $g(Forms\Components\Toggle::make('provenance_unknown')
                            ->label('Provenance unknown (no RAS parent)')
                            ->helperText('Only tick this if the RAS box of origin is genuinely unknown — RFQ A1.3 / Appendix-1 rule #3 escape hatch. Use sparingly.')
                            ->default(false)
                            ->live()
                            ->columnSpanFull()),
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
                            // U3 — hide (not just de-require) when provenance is unknown;
                            // keeping it visible but optional was confusing.
                            ->visible(fn (Get $get) => in_array($get('box_type'), ['IN_SITU', 'NRA'], true) && ! $get('provenance_unknown'))
                            ->required(fn (Get $get) => in_array($get('box_type'), ['IN_SITU', 'NRA'], true) && ! $get('provenance_unknown'))
                            ->columnSpanFull()
                            ->rule(function (Get $get) {
                                return function (string $attribute, $value, \Closure $fail) use ($get) {
                                    // Strict enforcement at validation time (defence in depth
                                    // vs the ->required() above; covers API/bulk-import paths
                                    // that don't go through the Filament Required validator).
                                    if (! in_array($get('box_type'), ['IN_SITU', 'NRA'], true)) {
                                        return;
                                    }
                                    if (! $get('provenance_unknown') && empty($value)) {
                                        $fail('IN_SITU / NRA boxes must reference a parent RAS box (RFQ Appendix-1 rule #3). Tick "Provenance unknown" only if the origin RAS box is genuinely unknown.');
                                    }
                                };
                            })),
                        // Bug #36 — a box may have MORE THAN ONE parent box (documents
                        // from several origin boxes combined after cataloguing). This is
                        // additive: the single "Parent RAS box" above stays the primary
                        // provenance; these are supplementary origins.
                        $g(Forms\Components\Select::make('parents')
                            ->label('Additional parent boxes')
                            ->helperText('Optional — other origin RAS boxes this one was assembled from.')
                            // Review finding: mirror the single parent_box_id guard —
                            // additional parents must be RAS boxes (provenance origins)
                            // and a box may never be its own parent.
                            ->relationship('parents', 'box_number', function (Builder $query, ?Box $record) {
                                $query->where('box_type', 'RAS');
                                if ($record !== null) {
                                    $query->whereKeyNot($record->getKey());
                                }

                                return $query;
                            })
                            ->getOptionLabelFromRecordUsing(fn (Box $r): string => ($r->batch?->batch_number ? 'Batch ' . $r->batch->batch_number . ' / ' : '') . 'Box ' . $r->box_number)
                            ->multiple()
                            ->searchable()
                            ->columnSpanFull()),
                    ]),

                Section::make('Barcode & status')
                    ->columns($twoCols)
                    ->schema([
                        // A10 — Barcode is required and globally unique across all boxes
                        // (never null; a duplicate triggers a friendly validation message).
                        $g(Forms\Components\TextInput::make('barcode')
                            ->label('Box barcode')
                            ->helperText('Barcode label affixed to this box. Must be globally unique. Distinct from any per-document barcodes inside it.')
                            ->required(fn (Get $get): bool => $isRas($get))
                            ->maxLength(64)
                            ->rule(static function (?Box $record): \Closure {
                                return static function (string $attribute, $value, \Closure $fail) use ($record): void {
                                    if ($value === null || trim((string) $value) === '') {
                                        return; // required() handles the empty case
                                    }
                                    $exists = Box::withoutGlobalScopes()
                                        ->where('barcode', trim((string) $value))
                                        ->when($record?->getKey(), fn ($q, $id) => $q->whereKeyNot($id))
                                        ->exists();
                                    if ($exists) {
                                        $fail('This barcode is already assigned to another box. Box barcodes must be globally unique.');
                                    }
                                };
                            })),
                        // RFQ Contract App.2-i — the yellow security seal that
                        // closes the box belongs to the BOX; every change is
                        // logged to box_seal_number_history (see Seal history).
                        $g(Forms\Components\TextInput::make('seal_number')
                            ->label('Seal #')
                            ->maxLength(255)
                            ->helperText('Yellow security seal closing the box. Changes are kept in the seal history.')),
                        $g(Forms\Components\Select::make('barcode_status')
                            // C4 — keep the record's current (possibly inactive) value selectable on edit.
                            ->options(fn (?Box $record): array => BarcodeStatus::optionsWith($record?->barcode_status))
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
                            // C2.1 — Location is mandatory for IN_SITU / NRA
                            // boxes (they live at a configured NRA location);
                            // optional for RAS boxes (which live in a batch).
                            // Also mandatory for any box marked PERM OUT: a box
                            // that has permanently left storage must record where
                            // it now lives (this widens the rule to RAS boxes set
                            // to PERM_OUT, which would otherwise pass blank).
                            ->required(fn (Get $get): bool => $isInSitu($get) || $get('barcode_status') === 'PERM_OUT')
                            ->helperText(fn (Get $get): string => ($isInSitu($get) || $get('barcode_status') === 'PERM_OUT')
                                ? 'Required for IN_SITU / NRA boxes and for any box marked PERM OUT. Repository / room / shelf / showcase / temp-holding hierarchy.'
                                : 'Repository / room / shelf / showcase / temp-holding hierarchy.')
                            ->columnSpanFull()
                            ->rule(function (Get $get) use ($isInSitu) {
                                return function (string $attribute, $value, \Closure $fail) use ($get, $isInSitu) {
                                    // Defence-in-depth beyond ->required(): covers
                                    // API / bulk-import paths that bypass the
                                    // Filament Required validator.
                                    if ($isInSitu($get) && empty($value)) {
                                        $fail('IN_SITU / NRA boxes must reference a Location (RFQ Feedback1 C2.1).');
                                    }
                                    if ($get('barcode_status') === 'PERM_OUT' && empty($value)) {
                                        $fail('A box marked PERM OUT must have a location.');
                                    }
                                };
                            })),
                    ]),

                // F3 (review finding) — the barcode-history log is an
                // append-only audit trail written EXCLUSIVELY by the model
                // observer (Box::booted → recordBarcodeChange) on every
                // barcode / status change. This Repeater is READ-ONLY so the
                // form is not a second, conflicting write path (which produced
                // duplicate rows + a mutable audit log). It mirrors the
                // read-only RelationManager shown next to the form.
                Section::make('Barcode legacy / history')
                    ->columns(1)
                    ->collapsed()
                    ->description('Old Barcode N : Status. Read-only — written automatically when the barcode/status changes.')
                    ->visible(fn (): bool => (bool) auth()->user()?->can('update_box'))
                    ->schema([
                        Forms\Components\Repeater::make('barcodeHistory')
                            ->relationship()
                            ->hiddenLabel()
                            ->columns(2)
                            ->columnSpanFull()
                            ->defaultItems(0)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            // Children disabled + repeater not dehydrated: no
                            // edits to existing rows reach the DB. Single write
                            // path stays the observer (recordBarcodeChange).
                            ->disabled()
                            ->dehydrated(false)
                            ->schema([
                                // previous_barcode is NOT NULL in the schema.
                                Forms\Components\TextInput::make('previous_barcode')
                                    ->label('Old barcode')
                                    ->required()
                                    ->maxLength(64),
                                Forms\Components\TextInput::make('new_barcode')
                                    ->label('New barcode')
                                    ->maxLength(64),
                                // Status columns are ENUM(IN, OUT, PERM_OUT) on
                                // MySQL — restrict to the valid set so a manual
                                // row cannot violate the enum.
                                Forms\Components\Select::make('previous_status')
                                    ->label('Status from')
                                    ->options(array_combine(Box::BARCODE_STATUSES, Box::BARCODE_STATUSES)),
                                Forms\Components\Select::make('new_status')
                                    ->label('Status to')
                                    ->options(array_combine(Box::BARCODE_STATUSES, Box::BARCODE_STATUSES)),
                                Forms\Components\DateTimePicker::make('changed_at')
                                    ->label('Changed at')
                                    ->default(now()),
                                Forms\Components\TextInput::make('reason')
                                    ->label('Reason')
                                    ->maxLength(255),
                            ]),
                    ]),

                // F3 (review finding) — seal-number history is an append-only
                // audit trail written EXCLUSIVELY by the model observer
                // (Box::booted → recordSealChange) on every seal_number change.
                // READ-ONLY here so the form is not a second write path.
                Section::make('Seal legacy / history')
                    ->columns(1)
                    ->collapsed()
                    ->description('Seal No — date changed. Read-only — written automatically when the seal number changes.')
                    ->visible(fn (): bool => (bool) auth()->user()?->can('update_box'))
                    ->schema([
                        Forms\Components\Repeater::make('sealNumberHistory')
                            ->relationship()
                            ->hiddenLabel()
                            ->columns(2)
                            ->columnSpanFull()
                            ->defaultItems(0)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->disabled()
                            ->dehydrated(false)
                            ->schema([
                                Forms\Components\TextInput::make('old_value')
                                    ->label('Seal from')
                                    ->maxLength(255),
                                Forms\Components\TextInput::make('new_value')
                                    ->label('Seal to')
                                    ->maxLength(255),
                                Forms\Components\DateTimePicker::make('changed_at')
                                    ->label('Date changed')
                                    ->default(now()),
                                Forms\Components\Textarea::make('notes')
                                    ->label('Notes')
                                    ->rows(2)
                                    ->columnSpanFull(),
                            ]),
                    ]),

                // Feedback1 Wave C2.4 — if a box is marked destroyed, a destroy
                // date is mandatory. The DatePicker becomes required the moment
                // a destruction reason is entered (the operator's signal that
                // the box is being destroyed); the model guard (Box::booted)
                // enforces the same rule on every other write path.
                Section::make('Destruction')
                    ->columns($twoCols)
                    ->collapsed()
                    ->description('Mark the box as physically destroyed (RFQ App.2 §vii). A destroy date is mandatory once destroyed.')
                    ->schema([
                        $g(Forms\Components\DateTimePicker::make('destroyed_at')
                            ->label('Destroy date')
                            ->helperText('Mandatory when the box is marked destroyed.')
                            ->required(fn (Get $get): bool => filled($get('destroyed_reason')))
                            ->live()),
                        $g(Forms\Components\Textarea::make('destroyed_reason')
                            ->label('Reason / where destroyed')
                            ->rows(2)
                            ->live(onBlur: true)
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

                // Custom fields (EAV, per-repository).
                // For Box the repository is derived from its batch (spec §Architecture).
                //
                // Repository resolution order (GROUP A fix):
                //   1. Live form state: Batch::find($get('batch_id'))->repository_id
                //      batch_id is ->live() (declared above) so the Section re-renders
                //      whenever the operator picks a different batch.
                //   2. Fallback to the loaded record's batch repository (on edit,
                //      before any batch selection change).
                //   3. Fallback to the user's default repository (on create, no batch yet).
                Section::make('Custom fields')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema(static function (Get $get, ?Box $record): array {
                        $batchId = $get('batch_id');
                        $repositoryId = ($batchId ? Batch::find($batchId)?->repository_id : null)
                            ?? $record?->batch?->repository_id
                            ?? auth()->user()?->default_repository_id;

                        return CustomFieldSchema::for('box', $repositoryId !== null ? (int) $repositoryId : null);
                    })
                    ->visible(static function (Get $get, ?Box $record): bool {
                        $batchId = $get('batch_id');
                        $repositoryId = ($batchId ? Batch::find($batchId)?->repository_id : null)
                            ?? $record?->batch?->repository_id
                            ?? auth()->user()?->default_repository_id;

                        return count(CustomFieldSchema::for('box', $repositoryId !== null ? (int) $repositoryId : null)) > 0;
                    }),
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

                // Bug #36 — additional (many-to-many) parent boxes, shown when set.
                Section::make('Additional parent boxes')
                    ->visible(fn (?Box $record): bool => (bool) $record?->parents()->exists())
                    ->schema([
                        TextEntry::make('parents.box_number')
                            ->label('Assembled from')
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
                        TextEntry::make('seal_number')
                            ->label('Seal #')
                            ->badge()
                            ->color('primary')
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

                // RFQ App.2 §vii — "Box destroyed" provenance block.
                // Only rendered after the box has been physically destroyed:
                // before that point there's nothing to show, and the action
                // itself is offered from the page header / row action.
                Section::make('Destruction')
                    ->columns($twoCols)
                    ->visible(fn (?Box $record): bool => (bool) $record?->isDestroyed())
                    ->schema([
                        TextEntry::make('destroyed_at')
                            ->label('Destroyed at')
                            ->dateTime()
                            ->badge()
                            ->color('danger')
                            ->placeholder('—'),
                        TextEntry::make('destroyedBy.name')
                            ->label('Destroyed by')
                            ->badge()
                            ->color('gray')
                            ->placeholder('—'),
                        TextEntry::make('destroyed_reason')
                            ->label('Reason / where destroyed')
                            ->prose()
                            ->placeholder('No reason recorded.')
                            ->columnSpanFull(),
                    ]),

                // Custom fields (EAV, per-repository) — view/infolist section.
                // For Box the repository is derived from its batch (spec §Architecture).
                Section::make('Custom fields')
                    ->columns($twoCols)
                    ->schema(static function (?Box $record): array {
                        if ($record === null) {
                            return [];
                        }
                        $data = $record->getCustomFieldData();
                        if (empty($data)) {
                            return [];
                        }
                        $entries = [];
                        foreach ($record->customFieldDefinitions()->get() as $def) {
                            $value = $data[$def->key] ?? null;
                            if ($value === null) {
                                continue;
                            }
                            $displayValue = match ($def->type) {
                                'boolean' => $value ? 'Yes' : 'No',
                                'date' => $value instanceof Carbon ? $value->toDateString() : (string) $value,
                                'datetime' => $value instanceof Carbon ? $value->toDateTimeString() : (string) $value,
                                default => (string) $value,
                            };
                            $entries[] = TextEntry::make('cf_' . $def->key)
                                ->label($def->label)
                                ->state($displayValue)
                                ->placeholder('—');
                        }

                        return $entries;
                    })
                    ->visible(static function (?Box $record): bool {
                        if ($record === null) {
                            return false;
                        }
                        $data = $record->getCustomFieldData();

                        return ! empty(array_filter($data, fn ($v) => $v !== null));
                    }),

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
            // A6 — allow drag-and-drop column reorder by the operator.
            ->reorderableColumns()
            // Feedback1 Wave B (B1) — persist & defer filters so they survive
            // navigation/refresh and so the cross-module landings (Batch → Boxes)
            // present a stable, query-string-driven filter state.
            ->deferFilters()
            ->persistFiltersInSession()
            // A7 — keep the filter panel visible even when the result set is
            // empty, so the operator can still adjust/clear their criteria.
            ->filtersLayout(FiltersLayout::BeforeContentCollapsible)
            // Feedback1 Wave B (B4) — clicking a box row navigates to the
            // Documents dashboard showing that box's contents. Documents'
            // `current_box_id` SelectFilter is `->multiple()`, so the URL shape
            // uses `values` (an array). View / Edit / Move / Destroy stay
            // reachable via the row actions column below.
            ->recordUrl(fn (Box $record): string => DocumentResource::getUrl('index', [
                'filters' => ['current_box_id' => ['values' => [$record->getKey()]]],
            ]))
            ->columns([
                // A6 — Column order per spec: Batch / Box / Barcode / Barcode Status /
                // Disinfestation Date / Box Type / Destroyed / Parent Box Id / Is Legacy.
                // All columns are toggleable so operators can hide what they don't need.
                $gc(Tables\Columns\TextColumn::make('batch.batch_number')
                    ->label('Batch')
                    ->numeric()
                    ->sortable()
                    ->toggleable(), 'batch_id'),
                $gc(Tables\Columns\TextColumn::make('box_number')
                    ->label('Box')
                    ->searchable()
                    // Bug #2 — a combined "Batch then Box" sort: clicking Box orders
                    // by the parent batch number first, then the box number.
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query
                        ->orderByLeftPowerJoins('batch.batch_number', $direction)
                        ->orderBy('box_number', $direction))
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('barcode')
                    ->label('Barcode')
                    ->searchable()
                    ->sortable()
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('barcode_status')
                    ->label('Barcode Status')
                    ->sortable()
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('disinfestation_date')
                    ->label('Disinfestation Date')
                    ->date()
                    ->sortable()
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('box_type')
                    ->label('Box Type')
                    ->sortable()
                    ->toggleable()),
                // RFQ App.2 §vii — "destroyed" badge. Shown as a red
                // chip on the row so operators can spot artefacts that
                // physically no longer exist without opening the record.
                // Hidden when null (no badge at all) to keep the table
                // visually quiet for the common "active" case.
                Tables\Columns\TextColumn::make('destroyed_at')
                    ->label('Destroyed')
                    ->dateTime()
                    ->badge()
                    ->color('danger')
                    ->placeholder('')
                    ->sortable()
                    ->toggleable(),
                $gc(Tables\Columns\TextColumn::make('parent_box_id')
                    ->label('Parent Box Id')
                    ->numeric()
                    ->sortable()
                    ->toggleable()),
                $gc(Tables\Columns\IconColumn::make('is_legacy')
                    ->label('Is Legacy')
                    ->boolean()
                    ->sortable()
                    ->toggleable()),
                // A9 — Inputter column (who created this box record).
                CreatorColumn::make(),
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
                // Custom fields (EAV) — toggleable columns for active definitions.
                ...DocumentResource::customFieldTableColumns('box'),
            ])
            ->filters([
                // Feedback1 Wave B (B3) — `batch` SelectFilter. The Batch
                // dashboard navigates here with
                // ?tableFilters[batch][values][]=<id>, so the filter name MUST
                // be `batch` and it queries `batch_id` via the relationship.
                // `->multiple()` matches the `values` URL shape used by the
                // BatchResource recordUrl / "View boxes" action.
                SelectFilter::make('batch')
                    ->label('Batch')
                    ->relationship('batch', 'batch_number')
                    ->searchable()
                    ->multiple(),

                // Feedback1 Wave B (B1) — rich filter mechanism (#1) with
                // AND/OR/NOT nested groups and per-field dropdown constraints,
                // complementing the free-text search on box_number / barcode (#2).
                QueryBuilder::make()
                    ->constraints([
                        RelationshipConstraint::make('batch')
                            ->label('Batch')
                            ->selectable(
                                IsRelatedToOperator::make()
                                    ->titleAttribute('batch_number')
                                    ->searchable()
                                    ->multiple(),
                            ),
                        SelectConstraint::make('box_type')
                            ->label('Box type')
                            ->options(array_combine(Box::TYPES, Box::TYPES))
                            ->multiple(),
                        TextConstraint::make('box_number')
                            ->label('Box number'),
                        TextConstraint::make('barcode')
                            ->label('Barcode'),
                        SelectConstraint::make('barcode_status')
                            ->label('Barcode status')
                            ->options(array_combine(Box::BARCODE_STATUSES, Box::BARCODE_STATUSES))
                            ->multiple(),
                        RelationshipConstraint::make('location')
                            ->label('Location')
                            ->selectable(
                                IsRelatedToOperator::make()
                                    ->titleAttribute('name')
                                    ->searchable()
                                    ->multiple(),
                            ),
                    ]),

                // RFQ App.2 §vii — destroyed / active filter. Default is
                // "All" so users see the full archive; flip to "Active"
                // to restrict to the physical inventory.
                TernaryFilter::make('destroyed_at')
                    ->label('Destruction')
                    ->placeholder('All')
                    ->trueLabel('Destroyed')
                    ->falseLabel('Active')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('destroyed_at'),
                        false: fn ($query) => $query->whereNull('destroyed_at'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                // Feedback1 Wave B (B4) — explicit "View documents" row action:
                // a discoverable alternative to the whole-row recordUrl.
                Action::make('viewDocuments')
                    ->label('View documents')
                    ->icon('heroicon-o-rectangle-stack')
                    ->color('gray')
                    ->url(fn (Box $record): string => DocumentResource::getUrl('index', [
                        'filters' => ['current_box_id' => ['values' => [$record->getKey()]]],
                    ])),
                // Feedback1 Wave B (B6) — "Add document to this box": jump to
                // the Document create form with current_box_id pre-filled.
                // Gated on the document create permission; hidden on destroyed
                // boxes (you cannot file a new document into a destroyed box).
                self::addDocumentAction('addDocumentRow'),
                // RFQ §3.1.6 — Move box to a different location (audited).
                MoveBoxToLocationAction::make(),
                // RFQ App.2 §vii — single-record "Mark as destroyed" row
                // action. The action's own visible() callback hides it on
                // already-destroyed rows, so we always register it here.
                DestroyBoxAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    BulkAction::make('relocate')
                        ->label('Relocate boxes')
                        ->icon('heroicon-o-arrows-right-left')
                        // Gate on the same permission the model policy uses for edits.
                        ->authorize(fn (): bool => (bool) (auth()->user()?->can('update_box')))
                        ->form([
                            // Repository-scoped + active locations only (same as the main
                            // form field) so a bulk relocate can't reach another tenant's
                            // or an inactive location.
                            SearchableSelects::location(
                                'location_id',
                                fn ($query) => $query
                                    ->active()
                                    ->forRepository(auth()->user()?->default_repository_id),
                            )->required(fn (Get $get): bool => (bool) $get('set_perm_out')),
                            Forms\Components\Toggle::make('set_perm_out')
                                ->label('Mark barcode as PERM OUT')
                                ->live(),
                            // RFQ Appendix-1 #2: a PERM_OUT box needs a disinfestation date
                            // and a location. Enforce both here instead of bypassing them.
                            Forms\Components\DatePicker::make('disinfestation_date')
                                ->label('Disinfestation date')
                                ->helperText('Required when marking boxes PERM OUT (RFQ A1.2).')
                                ->visible(fn (Get $get): bool => (bool) $get('set_perm_out'))
                                ->required(fn (Get $get): bool => (bool) $get('set_perm_out')),
                            Forms\Components\TextInput::make('tracking_note')
                                ->label('Tracking note')
                                ->maxLength(255),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            // Server-side guard mirroring the form filter AND the
                            // per-row MoveBoxToLocationAction: a forged submit
                            // carrying an inactive / soft-deleted / missing location
                            // id must be rejected before any box is touched, so a
                            // bulk relocate can never land boxes on a disabled
                            // location.
                            $location = null;
                            if (filled($data['location_id'] ?? null)) {
                                /** @var Location|null $location */
                                $location = Location::withoutGlobalScopes()->find((int) $data['location_id']);
                                if ($location === null || $location->trashed() || ! $location->is_active) {
                                    Notification::make()
                                        ->title('Cannot relocate — target location not found, deleted or inactive')
                                        ->danger()
                                        ->send();

                                    return;
                                }
                            }

                            // Review finding: count what actually changed so the
                            // notification is honest — a submit that leaves every
                            // field blank must not report success. $skipped counts
                            // boxes withheld by the cross-repository tenant guard.
                            $changed = 0;
                            $skipped = 0;
                            $records->each(function (Box $record) use ($data, $location, &$changed, &$skipped): void {
                                $update = [];

                                if ($location !== null) {
                                    // Tenant isolation: a repository-scoped location
                                    // may only be assigned to a box in the SAME
                                    // repository (global locations go anywhere).
                                    // Mirrors MoveBoxToLocationAction's guard.
                                    if ($location->repository_id !== null
                                        && (int) $location->repository_id !== (int) $record->customFieldRepositoryId()) {
                                        $skipped++;

                                        return;
                                    }

                                    $update['location_id'] = $location->getKey();
                                }

                                if (! empty($data['set_perm_out'])) {
                                    $update['barcode_status'] = 'PERM_OUT';
                                    if (filled($data['disinfestation_date'] ?? null)) {
                                        $update['disinfestation_date'] = $data['disinfestation_date'];
                                    }
                                }

                                if (filled($data['tracking_note'] ?? null)) {
                                    $existing = trim((string) ($record->notes ?? ''));
                                    $update['notes'] = $existing !== ''
                                        ? $existing . "\n" . $data['tracking_note']
                                        : $data['tracking_note'];
                                }

                                if (! empty($update)) {
                                    $record->update($update);
                                    $changed++;
                                }
                            });

                            if ($changed === 0) {
                                Notification::make()
                                    ->title('No boxes changed')
                                    ->body($skipped > 0
                                        ? $skipped . ' ' . ($skipped === 1 ? 'box belongs' : 'boxes belong') . ' to a different repository than the target location.'
                                        : 'Every field was left blank, so nothing was updated.')
                                    ->warning()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title($changed . ' ' . ($changed === 1 ? 'box' : 'boxes') . ' relocated')
                                ->body($skipped > 0
                                    ? $skipped . ' skipped (different repository than the target location).'
                                    : null)
                                ->success()
                                ->send();
                        })
                        ->requiresConfirmation()
                        ->modalHeading('Relocate boxes')
                        // Review finding: confirmation must match blast radius —
                        // state the count and that PERM OUT is a permanent change.
                        ->modalDescription(fn (Collection $records): string => 'You are about to update '
                            . $records->count() . ' ' . ($records->count() === 1 ? 'box' : 'boxes')
                            . '. Marking them PERM OUT is a permanent custody change (RFQ A1.2).')
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Feedback1 Wave B (B6) — "Add document to this box" action factory.
     *
     * Redirects to the Document create form with `current_box_id` pre-filled
     * from the box (CreateDocument reads it from the query string). Used both
     * as a Box row action and as a ViewBox header action. Gated on the
     * document create permission and hidden on physically destroyed boxes.
     */
    public static function addDocumentAction(string $name): Action
    {
        return Action::make($name)
            ->label('Add document to this box')
            ->icon('heroicon-o-document-plus')
            ->color('primary')
            ->visible(fn (Box $record): bool => DocumentResource::canCreate() && ! $record->isDestroyed())
            ->url(fn (Box $record): string => DocumentResource::getUrl('create', [
                'current_box_id' => $record->getKey(),
            ]));
    }

    /**
     * Feedback1 gaps (misc) — codes of legacy box types, driven by the
     * `box_types` lookup `is_legacy` flag, with {@see Box::LEGACY_TYPES} as a
     * fallback when the lookup table is empty / not yet seeded. Used to decide
     * when the `is_legacy` Toggle is relevant in the form.
     *
     * @return list<string>
     */
    public static function legacyBoxTypeCodes(): array
    {
        $codes = BoxType::query()->where('is_legacy', true)->pluck('code')->all();

        return $codes !== [] ? array_values($codes) : Box::LEGACY_TYPES;
    }

    /**
     * Feedback1 Wave B (B5) — helper text listing the box numbers already used
     * in the selected batch, so the operator can pick a free one. Excludes the
     * current record on edit. Capped to keep the hint readable.
     */
    public static function usedBoxNumbersHelper(?int $batchId, int|string|null $ignoreId = null): string
    {
        if ($batchId === null) {
            return 'Pick a batch first; box numbers must be unique within a batch.';
        }

        $used = Box::query()
            ->where('batch_id', $batchId)
            ->when($ignoreId, fn ($q, $id) => $q->whereKeyNot($id))
            ->orderBy('box_number')
            ->pluck('box_number')
            ->filter(fn ($n): bool => $n !== null && $n !== '')
            ->unique()
            ->values();

        if ($used->isEmpty()) {
            return 'No box numbers used in this batch yet.';
        }

        $shown = $used->take(30)->implode(', ');
        $suffix = $used->count() > 30 ? ', …' : '';

        return "Used in this batch: {$shown}{$suffix}";
    }

    public static function getRelations(): array
    {
        return [
            BoxResource\RelationManagers\BarcodeHistoryRelationManager::class,
            BoxResource\RelationManagers\SealNumberHistoryRelationManager::class,
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

    /**
     * Eager-load customFieldValues.definition and audits to avoid N+1 in
     * table columns (custom fields + A9 CreatorColumn).
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'customFieldValues.definition',
            'batch',
            // A9 — CreatorColumn resolves the inputter via the first audit row.
            'audits' => static fn ($q) => $q->where('event', 'created')->oldest('id')->with('user'),
        ]);
    }
}
