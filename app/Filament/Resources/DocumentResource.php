<?php

namespace App\Filament\Resources;

use App\Filament\Actions\Documents\DocumentActionGroup;
use App\Filament\Actions\Documents\MarkDisinfestedAction;
use App\Filament\Actions\Documents\MoveToBoxAction;
use App\Filament\Concerns\AppliesFieldPermissions;
use App\Filament\Resources\DocumentResource\Pages;
use App\Filament\Support\SearchableSelects;
use App\Models\CustomFieldDefinition;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Lookup\CurrentBoxType;
use App\Models\Lookup\DigitisationStatus;
use App\Models\Practice;
use App\Models\Repository;
use App\Support\CustomFields\CustomFieldSchema;
use Carbon\Carbon;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Filters\QueryBuilder\Constraints\NumberConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\RelationshipConstraint\Operators\IsRelatedToOperator;
use Filament\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DocumentResource extends Resource
{
    use AppliesFieldPermissions;

    /**
     * Config key used by App\Support\FieldPermissions to look up
     * the per-field, per-role read/write/hidden matrix (RFQ §3.1.8).
     */
    private const FIELD_PERMISSIONS_KEY = 'document';

    /**
     * Canonical list of Document direct columns the omni-search bar covers.
     *
     * Defined once so unit tests can introspect the surface area and the
     * implementation does not silently drift from the documentation. All
     * entries are real `documents` columns (verified against the migration
     * stack — including the POC legacy columns kept for parity with the
     * raw-PHP schema). LIKE-matched against `%search%`.
     *
     * @var array<int,string>
     */
    private const OMNI_DIRECT_COLUMNS = [
        // Canonical normalised columns
        'identifier',
        'catalogue_identifier',
        'barcode_in',
        'document_type',
        'practice',
        'volume_number',
        'dates',
        'notes',
        'deeds',
        'nra_location',
        'museum_location',
        'accession_code_legacy',
        'object_reference_number',
        'tracking',
        'museum_reference',
        // POC legacy columns (parity with raw-PHP schema) — still surfaced
        // because the operator queries production data that was imported
        // verbatim from the legacy Excel sheets.
        'barcode_ras_1', 'barcode_ras_2', 'barcode_ras_3', 'barcode_ras_4',
        'barcode_in_2', 'barcode_ras_2_alt', 'barcode_ras_2_alt2',
        'status_1', 'status_2', 'status_3', 'status_4',
        'status_1_alt', 'status_2_alt',
        'in_situ_box_1', 'in_situ_box_2', 'in_situ_box_3',
        'ras_batch_1', 'ras_batch_2',
        'ras_box_1', 'ras_box_2',
    ];

    protected static ?string $model = Document::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Archive';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'identifier';

    public static function form(Schema $schema): Schema
    {
        // Local alias so each gate call stays a one-liner instead of
        // repeating the resource key constant 40+ times.
        $g = fn (Schemas\Components\Component $c): Schemas\Components\Component => self::gateField($c, self::FIELD_PERMISSIONS_KEY);

        // Layout rule (user mandate, do NOT regress):
        //   - root schema is single-column (columns(1)) — no Filament-default
        //     2-col where two Sections end up side-by-side.
        //   - each Section is a full-width band.
        //   - inside a Section, atomic inputs (Text/Select/Date/Toggle) may
        //     sit in columns(2) or columns(3); any non-atomic child
        //     (Textarea, KeyValue, multi-Select with chips, helperText-heavy
        //     input, Repeater) must take columnSpanFull() so it never sits
        //     in a sub-cell of a grid-in-a-grid.
        return $schema
            ->columns(1)
            ->schema([
                Section::make('Identification')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        $g(Forms\Components\TextInput::make('identifier')->required()->maxLength(64)),
                        $g(Forms\Components\TextInput::make('catalogue_identifier')->maxLength(191)),
                        $g(Forms\Components\Select::make('document_type')
                            ->label('Document type')
                            ->searchable()
                            ->options(fn (): array => DocumentType::query()
                                ->where('is_active', true)->orderBy('name')->pluck('name', 'name')->all())
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')->required()->maxLength(100)->unique(DocumentType::class, 'name'),
                                Forms\Components\Textarea::make('description')->maxLength(500),
                            ])
                            ->createOptionUsing(fn (array $data): string => DocumentType::create([
                                'name' => $data['name'], 'description' => $data['description'] ?? null, 'is_active' => true,
                            ])->name)),
                        $g(SearchableSelects::series('series_id')
                            ->label('Series')
                            ->required()),
                        $g(SearchableSelects::repository(
                            'repository_id',
                            fn ($query) => $query->whereIn(
                                'id',
                                auth()->user()?->hasAnyRole(['super_admin', 'admin'])
                                    ? Repository::query()->pluck('id')->all()
                                    : (auth()->user()?->repositories()->pluck('repositories.id')->all() ?? [])
                            ),
                        )
                            ->label('Repository')
                            ->required()
                            // live() so the Custom fields Section re-renders when
                            // the operator picks a different repository (GROUP A fix).
                            ->live()
                            ->default(fn () => auth()->user()?->default_repository_id)),
                        $g(Forms\Components\TextInput::make('volume_number')->label('Volume No')->maxLength(64)),
                        $g(Forms\Components\TextInput::make('part_number')->label('Part No')->maxLength(64)->nullable()),
                        $g(Forms\Components\Select::make('practice')
                            ->label('Practice')
                            ->searchable()
                            ->options(fn (): array => Practice::query()
                                ->where('is_active', true)->orderBy('name')->pluck('name', 'name')->all())
                            ->createOptionForm([
                                Forms\Components\TextInput::make('name')->required()->maxLength(100)->unique(Practice::class, 'name'),
                                Forms\Components\Textarea::make('description')->maxLength(500),
                            ])
                            ->createOptionUsing(fn (array $data): string => Practice::create([
                                'name' => $data['name'], 'description' => $data['description'] ?? null, 'is_active' => true,
                            ])->name)),
                        $g(Forms\Components\TextInput::make('dates')->label('Dates (text)')->maxLength(191)
                            ->helperText('Free-text dates as in POC, e.g. "1607-1629" or "Jun 1997 - Nov 1998"')
                            ->columnSpanFull()),
                        $g(Forms\Components\TextInput::make('deeds')->maxLength(2000)->columnSpanFull()),
                        $g(Forms\Components\TextInput::make('number_of_acts')->label('No of Acts')->maxLength(64)),
                        $g(Forms\Components\TextInput::make('pages_folios')->label('Pages/Folios')->maxLength(128)),
                    ]),

                // Feedback1 Wave C2.5 — editable history of PREVIOUS document
                // identifications AND volume numbers. Operators can record
                // prior identifiers / volumes the document was known by; the
                // documents dashboard search matches these past values too
                // (see applyOmniSearch + getGloballySearchableAttributes).
                // The DocumentObserver ALSO appends a row on every identifier
                // change, so this Repeater is an additive editing surface.
                // Gated to users with the document update permission.
                Section::make('Previous identifiers & volume numbers')
                    ->columnSpanFull()
                    ->collapsed()
                    ->description('Past identifications and volume numbers this document was known by. Searchable from the documents dashboard. More can be added.')
                    ->visible(fn (): bool => (bool) auth()->user()?->can('update_document'))
                    ->schema([
                        Forms\Components\Repeater::make('identifierHistory')
                            ->relationship()
                            ->hiddenLabel()
                            ->columns(2)
                            ->columnSpanFull()
                            ->defaultItems(0)
                            ->addActionLabel('Add previous identification')
                            ->schema([
                                // previous_identifier is NOT NULL in the schema.
                                Forms\Components\TextInput::make('previous_identifier')
                                    ->label('Previous identifier')
                                    ->required()
                                    ->maxLength(64),
                                Forms\Components\TextInput::make('new_identifier')
                                    ->label('New identifier')
                                    ->maxLength(64),
                                Forms\Components\TextInput::make('previous_volume')
                                    ->label('Previous volume number')
                                    ->maxLength(64),
                                Forms\Components\TextInput::make('new_volume')
                                    ->label('New volume number')
                                    ->maxLength(64),
                                Forms\Components\DateTimePicker::make('changed_at')
                                    ->label('Changed at')
                                    ->default(now()),
                                Forms\Components\TextInput::make('reason')
                                    ->label('Reason / note')
                                    ->maxLength(255),
                            ]),
                    ]),

                Section::make('Authorities (Creators)')
                    ->columnSpanFull()
                    ->columns(1)
                    ->schema([
                        $g(SearchableSelects::authoritiesMulti('authorities')->columnSpanFull()),
                    ]),

                Section::make('Current location')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        // F1 (review finding) — batch_id is READ-ONLY on EDIT,
                        // mirroring current_box_id below. The audited
                        // MoveToBoxAction keeps batch_id in sync with the box on
                        // every move; leaving the field editable would let an
                        // operator point a document at one box but a DIFFERENT
                        // batch, bypassing that invariant. On CREATE it stays
                        // editable (initial placement). Dehydrated only on create
                        // so an edit never writes a stale value.
                        $g(SearchableSelects::batch('batch_id', 'batch')
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->helperText(fn (string $operation): ?string => $operation === 'edit'
                                ? 'Locked. Use the "Move to box" action to change the box; the batch follows the box automatically (writes an audited movement record).'
                                : 'Initial batch for this document. After creation, the batch follows the box via the "Move to box" action.')),
                        // F1 (review finding) — current_box_id is READ-ONLY on
                        // EDIT so every box move is forced through the audited
                        // MoveToBoxAction (which writes a BoxMovement, keeps
                        // batch_id in sync, and rejects PERM_OUT / destroyed
                        // targets). On CREATE it stays editable so the Wave-B
                        // "add document to this box" prefill keeps working; the
                        // model `creating` guard validates the chosen box.
                        $g(SearchableSelects::box('current_box_id', 'currentBox')
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->helperText(fn (string $operation): ?string => $operation === 'edit'
                                ? 'Locked. Use the "Move to box" action to change the box (writes an audited movement record).'
                                : 'Initial box for this document. After creation, use the "Move to box" action to change it.')),
                        $g(SearchableSelects::accession('accession_id')),
                        $g(SearchableSelects::location(
                            'location_id',
                            fn ($query) => $query
                                ->active()
                                ->forRepository(auth()->user()?->default_repository_id),
                        )
                            ->label('Location (RFQ §3.1.9)')
                            ->nullable()
                            ->helperText('Repository / room / shelf / showcase / temp-holding hierarchy.')),
                        $g(Forms\Components\Select::make('current_box_type')
                            ->options(fn (): array => CurrentBoxType::options())
                            ->nullable()
                            ->helperText('Used for disinfestation planning: Big Brown Box counts as 2 boxes in the 250-box cycle limit.')),
                        $g(Forms\Components\Select::make('custody_status')
                            ->label('Custody status')
                            // Options are derived from the const so they stay in
                            // sync with the model; the label map keeps them readable.
                            ->options(collect(Document::CUSTODY_STATUSES)
                                ->mapWithKeys(fn (string $v): array => [$v => [
                                    'in_box' => 'In box',
                                    'not_in_box' => 'Not in box',
                                    'mounted_no_box' => 'Mounted; no box',
                                ][$v] ?? Str::headline($v)])
                                ->all())
                            ->default('in_box')
                            ->native(false)),
                        // Wave D5 — barcode, nra_location and museum_location removed from
                        // the form (NAf feedback: "can be removed"). The columns remain in the
                        // DB and in $fillable for import compatibility. dates_precise does not
                        // exist as a column anywhere (no-op note per Wave D spec).
                    ]),

                Section::make('Disinfestation')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        $g(Forms\Components\DatePicker::make('disinfestation_date')->label('Disinfestation (current)')),
                        $g(Forms\Components\Toggle::make('is_in_disinfestation')
                            ->label('Currently in disinfestation')
                            ->helperText("Set when the document is physically out for disinfestation. The 'Send to disinfestation' bulk action sets this automatically.")
                            ->columnSpanFull()),
                        $g(Forms\Components\DatePicker::make('disinfestation_date_1')->label('Legacy disinfestation #1')),
                        $g(Forms\Components\DatePicker::make('disinfestation_date_2')->label('Legacy disinfestation #2')),
                        $g(Forms\Components\DatePicker::make('disinfestation_date_3')->label('Legacy disinfestation #3')),
                    ]),

                Section::make('Dates (precise)')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        $g(Forms\Components\TextInput::make('dates_year_start')->label('Year start')->numeric()),
                        $g(Forms\Components\TextInput::make('dates_year_end')->label('Year end')->numeric()),
                        $g(Forms\Components\DatePicker::make('dates_start')->label('Date start')),
                        $g(Forms\Components\DatePicker::make('dates_end')->label('Date end')),
                    ]),

                Section::make('Cataloguing extras')
                    ->columnSpanFull()
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        $g(Forms\Components\TextInput::make('colour_code')->maxLength(32)),
                        $g(Forms\Components\Select::make('digitised')
                            ->options(fn (): array => DigitisationStatus::options())
                            ->nullable()
                            ->helperText('Digitisation source per RFQ APP2-xiii.')),
                        $g(Forms\Components\Toggle::make('torre')->columnSpanFull()),
                        $g(Forms\Components\TextInput::make('accession_code_legacy')->label('Accession (legacy text)')->maxLength(191)),
                        $g(Forms\Components\TextInput::make('object_reference_number')->maxLength(500)),
                        $g(Forms\Components\TextInput::make('tracking')->maxLength(500)->columnSpanFull()),
                        $g(Forms\Components\TextInput::make('museum_reference')->maxLength(500)->columnSpanFull()),
                    ]),

                Section::make('Attachments')
                    ->columnSpanFull()
                    ->description('Scans of the document (PDF, JPG, PNG, TIFF). Files are stored in the spatie/medialibrary `attachments` collection on this document.')
                    ->schema([
                        $g(SpatieMediaLibraryFileUpload::make('attachments')
                            ->collection('attachments')
                            ->multiple()
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/tiff', 'image/tif'])
                            ->maxSize(20 * 1024)
                            ->downloadable()
                            ->openable()
                            ->reorderable()
                            ->preserveFilenames()
                            ->columnSpanFull()),
                    ]),

                Section::make('Legacy box history (RAS / In Situ)')
                    ->columnSpanFull()
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        $g(Forms\Components\TextInput::make('ras_batch_1')->label('RAS Batch 1')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('ras_box_1')->label('RAS Box 1')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('ras_1_box_destroyed')->label('RAS 1 Destroyed?')->maxLength(10)),
                        $g(Forms\Components\TextInput::make('in_situ_box_1')->label('In Situ Box 1')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('in_situ_box_1_destroyed')->label('In Situ 1 Destroyed?')->maxLength(10)),
                        $g(Forms\Components\TextInput::make('ras_batch_2')->label('RAS Batch 2')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('ras_box_2')->label('RAS Box 2')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('ras_2_box_destroyed')->label('RAS 2 Destroyed?')->maxLength(10)),
                        $g(Forms\Components\TextInput::make('in_situ_box_2')->label('In Situ Box 2')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('in_situ_box_2_destroyed')->label('In Situ 2 Destroyed?')->maxLength(10)),
                        $g(Forms\Components\TextInput::make('in_situ_box_3')->label('In Situ Box 3')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('in_situ_box_3_destroyed')->label('In Situ 3 Destroyed?')->maxLength(10)),
                    ]),

                Section::make('Legacy barcodes & status')
                    ->columnSpanFull()
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        $g(Forms\Components\TextInput::make('barcode_in')->label('Barcode (IN)')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('barcode_in_2')->label('Barcode (IN) #2')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('barcode_ras_1')->label('Barcode RAS 1')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('status_1')->label('Status 1')->maxLength(20)),
                        $g(Forms\Components\TextInput::make('barcode_ras_2')->label('Barcode RAS 2')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('status_2')->label('Status 2')->maxLength(20)),
                        $g(Forms\Components\TextInput::make('barcode_ras_3')->label('Barcode RAS 3')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('status_3')->label('Status 3')->maxLength(20)),
                        $g(Forms\Components\TextInput::make('barcode_ras_4')->label('Barcode RAS 4')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('status_4')->label('Status 4')->maxLength(20)),
                        $g(Forms\Components\TextInput::make('barcode_ras_2_alt')->label('Barcode RAS 2 alt')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('status_1_alt')->label('Status 1 alt')->maxLength(20)),
                        $g(Forms\Components\TextInput::make('barcode_ras_2_alt2')->label('Barcode RAS 2 alt 2')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('status_2_alt')->label('Status 2 alt')->maxLength(20)),
                    ]),

                Section::make('Notes & custom fields')
                    ->columnSpanFull()
                    ->collapsed()
                    ->columns(1)
                    ->schema([
                        $g(Forms\Components\Textarea::make('notes')->columnSpanFull()->rows(3)),
                        $g(Forms\Components\KeyValue::make('extra')->label('Extra (schemaless)')->columnSpanFull()),
                        $g(Forms\Components\KeyValue::make('custom_fields')->label('Custom fields (POC json)')->columnSpanFull()),
                        $g(Forms\Components\KeyValue::make('metadata')->label('Metadata (POC json)')->columnSpanFull()),
                    ]),

                // Custom fields (EAV, per-repository).
                // Definitions are created by super_admin via the Repository admin panel.
                //
                // Repository resolution order (GROUP A fix):
                //   1. Live form state: $get('repository_id') — reflects the operator's
                //      current selection in real-time (repository_id is ->live()).
                //   2. Fallback to record repository_id (on edit, before any change).
                //   3. Fallback to the user's default repository (on create, nothing selected yet).
                //
                // The Section re-renders automatically whenever repository_id changes
                // because Get reads a ->live() field.
                Section::make('Custom fields')
                    ->columnSpanFull()
                    ->columns(2)
                    ->collapsed(false)
                    ->schema(static function (Get $get, ?Document $record): array {
                        $repositoryId = (int) $get('repository_id')
                            ?: $record?->repository_id
                            ?: auth()->user()?->default_repository_id;

                        return CustomFieldSchema::for('document', $repositoryId !== null ? (int) $repositoryId : null);
                    })
                    ->visible(static function (Get $get, ?Document $record): bool {
                        $repositoryId = (int) $get('repository_id')
                            ?: $record?->repository_id
                            ?: auth()->user()?->default_repository_id;

                        return count(CustomFieldSchema::for('document', $repositoryId !== null ? (int) $repositoryId : null)) > 0;
                    }),
            ]);
    }

    /**
     * Filament 5 Infolist schema for the View Document page (RFQ UX brief
     * — feat/view-document-redesign).
     *
     * The previous `/admin/documents/{id}` rendered the *form* schema in
     * read-only mode, which inherited the form's 2-column narrow inputs
     * and left ~50% of a wide screen empty. The Infolist API ships
     * read-only entries (`TextEntry`, `IconEntry`, `RepeatableEntry`)
     * with a layout grammar designed for dense scanning, plus first-class
     * support for badges, relationship-driven repeaters and inline links.
     *
     * Hero card → 5-column summary the operator sees first glance.
     * Then sections drilling into: document, authorities, storage,
     * disinfestation timeline, identifiers, legacy box/barcode history,
     * notes, audit info.
     */
    public static function infolist(Schema $schema): Schema
    {
        // Layout rule (user mandate, refined):
        //   - Root schema columns(1) → every top-level Section is a
        //     full-width band.
        //   - A full-width Section MAY arrange atomic entries on 2 columns
        //     from desktop (md+): columns(['default'=>1, 'md'=>2]).
        //   - A non-atomic child (RepeatableEntry, multi-Select chips,
        //     KeyValue, prose Notes) MUST take columnSpanFull() inside the
        //     2-col Section, AND its inner schema MUST be columns(1) so we
        //     never produce a grid-in-a-grid.
        //   - Every relationship gets a `->url()` to its own Resource view
        //     so the operator can click through (Authority, Box, Batch,
        //     Repository, Location, Accession, Series).
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->components([
                Section::make('Summary')
                    ->columns($twoCols)
                    ->extraAttributes(['class' => 'fi-section-hero'])
                    ->schema([
                        TextEntry::make('identifier')
                            ->label('Identifier')
                            ->badge()
                            ->color('primary')
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold)
                            ->copyable()
                            // RFQ Appendix-2 §xv — display the best-available
                            // identifier: catalogue_identifier first, then
                            // object_reference_number, then the legacy
                            // identifier column.
                            ->state(fn (?Document $record): ?string => $record?->display_identifier)
                            ->placeholder('—'),

                        TextEntry::make('primary_author_display')
                            ->label('Primary author')
                            ->state(function (?Document $record): string {
                                if (! $record) {
                                    return '—';
                                }
                                $authors = $record->authorities;
                                $primary = $authors->firstWhere('pivot.is_primary', 1)
                                    ?? $authors->firstWhere('pivot.is_primary', true)
                                    ?? $authors->first();
                                if (! $primary) {
                                    return '—';
                                }
                                $parts = array_filter([
                                    trim((string) ($primary->surname ?? '')),
                                    trim((string) ($primary->given_names ?? '')),
                                ]);
                                $name = implode(' ', $parts);
                                $ident = trim((string) ($primary->identifier ?? ''));

                                return $ident !== '' && $name !== ''
                                    ? "{$ident} — {$name}"
                                    : ($name !== '' ? $name : $ident);
                            })
                            ->placeholder('No author attached')
                            ->weight(FontWeight::SemiBold)
                            ->url(function (?Document $record): ?string {
                                $primary = $record?->authorities->firstWhere('pivot.is_primary', 1)
                                    ?? $record?->authorities->firstWhere('pivot.is_primary', true)
                                    ?? $record?->authorities->first();

                                return $primary
                                    ? route('filament.admin.resources.authorities.view', ['record' => $primary->id])
                                    : null;
                            })
                            ->openUrlInNewTab(false),

                        TextEntry::make('current_box_display')
                            ->label('Current box')
                            ->state(function (?Document $record): string {
                                $box = $record?->currentBox;
                                if (! $box) {
                                    return '—';
                                }
                                $batchNo = $box->batch?->batch_number;
                                $boxNo = $box->box_number;

                                return $batchNo !== null && $boxNo !== null
                                    ? "Batch {$batchNo} / Box {$boxNo}"
                                    : (string) ($boxNo ?? '—');
                            })
                            ->placeholder('Unboxed')
                            ->url(fn (?Document $record): ?string => $record?->current_box_id
                                ? route('filament.admin.resources.boxes.view', ['record' => $record->current_box_id])
                                : null)
                            ->openUrlInNewTab(false),

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
                            ->label('Disinfestation')
                            ->date()
                            ->badge()
                            ->color(fn (?string $state): string => $state ? 'success' : 'warning')
                            ->placeholder('Pending')
                            ->columnSpanFull(),
                    ]),

                Section::make('Document')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('catalogue_identifier')->label('Catalogue ID')->copyable()->placeholder('—'),
                        TextEntry::make('document_type')->placeholder('—'),
                        TextEntry::make('series.code')
                            ->label('Series')
                            ->badge()
                            ->url(fn (?Document $record): ?string => $record?->series_id
                                ? route('filament.admin.resources.series.view', ['record' => $record->series_id])
                                : null)
                            ->openUrlInNewTab(false)
                            ->placeholder('—'),
                        TextEntry::make('practice')->placeholder('—'),
                        TextEntry::make('volume_number')->label('Volume No')->placeholder('—'),
                        TextEntry::make('dates')->label('Dates (free text)')->placeholder('—'),
                        TextEntry::make('year_range_display')
                            ->label('Year range')
                            ->state(function (?Document $record): string {
                                $from = $record?->dates_year_start;
                                $to = $record?->dates_year_end;
                                if ($from && $to) {
                                    return "{$from} – {$to}";
                                }
                                if ($from) {
                                    return "from {$from}";
                                }
                                if ($to) {
                                    return "to {$to}";
                                }

                                return '—';
                            }),
                        TextEntry::make('dates_start')->label('Date start')->date()->placeholder('—'),
                        TextEntry::make('dates_end')->label('Date end')->date()->placeholder('—'),
                        TextEntry::make('deeds')->placeholder('—')->columnSpanFull(),
                    ]),

                Section::make('Authorities (Creators)')
                    ->columns(1)
                    ->description(fn (?Document $record): ?string => ($record && $record->authorities->isEmpty())
                        ? 'No authorities assigned to this document.'
                        : null)
                    ->schema([
                        RepeatableEntry::make('authorities')
                            ->hiddenLabel()
                            ->columns(1)
                            ->columnSpanFull()
                            ->schema([
                                TextEntry::make('identifier')
                                    ->badge()
                                    ->color('primary')
                                    ->weight(FontWeight::Bold)
                                    ->placeholder('—')
                                    ->url(fn (Model $record): ?string => route(
                                        'filament.admin.resources.authorities.view',
                                        ['record' => $record->id]
                                    ))
                                    ->openUrlInNewTab(false)
                                    ->columnSpanFull(),
                                IconEntry::make('pivot.is_primary')
                                    ->label('Primary')
                                    ->boolean()
                                    ->trueColor('success')
                                    ->falseColor('gray')
                                    ->columnSpanFull(),
                                TextEntry::make('surname')
                                    ->weight(FontWeight::SemiBold)
                                    ->placeholder('—')
                                    ->url(fn (Model $record): ?string => route(
                                        'filament.admin.resources.authorities.view',
                                        ['record' => $record->id]
                                    ))
                                    ->openUrlInNewTab(false)
                                    ->columnSpanFull(),
                                TextEntry::make('given_names')
                                    ->label('Given names')
                                    ->placeholder('—')
                                    ->columnSpanFull(),
                                TextEntry::make('practice_dates_display')
                                    ->label('Practice dates')
                                    ->state(function (Model $record): string {
                                        $start = $record->practice_dates_start;
                                        $end = $record->practice_dates_end;
                                        if ($start && $end) {
                                            return "{$start} – {$end}";
                                        }
                                        if ($start) {
                                            return "from {$start}";
                                        }
                                        if ($end) {
                                            return "to {$end}";
                                        }

                                        return '—';
                                    })
                                    ->columnSpanFull(),
                            ]),
                    ]),

                Section::make('Storage location')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('batch.batch_number')
                            ->label('Batch')
                            ->badge()
                            ->color('gray')
                            ->url(fn (?Document $record): ?string => $record?->batch_id
                                ? route('filament.admin.resources.batches.view', ['record' => $record->batch_id])
                                : null)
                            ->openUrlInNewTab(false)
                            ->placeholder('—'),
                        TextEntry::make('currentBox.box_number')
                            ->label('Current box')
                            ->badge()
                            ->color('gray')
                            ->url(fn (?Document $record): ?string => $record?->current_box_id
                                ? route('filament.admin.resources.boxes.view', ['record' => $record->current_box_id])
                                : null)
                            ->openUrlInNewTab(false)
                            ->placeholder('—'),
                        TextEntry::make('current_box_type')->label('Box type')->badge()->placeholder('—'),
                        TextEntry::make('repository.code')
                            ->label('Repository')
                            ->badge()
                            ->color('info')
                            ->url(fn (?Document $record): ?string => $record?->repository_id
                                ? route('filament.admin.resources.repositories.view', ['record' => $record->repository_id])
                                : null)
                            ->openUrlInNewTab(false)
                            ->placeholder('—'),
                        TextEntry::make('location.full_path')
                            ->label('Location')
                            ->placeholder('—')
                            ->url(fn (?Document $record): ?string => $record?->location_id
                                ? route('filament.admin.resources.locations.view', ['record' => $record->location_id])
                                : null)
                            ->openUrlInNewTab(false)
                            ->columnSpanFull(),
                        TextEntry::make('accession.code')
                            ->label('Accession')
                            ->badge()
                            ->color('gray')
                            ->url(fn (?Document $record): ?string => $record?->accession_id
                                ? route('filament.admin.resources.accessions.view', ['record' => $record->accession_id])
                                : null)
                            ->openUrlInNewTab(false)
                            ->placeholder('—'),
                        TextEntry::make('nra_location')
                            ->label('NRA location (legacy)')
                            ->placeholder('—')
                            ->columnSpanFull()
                            ->visible(fn (?Document $record): bool => filled($record?->nra_location)),
                        TextEntry::make('museum_location')
                            ->label('Museum location (legacy)')
                            ->placeholder('—')
                            ->columnSpanFull()
                            ->visible(fn (?Document $record): bool => filled($record?->museum_location)),
                    ]),

                Section::make('Disinfestation timeline')
                    ->columns($twoCols)
                    ->description(fn (?Document $record): ?string => ($record && $record->disinfestationTimeline()->isEmpty())
                        ? 'Document has never been disinfested — pending.'
                        : null)
                    ->schema([
                        TextEntry::make('disinfestation_date')
                            ->label('Canonical (current)')
                            ->date()
                            ->badge()
                            ->color(fn (?string $state): string => $state ? 'success' : 'warning')
                            ->placeholder('Pending'),
                        TextEntry::make('is_in_disinfestation')
                            ->label('Currently in disinfestation')
                            ->badge()
                            ->formatStateUsing(fn ($state): string => $state ? 'Yes' : 'No')
                            ->color(fn ($state): string => $state ? 'warning' : 'gray')
                            ->columnSpanFull(),
                        RepeatableEntry::make('disinfestation_timeline_rows')
                            ->label('History')
                            ->state(fn (?Document $record): array => $record
                                ? $record->disinfestationTimeline()
                                    ->map(fn (array $row) => [
                                        'date' => optional($row['date'])->format('Y-m-d'),
                                        'label' => $row['label'],
                                    ])
                                    ->all()
                                : [])
                            ->columns(1)
                            ->columnSpanFull()
                            ->schema([
                                TextEntry::make('date')->label('Date')->badge()->color('success')->columnSpanFull(),
                                TextEntry::make('label')->label('Round')->columnSpanFull(),
                            ])
                            ->visible(fn (?Document $record): bool => $record !== null
                                && $record->disinfestationTimeline()->isNotEmpty()),
                    ]),

                Section::make('Identifiers')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('identifier')->badge()->color('primary')->copyable()->placeholder('—'),
                        TextEntry::make('catalogue_identifier')->label('Catalogue ID')->copyable()->placeholder('—'),
                        // U1 — document barcode visible in read mode (was edit-only before).
                        // Custody status is authoritative from the box, not from this field.
                        TextEntry::make('barcode')
                            ->label('Document barcode (individual label)')
                            ->copyable()
                            ->placeholder('—')
                            ->helperText('Individual label barcode. Custody status is authoritative from the box.'),
                        TextEntry::make('barcode_in')->label('Barcode (IN)')->copyable()->placeholder('—'),
                    ]),

                Section::make('Legacy box history (RAS / In Situ)')
                    ->columns($twoCols)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('ras_batch_1')->label('RAS Batch 1')->placeholder('—'),
                        TextEntry::make('ras_box_1')->label('RAS Box 1')->placeholder('—'),
                        TextEntry::make('ras_1_box_destroyed')->label('RAS 1 destroyed?')->placeholder('—'),
                        TextEntry::make('in_situ_box_1')->label('In Situ Box 1')->placeholder('—'),
                        TextEntry::make('in_situ_box_1_destroyed')->label('In Situ 1 destroyed?')->placeholder('—'),
                        TextEntry::make('ras_batch_2')->label('RAS Batch 2')->placeholder('—'),
                        TextEntry::make('ras_box_2')->label('RAS Box 2')->placeholder('—'),
                        TextEntry::make('ras_2_box_destroyed')->label('RAS 2 destroyed?')->placeholder('—'),
                        TextEntry::make('in_situ_box_2')->label('In Situ Box 2')->placeholder('—'),
                        TextEntry::make('in_situ_box_2_destroyed')->label('In Situ 2 destroyed?')->placeholder('—'),
                        TextEntry::make('in_situ_box_3')->label('In Situ Box 3')->placeholder('—'),
                        TextEntry::make('in_situ_box_3_destroyed')->label('In Situ 3 destroyed?')->placeholder('—'),
                    ]),

                Section::make('Legacy barcodes & status')
                    ->columns($twoCols)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('barcode_in')->label('Barcode (IN)')->placeholder('—'),
                        TextEntry::make('barcode_in_2')->label('Barcode (IN) #2')->placeholder('—'),
                        TextEntry::make('barcode_ras_1')->label('Barcode RAS 1')->placeholder('—'),
                        TextEntry::make('status_1')->label('Status 1')->placeholder('—'),
                        TextEntry::make('barcode_ras_2')->label('Barcode RAS 2')->placeholder('—'),
                        TextEntry::make('status_2')->label('Status 2')->placeholder('—'),
                        TextEntry::make('barcode_ras_3')->label('Barcode RAS 3')->placeholder('—'),
                        TextEntry::make('status_3')->label('Status 3')->placeholder('—'),
                        TextEntry::make('barcode_ras_4')->label('Barcode RAS 4')->placeholder('—'),
                        TextEntry::make('status_4')->label('Status 4')->placeholder('—'),
                        TextEntry::make('barcode_ras_2_alt')->label('Barcode RAS 2 alt')->placeholder('—'),
                        TextEntry::make('status_1_alt')->label('Status 1 alt')->placeholder('—'),
                        TextEntry::make('barcode_ras_2_alt2')->label('Barcode RAS 2 alt 2')->placeholder('—'),
                        TextEntry::make('status_2_alt')->label('Status 2 alt')->placeholder('—'),
                    ]),

                Section::make('Cataloguing extras')
                    ->columns($twoCols)
                    ->collapsed()
                    ->schema([
                        TextEntry::make('colour_code')->label('Colour code')->placeholder('—'),
                        TextEntry::make('digitised')->placeholder('—'),
                        IconEntry::make('torre')->boolean(),
                        TextEntry::make('accession_code_legacy')->label('Accession (legacy)')->placeholder('—'),
                        TextEntry::make('object_reference_number')->label('Object reference #')->placeholder('—'),
                        TextEntry::make('tracking')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('museum_reference')->label('Museum reference')->placeholder('—')->columnSpanFull(),
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

                // Custom fields (EAV, per-repository) — view/infolist section.
                // Shows label → formatted value for every active definition that
                // has a stored value on this record. Hidden when the record has no
                // active definitions or no stored values (no empty section shown).
                Section::make('Custom fields')
                    ->columns($twoCols)
                    ->schema(static function (?Document $record): array {
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
                    ->visible(static function (?Document $record): bool {
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
        // Same wrapping trick as form(): keep the column declarations
        // single-line, route through the gate. For relationship columns
        // (`series.code`, `repository.code`) we pass the local FK column
        // name explicitly so the matrix can still gate it.
        $gc = fn (mixed $col, ?string $fieldOverride = null): mixed => self::gateColumn($col, self::FIELD_PERMISSIONS_KEY, $fieldOverride);

        return $table
            ->defaultSort('identifier')
            // Feedback1 Wave B (B1) — persist & defer filters so an applied
            // filter set (free text OR the rich filters below) is not lost on
            // navigation/refresh. This also makes the cross-module navigation
            // (Box → Documents) land on a stable, query-string-driven filter.
            ->deferFilters()
            ->persistFiltersInSession()
            // Feedback1 — let each user choose which columns are visible and
            // reorder them. Column visibility/order is the default per-user
            // Filament behaviour (persisted client-side).
            ->reorderableColumns()
            // Feedback1 — expose first/last page links in the paginator so
            // users can jump to the ends of the (large) document list.
            ->extremePaginationLinks()
            // Force the table search input on even though most columns below
            // intentionally drop `->searchable()` (the omni-search closure
            // wired via `searchUsing()` is now the single source of truth
            // for the top-right search bar).
            ->searchable()
            // RFQ §3.1.2 — omni-search across direct columns, joined
            // Authorities, Series, Batch, current Box, Location and open
            // Flags. Replaces the per-column `searchable()` LIKE chain so
            // typing "Abela" or "REG" or "needs_review" surfaces the right
            // documents from the operator's primary entry point.
            //
            // Implementation note: Filament 5 calls this closure from
            // `applyGlobalSearchToTableQuery()` WITHOUT wrapping it in a
            // `where(fn ($q) => ...)` group (unlike the default loop over
            // searchable columns). We add our own outer `where(fn)` so the
            // ORs do not leak into the surrounding AND-stack of filters,
            // table scopes and the RepositoryScope.
            ->searchUsing(static function (Builder $query, string $search): void {
                self::applyOmniSearch($query, $search);
            })
            ->columns([
                // Per-column `->searchable()` intentionally dropped on this
                // resource: the table-level `searchUsing()` callback above is
                // the single source of truth for the omni-search bar (RFQ
                // §3.1.2) and covers `identifier`, `document_type`,
                // `barcode_in`, `catalogue_identifier`, joined Authorities,
                // Series, Batch, Box, Location and Flags.
                $gc(Tables\Columns\TextColumn::make('identifier')
                    ->sortable()
                    ->copyable()
                    // RFQ Appendix-2 §xv — fall back to object_reference_number
                    // (then to the legacy identifier) when catalogue_identifier
                    // is null. Sorting / search remain on the canonical column.
                    ->state(fn (Document $record): ?string => $record->display_identifier)),
                $gc(Tables\Columns\TextColumn::make('document_type')->toggleable()),
                $gc(Tables\Columns\TextColumn::make('series.code')->label('Series')->badge()->sortable()->toggleable(), 'series_id'),
                $gc(Tables\Columns\TextColumn::make('batch.batch_number')->label('Batch')->sortable()->alignCenter()->toggleable(), 'batch_id'),
                $gc(Tables\Columns\TextColumn::make('currentBox.box_number')->label('Box')->toggleable(), 'current_box_id'),
                $gc(Tables\Columns\TextColumn::make('practice')->toggleable()),
                $gc(Tables\Columns\TextColumn::make('volume_number')->label('Vol.')->toggleable()),
                $gc(Tables\Columns\TextColumn::make('part_number')->label('Part No')->toggleable(isToggledHiddenByDefault: true)),
                $gc(Tables\Columns\TextColumn::make('dates')->label('Dates')->toggleable()->limit(30)),
                $gc(Tables\Columns\TextColumn::make('dates_year_start')->label('From')->numeric(thousandsSeparator: '')->sortable()->alignEnd()),
                $gc(Tables\Columns\TextColumn::make('dates_year_end')->label('To')->numeric(thousandsSeparator: '')->sortable()->alignEnd()),
                $gc(Tables\Columns\TextColumn::make('number_of_acts')->label('No of Acts')->toggleable(isToggledHiddenByDefault: true)),
                $gc(Tables\Columns\TextColumn::make('pages_folios')->label('Pages/Folios')->toggleable(isToggledHiddenByDefault: true)),
                $gc(Tables\Columns\TextColumn::make('barcode_in')->label('Barcode (IN)')->toggleable(isToggledHiddenByDefault: true)),
                $gc(Tables\Columns\TextColumn::make('catalogue_identifier')->label('Catalogue ID')->toggleable(isToggledHiddenByDefault: true)),
                $gc(Tables\Columns\TextColumn::make('repository.code')->label('Repo')->badge()->color('gray')->toggleable(), 'repository_id'),
                $gc(Tables\Columns\TextColumn::make('disinfestation_date')->label('Disinfested')->date()->sortable()->toggleable(isToggledHiddenByDefault: true)),
                $gc(Tables\Columns\IconColumn::make('torre')->boolean()->toggleable(isToggledHiddenByDefault: true)),
                $gc(Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true)),
                // Custom fields (EAV) — one TextColumn per active definition for
                // the current user's default repository (document entity type).
                // All hidden by default; operator can reveal via the column picker.
                // Eager-load customFieldValues.definition to avoid N+1
                // (see getEloquentQuery() for the base eager-load chain).
                ...self::customFieldTableColumns('document'),
            ])
            ->filtersFormColumns(3)
            ->filters([
                // Feedback1 Wave B (B1) — rich filter mechanism (#1) with
                // AND/OR/NOT nested groups and per-field dropdown constraints,
                // complementing the omni free-text search bar (#2). Covers the
                // key Document fields: series, batch, current box (relationship
                // dropdowns), document_type (text) and the precise year range.
                QueryBuilder::make()
                    ->constraints([
                        RelationshipConstraint::make('series')
                            ->label('Series')
                            ->selectable(
                                IsRelatedToOperator::make()
                                    ->titleAttribute('code')
                                    ->searchable()
                                    ->multiple(),
                            ),
                        RelationshipConstraint::make('batch')
                            ->label('Batch')
                            ->selectable(
                                IsRelatedToOperator::make()
                                    ->titleAttribute('batch_number')
                                    ->searchable()
                                    ->multiple(),
                            ),
                        RelationshipConstraint::make('currentBox')
                            ->label('Current box')
                            ->selectable(
                                IsRelatedToOperator::make()
                                    ->titleAttribute('box_number')
                                    ->searchable()
                                    ->multiple(),
                            ),
                        TextConstraint::make('document_type')
                            ->label('Document type'),
                        TextConstraint::make('dates')
                            ->label('Dates (free text)'),
                        NumberConstraint::make('dates_year_start')
                            ->label('Year start')
                            ->integer(),
                        NumberConstraint::make('dates_year_end')
                            ->label('Year end')
                            ->integer(),
                    ]),

                // Relationship multi-selects (parity with POC creators/series/batch filters)
                SelectFilter::make('series')
                    ->relationship('series', 'code')->searchable()->preload()->multiple(),

                // Heavy relations (669 boxes, 669 batches, 808 authorities):
                // server-side searchable() WITHOUT preload() to avoid
                // dumping the entire option list into the filter dropdown.
                // Same pattern as the form-side fix in SearchableSelects
                // (PR feat/ux-searchable-selects).
                SelectFilter::make('batch')
                    ->relationship('batch', 'batch_number')->searchable()->multiple(),

                SelectFilter::make('repository')
                    ->relationship('repository', 'code')->searchable()->preload(),

                SelectFilter::make('current_box_id')
                    ->label('Current box')
                    ->relationship('currentBox', 'box_number')->searchable()->multiple(),

                SelectFilter::make('accession_id')
                    ->label('Accession')
                    ->relationship('accession', 'code')->searchable()->preload(),

                SelectFilter::make('authorities')
                    ->label('Creators')
                    ->relationship('authorities', 'surname')->searchable()->multiple(),

                // Free-text search per field (POC-style filtri puntuali).
                // For columns covered by a single-column FULLTEXT index
                // (notes, deeds, museum_reference) we use the model scope:
                // on MySQL it expands to MATCH(...) AGAINST(... IN NATURAL
                // LANGUAGE MODE) and uses the FT index added by migration
                // 2026_05_18_100000; on other drivers it transparently falls
                // back to the same LIKE chain.
                // Short-string indexed columns (barcode_in, catalogue_identifier,
                // practice) keep the LIKE filter because they're already covered
                // by B-tree indexes and a FULLTEXT index on a VARCHAR(50) gives
                // no measurable gain.
                self::likeFilter('barcode_in', 'Search in Barcode (IN)'),
                self::likeFilter('catalogue_identifier', 'Search in Catalogue ID'),
                self::likeFilter('practice', 'Search in Practice'),
                self::fullTextFilter('notes', 'Search in Notes'),
                self::fullTextFilter('deeds', 'Search in Deeds'),
                self::fullTextFilter('museum_reference', 'Search in Museum Reference'),

                // volume_number is special — also searches the JSON path extra->volume; kept inline.
                Filter::make('volume_number')
                    ->form([
                        Forms\Components\TextInput::make('value')->label('Search in Volume No'),
                    ])
                    ->query(
                        fn (Builder $q, array $data) => $q->when(
                            $data['value'] ?? null,
                            fn ($q, $v) => $q->where(function ($q) use ($v) {
                                $needle = '%' . trim($v) . '%';
                                $q->where('volume_number', 'like', $needle)
                                    ->orWhere('extra->volume', 'like', $needle);
                            })
                        )
                    ),

                // Year range filter
                Filter::make('year_range')
                    ->form([
                        Forms\Components\TextInput::make('year_from')->label('Year from')->numeric(),
                        Forms\Components\TextInput::make('year_to')->label('Year to')->numeric(),
                    ])
                    ->query(function (Builder $q, array $data) {
                        return $q
                            ->when($data['year_from'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->whereNull('dates_year_end')
                                ->orWhere('dates_year_end', '>=', (int) $v)))
                            ->when($data['year_to'] ?? null, fn ($q, $v) => $q->where(fn ($q) => $q->whereNull('dates_year_start')
                                ->orWhere('dates_year_start', '<=', (int) $v)));
                    })
                    ->indicateUsing(function (array $data): array {
                        $i = [];
                        if (! empty($data['year_from'])) {
                            $i[] = "Year ≥ {$data['year_from']}";
                        }
                        if (! empty($data['year_to'])) {
                            $i[] = "Year ≤ {$data['year_to']}";
                        }

                        return $i;
                    }),

                // Disinfestation date range
                Filter::make('disinfestation_range')
                    ->form([
                        Forms\Components\DatePicker::make('disinfested_from')->label('Disinfested from'),
                        Forms\Components\DatePicker::make('disinfested_to')->label('Disinfested to'),
                    ])
                    ->query(function (Builder $q, array $data) {
                        return $q
                            ->when(
                                $data['disinfested_from'] ?? null,
                                fn ($q, $v) => $q->whereDate('disinfestation_date', '>=', $v)
                            )
                            ->when(
                                $data['disinfested_to'] ?? null,
                                fn ($q, $v) => $q->whereDate('disinfestation_date', '<=', $v)
                            );
                    }),

                // Ternary filters
                TernaryFilter::make('torre')
                    ->placeholder('Any')->trueLabel('Torre = yes')->falseLabel('Torre = no'),

                TernaryFilter::make('disinfestation_date')
                    ->label('Disinfested?')->nullable()
                    ->trueLabel('Yes')->falseLabel('No')
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('disinfestation_date'),
                        false: fn ($q) => $q->whereNull('disinfestation_date'),
                    ),

                // Workflow filter (RFQ App.1 #5 — disinfestation lifecycle).
                // Narrows to documents physically out for fumigation, so the
                // operator can bulk-close the cycle via "Mark disinfested".
                TernaryFilter::make('is_in_disinfestation')
                    ->label('Currently in disinfestation')
                    ->placeholder('Any')
                    ->trueLabel('Currently out for disinfestation')
                    ->falseLabel('Not currently out'),

                TernaryFilter::make('has_barcode')
                    ->label('Has barcode?')
                    ->placeholder('Any')->trueLabel('Yes')->falseLabel('No')
                    ->queries(
                        true: fn ($q) => $q->whereRaw("TRIM(COALESCE(barcode_in, '')) <> ''"),
                        false: fn ($q) => $q->whereRaw("TRIM(COALESCE(barcode_in, '')) = ''"),
                    ),

                TernaryFilter::make('has_box')
                    ->label('Assigned to box?')
                    ->placeholder('Any')->trueLabel('Yes')->falseLabel('No')
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('current_box_id'),
                        false: fn ($q) => $q->whereNull('current_box_id'),
                    ),

                TernaryFilter::make('has_notes')
                    ->label('Has notes?')
                    ->placeholder('Any')->trueLabel('Yes')->falseLabel('No')
                    ->queries(
                        true: fn ($q) => $q->whereRaw("TRIM(COALESCE(notes, '')) <> ''"),
                        false: fn ($q) => $q->whereRaw("TRIM(COALESCE(notes, '')) = ''"),
                    ),

                // RFQ APP2-xviii — narrow the list to docs carrying at least one
                // unresolved flag (status IN open|acknowledged). The false branch
                // surfaces docs that are either flag-free or whose flags have all
                // been resolved/dismissed.
                TernaryFilter::make('has_open_flags')
                    ->label('Open flags')
                    ->placeholder('All documents')
                    ->trueLabel('Has open flags')
                    ->falseLabel('No open flags')
                    ->queries(
                        true: fn ($q) => $q->whereHas('flags', fn ($f) => $f->whereIn('status', ['open', 'acknowledged'])),
                        false: fn ($q) => $q->whereDoesntHave('flags', fn ($f) => $f->whereIn('status', ['open', 'acknowledged'])),
                    ),

                // RFQ APP2-viii / REQ-3.2.2 — catalogue progress filter. The
                // operator needs to triage docs that still need a catalogue
                // identifier assigned. NULL is the "not yet catalogued" state.
                TernaryFilter::make('uncatalogued')
                    ->label('Catalogued?')
                    ->placeholder('All documents')
                    ->trueLabel('Catalogued (has catalogue_identifier)')
                    ->falseLabel('Uncatalogued (no catalogue_identifier yet)')
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('catalogue_identifier'),
                        false: fn ($q) => $q->whereNull('catalogue_identifier'),
                    ),

                // Soft-deleted records filter
                TrashedFilter::make(),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                // The two most-frequent single-record power-actions are
                // exposed as row actions for one-click access from the list.
                // The rest live behind the "Actions" dropdown on the Edit /
                // View page header to keep the row toolbar uncluttered.
                MoveToBoxAction::make('rowMoveToBox')
                    ->label('Move box')
                    ->iconButton(),
                MarkDisinfestedAction::make('rowMarkDisinfested')
                    ->label('Disinfested')
                    ->iconButton(),
            ])
            ->bulkActions([
                // The 14 Document power-actions (RFQ §3.1.1 / §3.1.4 /
                // §3.1.5) live in a dedicated bulk-action group so the
                // operator can act on tens-of-rows-at-a-time selections
                // without leaving the list page.
                BulkActionGroup::make(DocumentActionGroup::bulkActions())
                    ->label('Power actions')
                    ->icon('heroicon-o-bolt')
                    ->color('primary'),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            DocumentResource\RelationManagers\IdentifierHistoryRelationManager::class,
            DocumentResource\RelationManagers\FlagsRelationManager::class,
            DocumentResource\RelationManagers\BarcodeHistoryRelationManager::class,
        ];
    }

    /**
     * Eager-load identifierHistory + the relations consumed by
     * getGlobalSearchResultDetails() so the spotlight panel can render
     * "Authors / Series / Box" hints without an N+1 explosion across the
     * result set.
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()
            ->with(['identifierHistory', 'authorities', 'series', 'currentBox']);
    }

    /**
     * Apply eager-loading to the base query.
     *
     * The previous implementation called `conditionallyWith()` which ran
     * a `SELECT COUNT(*) ... LIMIT 201` on EVERY call to
     * `getEloquentQuery()`. Filament invokes this method dozens of times
     * per page render (one per column, filter, header action, livewire
     * poll, widget computation) — Debugbar caught it firing 291 times on
     * a single dashboard load. The "cheap" probe added up to ~700 ms of
     * pure overhead per request.
     *
     * Always eager-load. The production archive contains ~50k documents,
     * which means the conditional was effectively always-true anyway;
     * removing it just kills the overhead without changing the behaviour.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'series',
                'batch',
                // `currentBox.batch` lets the "Box" column render
                // "Batch X / Box Y" without firing a second query per row.
                'currentBox.batch',
                'repository',
                'authorities',
                // RFQ §3.1.9 — the Location column displays
                // `location.full_path`, an accessor that calls
                // `breadcrumb()` → `ancestors()` and runs a single
                // `whereIn` against `locations`. Without eager-loading
                // the Location itself, each row would also have to load
                // the relation BEFORE the accessor could walk it.
                'location',
                // The Accession column displays `accession.code`; on the
                // 3,113-row sample where most rows share ~10 accessions
                // this avoids 25 round-trips per page render.
                'accession',
                // Custom-field EAV: eager-load definition to avoid N+1 in
                // table columns and infolist rendering.
                'customFieldValues.definition',
            ]);
    }

    /**
     * Build toggleable TextColumn entries for every active custom-field
     * definition belonging to the given entity type and the current user's
     * default repository. All columns are hidden by default (operator reveals
     * them via the column picker). Returns an empty array when no definitions
     * exist so the spread operator in table() is a no-op.
     *
     * Used by DocumentResource::table() (and mirrored in the other resources).
     *
     * @return array<int, Tables\Columns\TextColumn>
     */
    public static function customFieldTableColumns(string $entityType): array
    {
        $repositoryId = auth()->user()?->default_repository_id;
        if ($repositoryId === null) {
            return [];
        }

        return CustomFieldDefinition::query()
            ->where('repository_id', $repositoryId)
            ->where('entity_type', $entityType)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(static function (CustomFieldDefinition $def): Tables\Columns\TextColumn {
                return Tables\Columns\TextColumn::make('customFieldValues_' . $def->key)
                    ->label($def->label)
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->placeholder('—')
                    ->state(static function ($record) use ($def): ?string {
                        if (! method_exists($record, 'customFieldValues')) {
                            return null;
                        }
                        // Use already-eager-loaded collection where possible.
                        $valueModel = $record->customFieldValues
                            ->firstWhere('custom_field_definition_id', $def->id);
                        if ($valueModel === null) {
                            return null;
                        }
                        $typed = $valueModel->getTypedValueAttribute();

                        return match ($def->type) {
                            'boolean' => $typed ? 'Yes' : 'No',
                            'date' => $typed instanceof Carbon ? $typed->toDateString() : (string) ($typed ?? ''),
                            'datetime' => $typed instanceof Carbon ? $typed->toDateTimeString() : (string) ($typed ?? ''),
                            default => $typed !== null ? (string) $typed : null,
                        };
                    });
            })
            ->all();
    }

    /**
     * Extend the global search bar (top-right of Filament panel) — POC parity
     * plus RFQ §3.1.2 omni-search parity. The list here MUST stay aligned
     * with {@see self::applyOmniSearch()} so that the spotlight panel and
     * the in-table search bar surface the same documents.
     */
    public static function getGloballySearchableAttributes(): array
    {
        return [
            // Direct columns
            'identifier',
            'catalogue_identifier',
            'document_type',
            'practice',
            'volume_number',
            'dates',
            'notes',
            'barcode_in',
            // Joined relations
            'series.code',
            'series.title',
            'authorities.surname',
            'authorities.given_names',
            'authorities.identifier',
            'authorities.alternative_identifier',
            'currentBox.box_number',
            'currentBox.barcode',
            // Accession (RFQ §3.2.1) — symmetric with applyOmniSearch so the
            // Cmd+K spotlight and the in-table bar return the same docs.
            'accession.code',
            // Identifier history (PR #8) — searching for "R7-old" finds the document
            // whose identifier was previously "R7-old", even after re-classification.
            'identifierHistory.previous_identifier',
            // Feedback1 Wave C2.5 — also surface documents by a PAST volume number.
            'identifierHistory.previous_volume',
        ];
    }

    /**
     * Show contextual hints in the spotlight (Cmd+K) global search results so
     * an operator can tell at a glance WHY a document matched: surfaces the
     * authors, the current box code, and the series code next to the title.
     *
     * @param Document $record
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        // The `authorities` and `currentBox.batch` relations are loaded
        // up-front via getGlobalSearchEloquentQuery() (see PR #8 +
        // identifierHistory eager-load). We use the in-memory collection
        // rather than ->pluck() to avoid one query per result row.
        $authors = $record->authorities
            ->map(fn ($a) => trim((string) $a->surname))
            ->filter()
            ->take(3)
            ->implode(', ');

        return array_filter([
            'Authors' => $authors !== '' ? $authors : null,
            'Series' => $record->series?->code,
            'Box' => $record->currentBox?->box_number,
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDocuments::route('/'),
            'create' => Pages\CreateDocument::route('/create'),
            'view' => Pages\ViewDocument::route('/{record}'),
            'edit' => Pages\EditDocument::route('/{record}/edit'),
        ];
    }

    /**
     * RFQ §3.1.2 — omni-search closure for the documents list page.
     *
     * Builds a single OR-group across:
     *   - {@see self::OMNI_DIRECT_COLUMNS} direct documents.* columns
     *   - authorities.(identifier|surname|given_names|alternative_identifier)
     *   - series.(code|title)
     *   - batches.batch_number (exact integer match when the search parses
     *     as a positive int, LIKE otherwise so "Batch 4" / "4" both match)
     *   - boxes.(box_number|barcode)
     *   - locations.(code|name)
     *   - document_flags.(type|title)
     *
     * Composes correctly with the surrounding AND-stack of filters / the
     * RepositoryScope because the whole OR group is wrapped in a single
     * `where(fn ($q) => ...)` closure. Results are de-duplicated via
     * `distinct()` so a document with N matching authorities still appears
     * once in the table — Eloquent's hydration step then re-loads each
     * row by id without double-counting.
     *
     * SQL-injection safe: the search value is bound through Eloquent's
     * parameterised LIKE binding and the `%` / `_` wildcards inside the
     * search term are escaped so a user searching for "100%" matches the
     * literal "100%" instead of every row.
     *
     * @internal Used by table()'s `searchUsing()`; static so the closure
     *           does not capture a reference to the resource instance.
     */
    public static function applyOmniSearch(Builder $query, string $search): void
    {
        $term = trim($search);
        if ($term === '') {
            return;
        }

        // Escape LIKE wildcards so user input like "100%" or
        // "OMSEARCH_REG" matches the literal substring instead of being
        // interpreted as a `%` / `_` pattern. Eloquent does NOT escape
        // these for us — only the surrounding bound parameter is sanitised
        // against SQL injection. We escape `%`, `_` and the escape char
        // itself (`!`) with a leading `!`; the matching `LIKE ? ESCAPE '!'`
        // clause is emitted by $likeEsc below.
        $needle = '%' . self::escapeForLike($term) . '%';

        // Integer-like terms get an exact-match path against batch_number
        // (PK-style lookup) AND a LIKE path so "4" matches both batch_number
        // = 4 and identifiers / codes containing "4". Same idea Filament
        // uses for numeric primary-key columns in its default search.
        $asInt = ctype_digit($term) ? (int) $term : null;

        // Helper: emits `col LIKE ? ESCAPE '\'` so a search for "100%" /
        // "OMSEARCH_REG" matches the literal substring instead of being
        // interpreted as a wildcard pattern. Works identically on SQLite
        // (test) and MySQL (prod).
        // ESCAPE clause uses `!` (bang) as the escape character — it is
        // ASCII-printable, never appears in real archive data, and avoids
        // the backslash quoting quirks that differ between SQLite (literal
        // 1-char) and MySQL/PDO (often doubled). The needle re-escape
        // below targets the same `!` character.
        $likeEsc = static function (Builder $q, string $col, string $pattern, bool $or = true): Builder {
            $method = $or ? 'orWhereRaw' : 'whereRaw';

            return $q->{$method}("{$col} LIKE ? ESCAPE '!'", [$pattern]);
        };

        $query->where(function (Builder $q) use ($needle, $asInt, $likeEsc): void {
            // Direct columns — first iteration uses `whereRaw` (not `or`)
            // to seed the inner WHERE group; subsequent rows OR onto it.
            $first = true;
            foreach (self::OMNI_DIRECT_COLUMNS as $col) {
                $likeEsc($q, 'documents.' . $col, $needle, ! $first);
                $first = false;
            }

            // Authorities (many-to-many via document_authority)
            $q->orWhereHas('authorities', static function (Builder $a) use ($needle, $likeEsc): void {
                $likeEsc($a, 'authorities.identifier', $needle, false);
                $likeEsc($a, 'authorities.alternative_identifier', $needle, true);
                $likeEsc($a, 'authorities.surname', $needle, true);
                $likeEsc($a, 'authorities.given_names', $needle, true);
            });

            // Series
            $q->orWhereHas('series', static function (Builder $s) use ($needle, $likeEsc): void {
                $likeEsc($s, 'series.code', $needle, false);
                $likeEsc($s, 'series.title', $needle, true);
            });

            // Accession (RFQ §3.2.1) — operator must be able to find every
            // document acquired under accession "ACC-2026-001" by typing the
            // accession code (or any substring) directly into the omni-search
            // bar. Accessions has no `title` column in the canonical schema —
            // `code` is the human label and `notes` carries free-form context.
            $q->orWhereHas('accession', static function (Builder $acc) use ($needle, $likeEsc): void {
                $likeEsc($acc, 'accessions.code', $needle, false);
                $likeEsc($acc, 'accessions.notes', $needle, true);
            });

            // Batch — numeric match when possible; LIKE fallback covers
            // phrases like "Batch 4" / partial numbers if the operator
            // types padding.
            $q->orWhereHas('batch', static function (Builder $b) use ($needle, $asInt, $likeEsc): void {
                if ($asInt !== null) {
                    $b->where('batches.batch_number', $asInt);
                    $likeEsc($b, 'batches.batch_number', $needle, true);

                    return;
                }
                $likeEsc($b, 'batches.batch_number', $needle, false);
            });

            // Current box
            $q->orWhereHas('currentBox', static function (Builder $b) use ($needle, $likeEsc): void {
                $likeEsc($b, 'boxes.box_number', $needle, false);
                $likeEsc($b, 'boxes.barcode', $needle, true);
            });

            // Location
            $q->orWhereHas('location', static function (Builder $l) use ($needle, $likeEsc): void {
                $likeEsc($l, 'locations.code', $needle, false);
                $likeEsc($l, 'locations.name', $needle, true);
            });

            // Document flags — operator can find "needs_review" / "damaged"
            // by typing the type literal, or by typing the human-readable
            // flag title.
            $q->orWhereHas('flags', static function (Builder $f) use ($needle, $likeEsc): void {
                $likeEsc($f, 'document_flags.type', $needle, false);
                $likeEsc($f, 'document_flags.title', $needle, true);
            });

            // Identifier history — preserves PR #8 behaviour (searching
            // for a previous identifier still finds the renamed doc).
            // Feedback1 Wave C2.5 — extended to ALSO match the PAST volume
            // numbers stored on the same history rows, so searching a former
            // volume number returns the document whose CURRENT volume differs.
            $q->orWhereHas('identifierHistory', static function (Builder $h) use ($needle, $likeEsc): void {
                $likeEsc($h, 'document_identifier_history.previous_identifier', $needle, false);
                $likeEsc($h, 'document_identifier_history.new_identifier', $needle, true);
                $likeEsc($h, 'document_identifier_history.previous_volume', $needle, true);
                $likeEsc($h, 'document_identifier_history.new_volume', $needle, true);
            });

            // NOTE: `spatie/laravel-tags` is wired on the Document model
            // (HasTags trait) but is NOT included in the omni-search OR-set
            // by design — tags are a free-form curation layer that the
            // operator already searches via the dedicated Tag filters /
            // tag chips in the UI. Adding them here would expand the OR
            // surface area without a clear product use-case.
        });

        // No DISTINCT / GROUP BY needed: `whereHas` compiles to EXISTS
        // subqueries (not JOINs), so a document with N matching authorities
        // appears exactly once. Avoiding `distinct()` keeps the query
        // pagination-friendly (no count() rewrite quirks) and lets MySQL
        // reuse the existing index on `documents.identifier` for the
        // outer ORDER BY.
    }

    /**
     * Build a leading-/trailing-wildcard LIKE filter on a single column.
     * Centralises the form + query shape shared by all "Search in X" filters.
     */
    private static function likeFilter(string $name, string $label, ?string $column = null): Filter
    {
        $col = $column ?? $name;

        return Filter::make($name)
            ->form([Forms\Components\TextInput::make('value')->label($label)])
            ->query(fn (Builder $q, array $data) => $q->when($data['value'] ?? null, fn ($q, $v) => $q->where($col, 'like', '%' . trim($v) . '%')));
    }

    /**
     * Build a FULLTEXT-backed filter on a single column. Delegates to
     * Document::scopeSearchFullText() which handles the MySQL/non-MySQL
     * driver split (MATCH...AGAINST vs LIKE) and the empty-term no-op.
     *
     * One column per filter is intentional: MySQL only uses a FULLTEXT
     * index when the MATCH() column list exactly matches the index's
     * column list, and the migration creates one single-column index
     * per searchable column.
     */
    private static function fullTextFilter(string $name, string $label, ?string $column = null): Filter
    {
        $col = $column ?? $name;

        return Filter::make($name)
            ->form([Forms\Components\TextInput::make('value')->label($label)])
            ->query(fn (Builder $q, array $data) => $q->when(
                $data['value'] ?? null,
                fn (Builder $q, string $v) => $q->searchFullText($v, [$col]),
            ));
    }

    /**
     * Escape `%`, `_` and the escape sentinel (`!`) so they match literally
     * inside a `LIKE ? ESCAPE '!'` clause. Centralised so the unit tests
     * can introspect the exact escape strategy and so any future change
     * to the sentinel is a one-liner.
     */
    private static function escapeForLike(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }
}
