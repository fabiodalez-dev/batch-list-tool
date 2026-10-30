<?php

namespace App\Filament\Resources;

use App\Filament\Actions\Boxes\DestroyBoxAction;
use App\Filament\Actions\Boxes\MoveBoxToLocationAction;
use App\Filament\Concerns\AppliesFieldPermissions;
use App\Filament\Resources\BoxResource\Pages;
use App\Filament\Support\CompactLayout;
use App\Filament\Support\CreatorColumn;
use App\Filament\Support\EqualsFirst;
use App\Filament\Support\SearchableSelects;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Location;
use App\Models\Lookup\BarcodeStatus;
use App\Models\Lookup\BoxType;
use App\Models\Lookup\CurrentBoxType;
use App\Support\ActiveRepository;
use App\Support\ColumnLabels\ColumnLabels;
use App\Support\CustomFields\CustomFieldSchema;
use App\Support\LocationBreadcrumbCache;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class BoxResource extends Resource
{
    use AppliesFieldPermissions;

    /** RFQ §3.1.8 — see config/field_permissions.php */
    private const string FIELD_PERMISSIONS_KEY = 'box';

    protected static ?string $model = Box::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static string|\UnitEnum|null $navigationGroup = 'Records';

    protected static ?int $navigationSort = 50;

    protected static ?string $recordTitleAttribute = 'box_number';

    public static function form(Schema $schema): Schema
    {
        // Same wrapping trick as the other resources. NOTE: `provenance_unknown`
        // IS gated via $g() — it is a real persisted column (added in A1.3) whose
        // visibility depends on box_type.
        $g = fn (Schemas\Components\Component $c): Schemas\Components\Component => self::gateField($c, self::FIELD_PERMISSIONS_KEY);

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
        $notOnCreate = fn (string $operation): bool => $operation !== 'create';

        // Client 2026-10-30: one compact Details section, its fields in the
        // order of the view; the read-only legacy histories follow on edit.
        return $schema
            ->columns(1)
            ->schema([
                Section::make(CompactLayout::DETAILS)
                    ->columns(CompactLayout::GRID)
                    ->schema([
                        // Client 2026-10-30: Box type and Box identifier side by side.
                        $g(Forms\Components\Select::make('box_type')->label(ColumnLabels::get('box', 'box_type'))
                            // C4 — keep the record's current (possibly inactive) value selectable on edit.
                            ->options(fn (?Box $record): array => BoxType::optionsWith($record?->box_type))
                            ->searchable()
                            ->required()
                            ->live()  // re-evaluate visibility/required of dependent fields
                            ->helperText('RAS / IN_SITU / NRA for new boxes. MAV / STVC are legacy-only and cannot be created.')
                            // RFQ Appendix-1 rule #4: legacy box types (MAV, STVC) cannot be
                            // assigned to *new* boxes. Existing legacy records must stay
                            // editable, so we only enforce this on CREATE.
                            ->rule(fn (?Box $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                                if ($record instanceof Box && $record->exists) {
                                    return; // edit: legacy stays editable
                                }
                                if ($value !== null && in_array((string) $value, Box::LEGACY_TYPES, true)) {
                                    $fail("Box type '{$value}' is a legacy type and cannot be assigned to new boxes (RFQ Appendix-1 rule #4). Allowed for create: " . implode(', ', array_diff(Box::TYPES, Box::LEGACY_TYPES)) . '.');
                                }
                            })),
                        // Feedback1 Wave B (B5) — the helper lists the numbers already
                        // taken in the selected batch so the operator can pick a free one.
                        $g(Forms\Components\TextInput::make('box_number')
                            ->required()
                            ->maxLength(32)
                            ->live(onBlur: true)
                            // C2.1 — label + meaning differ by type. For RAS it
                            // is a numeric box number; for IN_SITU / NRA it is the
                            // box IDENTIFIER (e.g. "NRA1", "MAV1") stored in the
                            // same column. The RAS branch follows the repository's
                            // own name for the column; the IN_SITU branch is what
                            // this one field means when the box has no number.
                            ->label(fn (Get $get): string => $isInSitu($get)
                                ? 'Box identifier'
                                : ColumnLabels::get('box', 'box_number'))
                            // C2.1 — RAS box numbers are numeric only; IN_SITU /
                            // NRA identifiers are free-text (alphanumeric).
                            ->numeric(fn (Get $get): bool => $isRas($get))
                            ->helperText(fn (Get $get, ?Box $record): string => $isInSitu($get)
                                ? 'Identifier for this in-situ box, e.g. "NRA1" or "MAV1".'
                                : self::usedBoxNumbersHelper(
                                    $get('batch_id') !== null && $get('batch_id') !== '' ? (int) $get('batch_id') : null,
                                    $record?->getKey(),
                                ))
                            // Client 2026-10-30: a type and number is one box per
                            // batch — or, with no batch, per repository (two
                            // batch-less "NRA 500" had been accepted). The model's
                            // saving guard asks the same question on every path.
                            ->rule(static fn (Get $get, ?Box $record): \Closure => static function (string $attribute, $value, \Closure $fail) use ($get, $record): void {
                                $batchId = $get('batch_id');
                                $batchId = $batchId !== null && $batchId !== '' ? (int) $batchId : null;
                                $clash = Box::identityClash(
                                    $batchId,
                                    $batchId === null ? self::batchlessRepositoryId($record) : null,
                                    $get('box_type'),
                                    $value !== null ? (string) $value : null,
                                    $record?->getKey(),
                                );
                                if ($clash !== null) {
                                    $fail($clash->identityClashMessage());
                                }
                            })),
                        // Feedback1 Wave B (B5) — `->live()` so box_number's helper
                        // text + uniqueness re-evaluate when the batch changes.
                        $g(SearchableSelects::batch('batch_id', 'batch')
                            ->live()
                            // C2.1 — Batch is required for RAS boxes only; an
                            // IN_SITU / NRA box is identified by its own
                            // identifier + location, not by a batch.
                            ->required($isRas)
                            ->helperText(fn (Get $get): ?string => $isInSitu($get)
                                ? 'Optional for IN_SITU / NRA boxes.'
                                : 'Required for RAS boxes.')),
                        // Physical container type (distinct from the archival
                        // box_type). Client 2026-10-30: a dropdown fed by the
                        // Physical Box Type lookup; a legacy free-text value stays
                        // selectable on edit (optionsWith), so nothing is lost.
                        $g(Forms\Components\Select::make('current_box_type')
                            ->label(ColumnLabels::get('box', 'current_box_type'))
                            ->options(fn (?Box $record): array => CurrentBoxType::optionsWith($record?->current_box_type))
                            ->searchable()
                            ->nullable()),

                        // RFQ App.1 #3 applies to BOTH IN_SITU and NRA boxes — they
                        // each require a parent RAS box (or Provenance unknown).
                        // RFQ A1.3 — the documented escape hatch for a box whose
                        // origin RAS box is genuinely unknown; the model guard reads
                        // it to allow a null `parent_box_id`.
                        $g(Forms\Components\Toggle::make('provenance_unknown')
                            ->label(ColumnLabels::get('box', 'provenance_unknown'))
                            ->helperText('Only tick this if the RAS box of origin is genuinely unknown — RFQ A1.3 / Appendix-1 rule #3 escape hatch. Use sparingly.')
                            ->default(false)
                            ->live()
                            ->visible($isInSitu)),
                        // Client 2026-10-30: "select Batch and then Box". Not a
                        // column: it narrows the parent box list below and is
                        // filled from the current parent on edit.
                        Forms\Components\Select::make('parent_batch_id')
                            ->label('Parent box batch')
                            ->options(fn (): array => self::parentBatchOptions())
                            ->searchable()
                            ->preload()
                            ->dehydrated(false)
                            ->live()
                            ->afterStateHydrated(function (Forms\Components\Select $component, ?Box $record): void {
                                $batchId = $record?->parent_box_id !== null
                                    ? Box::withoutGlobalScopes()->whereKey($record->parent_box_id)->value('batch_id')
                                    : null;
                                $component->state($batchId !== null ? (int) $batchId : null);
                            })
                            ->afterStateUpdated(function (Set $set, Get $get, mixed $state): void {
                                $parentId = $get('parent_box_id');
                                if ($parentId === null || $parentId === '' || $state === null || $state === '') {
                                    return;
                                }
                                $parentBatch = Box::withoutGlobalScopes()->whereKey($parentId)->value('batch_id');
                                if ((int) $parentBatch !== (int) $state) {
                                    $set('parent_box_id', null);
                                }
                            })
                            ->helperText('Pick the batch, then its box.')
                            ->visible(fn (Get $get): bool => $isInSitu($get) && ! $get('provenance_unknown')),
                        // The stored field is still parent_box_id. With a batch
                        // picked the list holds that batch's RAS boxes ("Box 1 —
                        // RAS — IN"); without one it searches every RAS box.
                        $g(Forms\Components\Select::make('parent_box_id')
                            ->label('Parent RAS box')
                            ->options(fn (Get $get): array => self::parentBoxOptions($get('parent_batch_id')))
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search, Get $get): array => self::parentBoxOptions($get('parent_batch_id'), $search))
                            ->getOptionLabelUsing(fn ($value): ?string => SearchableSelects::boxOptionLabel($value))
                            // U3 — hide (not just de-require) when provenance is unknown.
                            ->visible(fn (Get $get) => $isInSitu($get) && ! $get('provenance_unknown'))
                            ->required(fn (Get $get) => $isInSitu($get) && ! $get('provenance_unknown'))
                            ->rule(fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                // Strict enforcement at validation time (defence in depth
                                // vs the ->required() above; covers API/bulk-import paths
                                // that don't go through the Filament Required validator).
                                if (! in_array($get('box_type'), ['IN_SITU', 'NRA'], true)) {
                                    return;
                                }
                                if (! $get('provenance_unknown') && empty($value)) {
                                    $fail('IN_SITU / NRA boxes must reference a parent RAS box (RFQ Appendix-1 rule #3). Tick "Provenance unknown" only if the origin RAS box is genuinely unknown.');

                                    return;
                                }
                                // The list only offers RAS boxes; this keeps a
                                // posted id to the same rule.
                                if (! empty($value) && Box::withoutGlobalScopes()->whereKey($value)->value('box_type') !== 'RAS') {
                                    $fail('The parent box must be a RAS box (RFQ Appendix-1 rule #3).');
                                }
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
                                $query->where('box_type', 'RAS')->with('batch');
                                if ($record instanceof Box) {
                                    $query->whereKeyNot($record->getKey());
                                }

                                return $query;
                            })
                            ->getOptionLabelFromRecordUsing(fn (Box $r): string => ($r->batch?->batch_number ? 'Batch ' . $r->batch->batch_number . ' / ' : '') . 'Box ' . $r->box_number)
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->visible($isInSitu)
                            ->columnSpan(CompactLayout::HALF)),

                        // A10 — Barcode is globally unique across all boxes (a
                        // duplicate triggers a friendly validation message).
                        $g(Forms\Components\TextInput::make('barcode')
                            ->label(ColumnLabels::get('box', 'barcode'))
                            ->helperText('Barcode label affixed to this box. Must be globally unique. Distinct from any per-document barcodes inside it.')
                            ->required(fn (Get $get): bool => $isRas($get))
                            ->maxLength(64)
                            ->rule(static fn (?Box $record): \Closure => static function (string $attribute, $value, \Closure $fail) use ($record): void {
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
                            })),
                        // RFQ Contract App.2-i — the yellow security seal that
                        // closes the box belongs to the BOX; every change is
                        // logged to box_seal_number_history (see Seal history).
                        $g(Forms\Components\TextInput::make('seal_number')
                            ->label(ColumnLabels::get('box', 'seal_number'))
                            ->maxLength(255)
                            ->helperText('Yellow security seal closing the box. Changes are kept in the seal history.')),
                        // Client 2026-10-30: only a RAS box (and the legacy types
                        // treated like one) has a barcode status. Hidden, not
                        // cleared: a hidden field is not saved, so a status
                        // already stored on another box type is left as it is.
                        $g(Forms\Components\Select::make('barcode_status')->label(ColumnLabels::get('box', 'barcode_status'))
                            // C4 — keep the record's current (possibly inactive) value selectable on edit.
                            ->options(fn (?Box $record): array => BarcodeStatus::optionsWith($record?->barcode_status))
                            ->searchable()
                            ->required()
                            ->live()
                            ->default('IN')
                            ->visible($isRas)),
                        // Feedback1 gaps (misc) — Is legacy only matters for legacy
                        // box types (MAV / STVC); kept visible when the record is
                        // already flagged so historical rows still render it. Client
                        // 2026-10-30: no legacy features on a new box.
                        $g(Forms\Components\Toggle::make('is_legacy')->label(ColumnLabels::get('box', 'is_legacy'))
                            ->helperText('Marks data migrated from the legacy spreadsheet; must be on for legacy box types (MAV / STVC).')
                            ->default(false)
                            ->visible(fn (Get $get, ?Box $record, string $operation): bool => $operation !== 'create'
                                && (in_array($get('box_type'), self::legacyBoxTypeCodes(), true) || (bool) $record?->is_legacy))),

                        // RFQ §3.1.9 — Configurable Location Hierarchies, scoped to
                        // the user's default repository AND global locations.
                        // Client 2026-08-05: optional for every box type.
                        $g(SearchableSelects::location(
                            'location_id',
                            fn ($query) => $query
                                ->active()
                                ->forRepository(auth()->user()?->default_repository_id),
                        )
                            ->label('Location (RFQ §3.1.9)')
                            ->helperText('Optional. Repository / room / shelf / showcase / temp-holding hierarchy.')
                            ->columnSpan(CompactLayout::HALF)),

                        $g(Forms\Components\Textarea::make('notes')->label(ColumnLabels::get('box', 'notes'))
                            ->rows(3)
                            ->columnSpan(CompactLayout::HALF)),
                        // Client feedback 2026-08-04: a Tracking Note kept
                        // separate from the general note (movement / stock-take).
                        $g(Forms\Components\Textarea::make('tracking_note')
                            ->label(ColumnLabels::get('box', 'tracking_note'))
                            ->helperText('Movement / stock-take tracking, kept separate from the general note.')
                            ->rows(3)
                            ->columnSpan(CompactLayout::HALF)),

                        // Feedback1 Wave C2.4 — if a box is marked destroyed, a destroy
                        // date is mandatory (the model guard enforces the same rule on
                        // every other write path). Client 2026-10-30: not on a new box.
                        $g(Forms\Components\DateTimePicker::make('destroyed_at')
                            ->label('Destroy date')
                            ->helperText('Marks the box physically destroyed (RFQ App.2 §vii). Mandatory once destroyed.')
                            ->required(fn (Get $get): bool => filled($get('destroyed_reason')))
                            ->live()
                            ->visible($notOnCreate)),
                        $g(Forms\Components\Textarea::make('destroyed_reason')
                            ->label('Reason / where destroyed')
                            ->rows(2)
                            ->live(onBlur: true)
                            ->visible($notOnCreate)
                            ->columnSpan(CompactLayout::HALF)),
                    ]),

                // F3 (review finding) — the barcode-history log is an
                // append-only audit trail written EXCLUSIVELY by the model
                // observer (Box::booted → recordBarcodeChange) on every
                // barcode / status change. This Repeater is READ-ONLY so the
                // form is not a second, conflicting write path. Client
                // 2026-10-30: not shown on a new box, which has no history.
                Section::make('Barcode legacy / history')
                    ->columns(1)
                    ->collapsed()
                    ->description('Old Barcode N : Status. Read-only — written automatically when the barcode/status changes.')
                    ->visible(fn (): bool => (bool) auth()->user()?->can('update_box'))
                    ->hiddenOn('create')
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
                    ->hiddenOn('create')
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
                                    ->label(ColumnLabels::get('box', 'notes'))
                                    ->rows(2)
                                    ->columnSpanFull(),
                            ]),
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

                        return CustomFieldSchema::for('box', $repositoryId !== null ? (int) $repositoryId : null) !== [];
                    }),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        // Client 2026-10-30: Identification, Additional parent boxes, Barcode &
        // status, Location, Counts, Notes and Destruction condensed into one
        // section, in the order of the form; Audit info on its own, closed.
        // Every FK gets a clickable URL to its Resource view.
        $isInSitu = fn (?Box $record): bool => in_array($record?->box_type, ['IN_SITU', 'NRA'], true);
        $isDestroyed = fn (?Box $record): bool => (bool) $record?->isDestroyed();

        return $schema
            ->columns(1)
            ->components([
                Section::make(CompactLayout::DETAILS)
                    ->columns(CompactLayout::GRID)
                    ->schema([
                        TextEntry::make('box_type')
                            ->label(ColumnLabels::get('box', 'box_type'))
                            ->badge()
                            ->color('gray')
                            ->placeholder('—'),
                        TextEntry::make('box_number')
                            ->label(fn (?Box $record): string => $isInSitu($record) ? 'Box identifier' : ColumnLabels::get('box', 'box_number'))
                            ->badge()
                            ->color('primary')
                            ->copyable()
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
                        TextEntry::make('current_box_type')
                            ->label(ColumnLabels::get('box', 'current_box_type'))
                            ->placeholder('—'),

                        // RFQ App.1 #3 — the parent RAS box of an IN_SITU / NRA box.
                        IconEntry::make('provenance_unknown')
                            ->label(ColumnLabels::get('box', 'provenance_unknown'))
                            ->boolean()
                            ->visible($isInSitu),
                        TextEntry::make('parent.batch.batch_number')
                            ->label('Parent box batch')
                            ->badge()
                            ->color('gray')
                            ->placeholder('—')
                            ->visible($isInSitu),
                        TextEntry::make('parent.box_number')
                            ->label('Parent RAS box')
                            ->state(fn (?Box $record): ?string => $record?->parent !== null ? SearchableSelects::boxShortLabel($record->parent) : null)
                            ->badge()
                            ->color('warning')
                            ->url(fn (?Box $record): ?string => $record?->parent_box_id
                                ? route('filament.admin.resources.boxes.view', ['record' => $record->parent_box_id])
                                : null)
                            ->openUrlInNewTab(false)
                            ->placeholder('Provenance lost')
                            ->visible($isInSitu),
                        // Bug #36 — additional (many-to-many) parent boxes.
                        TextEntry::make('assembled_from')
                            ->label('Additional parent boxes')
                            ->state(fn (?Box $record): array => $record === null ? [] : $record->parents()->with('batch')->orderBy('boxes.id')->get()->map(fn (Box $p): string => $p->label())->all())
                            ->badge()
                            ->color('gray')
                            ->placeholder('—')
                            ->visible(fn (?Box $record): bool => $isInSitu($record) || (bool) $record?->parents()->exists()),

                        TextEntry::make('barcode')
                            ->label(ColumnLabels::get('box', 'barcode'))
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('seal_number')
                            ->label(ColumnLabels::get('box', 'seal_number'))
                            ->badge()
                            ->color('primary')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('barcode_status')
                            ->label(ColumnLabels::get('box', 'barcode_status'))
                            // No barcode, no status (same rule as the list).
                            ->state(fn (?Box $record): ?string => filled($record?->barcode) ? $record->barcode_status : null)
                            ->badge()
                            ->color(fn (?string $state): string => match ($state) {
                                'IN' => 'success',
                                'OUT' => 'warning',
                                'PERM_OUT' => 'danger',
                                default => 'gray',
                            })
                            ->placeholder('—'),
                        IconEntry::make('is_legacy')
                            ->label(ColumnLabels::get('box', 'is_legacy'))
                            ->boolean(),

                        TextEntry::make('location.full_path')
                            ->label('Location')
                            // Same as the list: a box with no location of its own
                            // shows the one all its documents share.
                            ->state(fn (?Box $record): ?string => $record?->location?->full_path ?? $record?->sharedDocumentLocation()?->full_path)
                            ->belowContent(fn (?Box $record): ?string => $record !== null && $record->location === null && $record->sharedDocumentLocation() !== null ? 'From its documents' : null)
                            // Gate the link on the resolved relation, not the raw
                            // FK — an orphaned location_id must not link to a
                            // record that no longer exists (matches the list column).
                            ->url(fn (?Box $record): ?string => ($loc = $record?->location ?? $record?->sharedDocumentLocation())
                                ? route('filament.admin.resources.locations.view', ['record' => $loc->getKey()])
                                : null)
                            ->openUrlInNewTab(false)
                            ->placeholder('—')
                            ->columnSpan(CompactLayout::HALF),
                        TextEntry::make('documents_count')
                            ->label('Documents')
                            ->state(fn (?Box $record): int => $record?->documents()->count() ?? 0)
                            ->badge()
                            ->color('gray'),
                        // The sheet's rows for this box that have since gone
                        // into an In Situ box are counted here too; "View
                        // documents" lists these.
                        TextEntry::make('documents_now_or_earlier_count')
                            ->label('Documents, including those moved on')
                            ->state(fn (?Box $record): int => $record !== null ? Document::query()->inBox([$record->getKey()])->count() : 0)
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('children_count')
                            ->label('Child IN_SITU boxes')
                            ->state(fn (?Box $record): int => $record?->children()->count() ?? 0)
                            ->badge()
                            ->color('gray'),

                        TextEntry::make('notes')->label(ColumnLabels::get('box', 'notes'))
                            ->prose()
                            ->placeholder('No notes.')
                            ->columnSpan(CompactLayout::HALF),
                        // Client feedback 2026-08-04: Tracking Note, separate
                        // from the general note (movement / stock-take).
                        TextEntry::make('tracking_note')
                            ->label(ColumnLabels::get('box', 'tracking_note'))
                            ->prose()
                            ->placeholder('No tracking note.')
                            ->columnSpan(CompactLayout::HALF),

                        // RFQ App.2 §vii — shown only once the box has been
                        // physically destroyed; the action itself is offered from
                        // the page header / row action.
                        TextEntry::make('destroyed_at')
                            ->label('Destroyed at')
                            ->dateTime()
                            ->badge()
                            ->color('danger')
                            ->placeholder('—')
                            ->visible($isDestroyed),
                        TextEntry::make('destroyedBy.name')
                            ->label('Destroyed by')
                            ->badge()
                            ->color('gray')
                            ->placeholder('—')
                            ->visible($isDestroyed),
                        TextEntry::make('destroyed_reason')
                            ->label('Reason / where destroyed')
                            ->prose()
                            ->placeholder('No reason recorded.')
                            ->visible($isDestroyed)
                            ->columnSpan(CompactLayout::HALF),
                    ]),

                // Custom fields (EAV, per-repository) — view/infolist section.
                // For Box the repository is derived from its batch (spec §Architecture).
                Section::make('Custom fields')
                    ->columns(CompactLayout::GRID)
                    ->schema(static function (?Box $record): array {
                        if (! $record instanceof Box) {
                            return [];
                        }
                        $data = $record->getCustomFieldData();
                        if ($data === []) {
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
                        if (! $record instanceof Box) {
                            return false;
                        }
                        $data = $record->getCustomFieldData();

                        return ! empty(array_filter($data, fn ($v) => $v !== null));
                    }),

                CompactLayout::auditInfolist(),
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
            // Client 2026-10-19: the filters open in a side panel.
            ->filtersLayout(FiltersLayout::Modal)
            ->filtersTriggerAction(fn (Action $action): Action => $action->slideOver())
            // Feedback1 Wave B (B4) — clicking a box row navigates to the
            // Documents dashboard showing that box's contents: the `in_box`
            // filter, so documents that went on into an In Situ box are listed
            // too (client 2026-10-28). It is `->multiple()`, so the URL shape
            // uses `values` (an array). View / Edit / Move / Destroy stay
            // reachable via the row actions column below.
            ->recordUrl(fn (Box $record): string => self::documentsUrl($record))
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
                    ->label(ColumnLabels::get('box', 'box_number'))
                    ->searchable()
                    // Bug #2 — a combined "Batch then Box" sort: clicking Box orders
                    // by the parent batch number first, then the box number.
                    // Client 2026-08-10 — box_number is a string, so a plain
                    // lexicographic sort gives 1, 10, 11, 2, … Cast to an integer
                    // so the numbers order naturally (1, 2, 3, …, 10, 11); the raw
                    // box_number is the tie-breaker for the handful of non-numeric
                    // ids (1A, 2A, "NULL", which cast to their leading integer / 0).
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        $dir = strtolower($direction) === 'desc' ? 'desc' : 'asc';

                        return $query
                            ->orderByLeftPowerJoins('batch.batch_number', $direction)
                            ->orderByRaw("CAST(box_number AS UNSIGNED) {$dir}")
                            ->orderBy('box_number', $direction);
                    })
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('barcode')
                    ->label(ColumnLabels::get('box', 'barcode'))
                    ->searchable()
                    ->sortable()
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('barcode_status')
                    ->label(ColumnLabels::get('box', 'barcode_status'))
                    // Client 2026-10-18: a box with no barcode (In Situ, NRA,
                    // STVC…) has no barcode status; the column's database
                    // default 'IN' is not shown for it.
                    ->formatStateUsing(fn (?string $state, Box $record): ?string => filled($record->barcode) ? $state : '—')
                    ->sortable()
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('box_type')
                    ->label(ColumnLabels::get('box', 'box_type'))
                    ->sortable()
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('current_box_type')
                    ->label(ColumnLabels::get('box', 'current_box_type'))
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)),
                // The import template carries a `location` column (resolved by
                // code), so operators can set where a box physically lives on
                // import — surface it here too, otherwise that imported value is
                // invisible on the list. Uses the same full_path accessor +
                // deep-link as the view page. Not sortable: full_path is an
                // accessor, not a DB column. Gated on the same field key as the
                // form's location_id select.
                $gc(Tables\Columns\TextColumn::make('location.full_path')
                    ->label('Location')
                    // Memoise the breadcrumb by location id instead of
                    // dot-accessing the accessor per row, which runs an
                    // ancestors() query each time (schema audit — N+1).
                    ->state(fn (?Box $record): ?string => LocationBreadcrumbCache::for($record?->location ?? $record?->sharedDocumentLocation()))
                    // Client 2026-10-18: a box without a location of its own
                    // (a destroyed RAS box, say) shows where all its documents are.
                    ->description(fn (?Box $record): ?string => $record !== null && $record->location === null && $record->sharedDocumentLocation() !== null ? 'from documents' : null)
                    ->placeholder('—')
                    ->toggleable()
                    // Gate the link on the RESOLVED relation, not the raw FK: an
                    // orphaned location_id renders a blank cell, so it must not
                    // link to a location record that no longer exists.
                    ->url(fn (?Box $record): ?string => ($loc = $record?->location ?? $record?->sharedDocumentLocation())
                        ? route('filament.admin.resources.locations.view', ['record' => $loc->getKey()])
                        : null), 'location_id'),
                $gc(Tables\Columns\TextColumn::make('tracking_note')
                    ->label(ColumnLabels::get('box', 'tracking_note'))
                    ->limit(60)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('—')
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('seal_number')
                    ->label(ColumnLabels::get('box', 'seal_number'))
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('notes')
                    ->label(ColumnLabels::get('box', 'notes'))
                    ->limit(60)
                    ->tooltip(fn (?string $state): ?string => $state)
                    ->placeholder('—')
                    ->toggleable()),
                // RFQ App.2 §vii — "destroyed" badge. Shown as a red
                // chip on the row so operators can spot artefacts that
                // physically no longer exist without opening the record.
                // Hidden when null (no badge at all) to keep the table
                // visually quiet for the common "active" case.
                // Client 2026-08-18 (Charlene): show a boolean Yes/No, not the
                // raw destroyed_at datetime (the exact date is on the View page).
                // getStateUsing() fires for every row, so an active box (null
                // destroyed_at) correctly reads "No" instead of a blank cell.
                Tables\Columns\TextColumn::make('destroyed_at')
                    ->label('Destroyed')
                    ->badge()
                    ->getStateUsing(fn (Box $record): string => $record->isDestroyed() ? 'Yes' : 'No')
                    ->color(fn (string $state): string => $state === 'Yes' ? 'danger' : 'gray')
                    ->sortable()
                    ->toggleable(),
                // The parent as people know it (type, number, barcode), not its
                // internal id: "6,088" told the operator nothing.
                $gc(Tables\Columns\TextColumn::make('parent_box_id')
                    ->label('Parent box')
                    // A box assembled from several RAS boxes has no single
                    // parent: the list names them instead of a dash.
                    ->getStateUsing(fn (Box $record): ?string => match (true) {
                        $record->parent !== null => $record->parent->label(),
                        $record->parents->isNotEmpty() => 'Assembled from ' . $record->parents->map(fn (Box $p): string => $p->label())->implode(', '),
                        default => null,
                    })
                    ->url(fn (Box $record): ?string => $record->parent_box_id ? static::getUrl('view', ['record' => $record->parent_box_id]) : null)
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable()),
                $gc(Tables\Columns\IconColumn::make('is_legacy')
                    ->label(ColumnLabels::get('box', 'is_legacy'))
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
                            ->label(ColumnLabels::get('box', 'box_type'))
                            ->options(fn (): array => BoxType::filterOptions())
                            ->multiple(),
                        EqualsFirst::text('box_number', ColumnLabels::get('box', 'box_number')),
                        EqualsFirst::text('barcode', ColumnLabels::get('box', 'barcode')),
                        SelectConstraint::make('barcode_status')
                            ->label(ColumnLabels::get('box', 'barcode_status'))
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
                // Deleted (archived) boxes: hidden by default, shown here to be
                // restored or deleted for good.
                TrashedFilter::make()->label('Archived (deleted) boxes'),
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
                    ->url(fn (Box $record): string => self::documentsUrl($record)),
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
                RestoreAction::make(),
                ForceDeleteAction::make()
                    ->modalDescription('The box is deleted for good, with its barcode, seal and location history.')
                    ->failureNotificationTitle('Not deleted: the box still contains documents. Move or delete them first.'),
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
                            )
                                // A normal relocation must have a target location
                                // (otherwise the action just updates the note and
                                // moves nothing). It's optional ONLY when marking
                                // PERM OUT — a perm-out/destroyed box no longer
                                // needs a box-level location (client feedback 2026-08-01).
                                ->required(fn (Get $get): bool => ! (bool) $get('set_perm_out')),
                            Forms\Components\Toggle::make('set_perm_out')
                                ->label('Mark barcode as PERM OUT')
                                ->live(),
                            // Location + disinfestation are OPTIONAL even when marking
                            // PERM OUT — the RFQ App.1 #5 preconditions were dropped
                            // (client feedback 2026-08-01, later than the RFQ). Both
                            // fields stay available so an operator can still record
                            // them, but a perm-out/destroyed box no longer needs them.
                            Forms\Components\TextInput::make('tracking_note')
                                ->label(ColumnLabels::get('box', 'tracking_note'))
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
                                }

                                if (filled($data['tracking_note'] ?? null)) {
                                    // Client feedback 2026-08-04: relocation notes
                                    // go to the dedicated tracking_note, no longer
                                    // appended to the general note.
                                    $existing = trim((string) ($record->tracking_note ?? ''));
                                    $update['tracking_note'] = $existing !== ''
                                        ? $existing . "\n" . $data['tracking_note']
                                        : $data['tracking_note'];
                                }

                                if ($update !== []) {
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
                    // Delete only archives a box: an import of the same box
                    // brings it back, history and all. From the Archived filter
                    // a box can be restored, or deleted for good.
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make()
                        ->modalDescription('The selected boxes are deleted for good, with their barcode, seal and location history. A box that still contains documents is kept.'),
                ]),
            ]);
    }

    /**
     * The Documents list showing this box's documents, the ones that have
     * gone on from it into an In Situ box included.
     */
    public static function documentsUrl(Box $box): string
    {
        // A "Box (now or earlier) is" rule of Add rule (client 2026-10-29:
        // the Documents filters are rules only).
        return DocumentResource::getUrl('index', [
            'filters' => ['queryBuilder' => ['match' => 'all', 'rules' => ['box' => [
                'type' => 'in_box',
                'data' => ['operator' => 'is', 'settings' => ['values' => [$box->getKey()]]],
            ]]]],
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
     * Batches holding at least one RAS box, for the "Parent box batch" picker
     * (client 2026-10-30: pick the batch, then the box).
     *
     * @return array<int, string>
     */
    public static function parentBatchOptions(): array
    {
        return Batch::query()
            ->whereHas('boxes', fn (Builder $q): Builder => $q->where('box_type', 'RAS'))
            ->orderByRaw('batch_number + 0')
            ->orderBy('batch_number')
            ->get()
            ->mapWithKeys(fn (Batch $b): array => [$b->getKey() => 'Batch ' . $b->batch_number])
            ->all();
    }

    /**
     * RAS boxes a parent may be picked from: every box of the chosen batch,
     * labelled "Box 1 — RAS — IN", or without a batch the first matches over
     * all RAS boxes, labelled with their batch.
     *
     * @return array<int|string, string>
     */
    public static function parentBoxOptions(mixed $batchId, string $search = ''): array
    {
        if ($batchId === null || $batchId === '') {
            return SearchableSelects::boxSearchResults($search, fn ($q) => $q->where('box_type', 'RAS'));
        }

        $search = trim($search);

        return Box::query()
            ->where('box_type', 'RAS')
            ->where('batch_id', (int) $batchId)
            ->when($search !== '', fn (Builder $q): Builder => $q->where(fn (Builder $w): Builder => $w
                ->where('box_number', 'like', '%' . $search . '%')
                ->orWhere('barcode', 'like', '%' . $search . '%')))
            ->orderByRaw('CAST(box_number AS UNSIGNED)')
            ->orderBy('box_number')
            ->get()
            ->mapWithKeys(fn (Box $b): array => [$b->getKey() => SearchableSelects::boxShortLabel($b)])
            ->all();
    }

    /**
     * The repository a batch-less box is saved under — the record's own, else
     * the one the model's saving hook stamps (active repository, then the
     * user's default) — so the form's uniqueness rule compares the same set.
     */
    public static function batchlessRepositoryId(?Box $record): ?int
    {
        $id = $record?->repository_id
            ?? app(ActiveRepository::class)->id()
            ?? auth()->user()?->default_repository_id;

        return $id !== null ? (int) $id : null;
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
            // The whole chronology first; the per-log tabs follow for detail.
            BoxResource\RelationManagers\HistoryRelationManager::class,
            BoxResource\RelationManagers\BarcodeHistoryRelationManager::class,
            BoxResource\RelationManagers\SealNumberHistoryRelationManager::class,
            BoxResource\RelationManagers\LocationHistoryRelationManager::class,
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
    /**
     * An archived box must open from the Archived filter to be restored or
     * deleted for good, as documents already do.
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'customFieldValues.definition',
            'batch',
            // The Parent box column prints the parent's type, number and barcode
            // — or, with no single parent, the boxes it was assembled from.
            'parent.batch',
            'parents.batch',
            // The Location column renders `location.full_path` (an accessor that
            // walks the location's ancestors); eager-load the relation so the
            // default box list doesn't fire one query per row.
            'location',
            // A9 — CreatorColumn resolves the inputter via the first audit row.
            'audits' => static fn ($q) => $q->oldest('id')->with('user'),
        ]);
    }
}
