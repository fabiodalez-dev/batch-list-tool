<?php

namespace App\Filament\Resources;

use App\Filament\Actions\Documents\DocumentActionGroup;
use App\Filament\Actions\Documents\MarkDisinfestedAction;
use App\Filament\Actions\Documents\MoveToBoxAction;
use App\Filament\Concerns\AppliesFieldPermissions;
use App\Filament\Resources\DocumentResource\Pages;
use App\Filament\Support\SearchableSelects;
use App\Models\Document;
use App\Models\Repository;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
        'volume_label',
        'dates',
        'notes',
        'deeds',
        'seal_number',
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
                        $g(Forms\Components\TextInput::make('document_type')->maxLength(100)),
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
                            ->default(fn () => auth()->user()?->default_repository_id)),
                        $g(Forms\Components\TextInput::make('volume_label')->label('Volume label')->maxLength(64)),
                        $g(Forms\Components\TextInput::make('practice')->maxLength(100)),
                        $g(Forms\Components\TextInput::make('dates')->label('Dates (text)')->maxLength(191)
                            ->helperText('Free-text dates as in POC, e.g. "1607-1629" or "Jun 1997 - Nov 1998"')
                            ->columnSpanFull()),
                        $g(Forms\Components\TextInput::make('deeds')->maxLength(2000)->columnSpanFull()),
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
                        $g(SearchableSelects::batch('batch_id', 'batch')),
                        $g(SearchableSelects::box('current_box_id', 'currentBox')),
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
                        $g(Forms\Components\TextInput::make('current_box_type')->maxLength(50)),
                        $g(Forms\Components\TextInput::make('nra_location')->maxLength(500)
                            ->helperText('Legacy free-text. New records should use the Location Select above.')
                            ->columnSpanFull()),
                        $g(Forms\Components\TextInput::make('museum_location')->maxLength(500)
                            ->helperText('Legacy free-text. New records should use the Location Select above.')
                            ->columnSpanFull()),
                    ]),

                Section::make('Seal & disinfestation')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        $g(Forms\Components\TextInput::make('seal_number')->maxLength(50)),
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
                        $g(Forms\Components\TextInput::make('digitised')->maxLength(100)),
                        $g(Forms\Components\Toggle::make('torre')->columnSpanFull()),
                        $g(Forms\Components\TextInput::make('accession_code_legacy')->label('Accession (legacy text)')->maxLength(191)),
                        $g(Forms\Components\TextInput::make('object_reference_number')->maxLength(500)),
                        $g(Forms\Components\TextInput::make('tracking')->maxLength(500)->columnSpanFull()),
                        $g(Forms\Components\TextInput::make('museum_reference')->maxLength(500)->columnSpanFull()),
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
                        TextEntry::make('volume_label')->label('Volume')->placeholder('—'),
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
                        TextEntry::make('seal_number')
                            ->label('Current seal #')
                            ->badge()
                            ->color('primary')
                            ->copyable()
                            ->placeholder('—'),
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
                        TextEntry::make('barcode_in')->label('Barcode (IN)')->copyable()->placeholder('—'),
                        TextEntry::make('seal_number')->label('Seal #')->copyable()->placeholder('—'),
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
                $gc(Tables\Columns\TextColumn::make('identifier')->sortable()->copyable()),
                $gc(Tables\Columns\TextColumn::make('document_type')->toggleable()),
                $gc(Tables\Columns\TextColumn::make('series.code')->label('Series')->badge()->sortable(), 'series_id'),
                $gc(Tables\Columns\TextColumn::make('batch.batch_number')->label('Batch')->sortable()->alignCenter(), 'batch_id'),
                $gc(Tables\Columns\TextColumn::make('currentBox.box_number')->label('Box')->toggleable(), 'current_box_id'),
                $gc(Tables\Columns\TextColumn::make('practice')->toggleable()),
                $gc(Tables\Columns\TextColumn::make('volume_label')->label('Vol.')->toggleable()),
                $gc(Tables\Columns\TextColumn::make('dates')->label('Dates')->toggleable()->limit(30)),
                $gc(Tables\Columns\TextColumn::make('dates_year_start')->label('From')->numeric(thousandsSeparator: '')->sortable()->alignEnd()),
                $gc(Tables\Columns\TextColumn::make('dates_year_end')->label('To')->numeric(thousandsSeparator: '')->sortable()->alignEnd()),
                $gc(Tables\Columns\TextColumn::make('barcode_in')->label('Barcode (IN)')->toggleable(isToggledHiddenByDefault: true)),
                $gc(Tables\Columns\TextColumn::make('catalogue_identifier')->label('Catalogue ID')->toggleable(isToggledHiddenByDefault: true)),
                $gc(Tables\Columns\TextColumn::make('repository.code')->label('Repo')->badge()->color('gray')->toggleable(), 'repository_id'),
                $gc(Tables\Columns\TextColumn::make('disinfestation_date')->label('Disinfested')->date()->sortable()->toggleable(isToggledHiddenByDefault: true)),
                $gc(Tables\Columns\IconColumn::make('torre')->boolean()->toggleable(isToggledHiddenByDefault: true)),
                $gc(Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true)),
            ])
            ->filtersFormColumns(3)
            ->filters([
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

                // volume_label is special — also searches the JSON path extra->volume; kept inline.
                Filter::make('volume_label')
                    ->form([
                        Forms\Components\TextInput::make('value')->label('Search in Volume'),
                    ])
                    ->query(
                        fn (Builder $q, array $data) => $q->when(
                            $data['value'] ?? null,
                            fn ($q, $v) => $q->where(function ($q) use ($v) {
                                $needle = '%' . trim($v) . '%';
                                $q->where('volume_label', 'like', $needle)
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
            DocumentResource\RelationManagers\SealNumberHistoryRelationManager::class,
            DocumentResource\RelationManagers\FlagsRelationManager::class,
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
     * Apply conditional eager-loading to the base query.
     *
     * NOTE on timing: Filament evaluates `getEloquentQuery()` BEFORE the
     * table's filters run (see `Filament\Tables\Concerns\HasRecords::filterTableQuery()`
     * — the eloquent builder returned here is the one filters are then
     * stacked onto). That means the `conditionallyWith()` count probes
     * the full table, not the post-filter subset. For the production
     * archive (~50k+ docs) the count will always cross the 200 threshold,
     * so the eager load is effectively always-on — which is the SAFE
     * default and matches the previous behaviour. For smaller installs
     * (e.g. a development copy with < 200 documents) the scope skips
     * the eager load and lets Filament fall back to lazy access per row,
     * which is cheaper for the dev case.
     *
     * If a future page wants true post-filter conditional preloading it
     * should override `ListDocuments::getTableRecords()` and call
     * `loadMissing(...)` on the paginated collection — Filament does not
     * expose a post-filter hook on the resource itself.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->conditionallyWith([
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
            ]);
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
            'volume_label',
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
            // Identifier history (PR #8) — searching for "R7-old" finds the document
            // whose identifier was previously "R7-old", even after re-classification.
            'identifierHistory.previous_identifier',
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
            $q->orWhereHas('identifierHistory', static function (Builder $h) use ($needle, $likeEsc): void {
                $likeEsc($h, 'document_identifier_history.previous_identifier', $needle, false);
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
