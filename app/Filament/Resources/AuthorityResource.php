<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AppliesFieldPermissions;
use App\Filament\Resources\AuthorityResource\Pages;
use App\Filament\Support\CreatorColumn;
use App\Models\Authority;
use App\Support\ColumnLabels\ColumnLabels;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\QueryBuilder;
use Filament\Tables\Filters\QueryBuilder\Constraints\NumberConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\TextConstraint;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

class AuthorityResource extends Resource
{
    use AppliesFieldPermissions;

    /**
     * Feedback1 G-? — entity_type is now a constrained vocabulary
     * (Notary / Interventor) instead of free text. Kept as a constant so
     * the option set stays extensible without touching the form. Any value
     * already stored on a record (e.g. legacy 'PERSON') is merged into the
     * options at render time so existing rows remain editable/saveable.
     *
     * @var array<string, string>
     */
    public const ENTITY_TYPES = [
        'Notary' => 'Notary',
        'Interventor' => 'Interventor',
    ];

    /** RFQ §3.1.8 — see config/field_permissions.php */
    private const string FIELD_PERMISSIONS_KEY = 'authority';

    protected static ?string $model = Authority::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-circle';

    protected static string|\UnitEnum|null $navigationGroup = 'Records';

    protected static ?int $navigationSort = 70;

    protected static ?string $recordTitleAttribute = 'surname';

    public static function form(Schema $schema): Schema
    {
        $g = fn (Schemas\Components\Component $c): Schemas\Components\Component => self::gateField($c, self::FIELD_PERMISSIONS_KEY);

        // Layout rule (user mandate): root columns(1) so every Section is a
        // full-width band; atomic-field Sections use ['default' => 1, 'md' => 2];
        // non-atomic children (Textarea) take columnSpanFull.
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->schema([
                Section::make('Identification')
                    ->columns($twoCols)
                    ->schema([
                        // Feedback1 — identifier is required, unique and must
                        // start with R or I (Register / Interventor families).
                        $g(Forms\Components\TextInput::make('identifier')
                            ->label(ColumnLabels::get('authority', 'identifier'))
                            ->required()
                            ->maxLength(32)
                            ->unique(ignoreRecord: true)
                            ->rule('regex:/^[RI]/i')
                            ->validationMessages([
                                'regex' => ColumnLabels::get('authority', 'identifier') . ' must start with R or I.',
                            ])),
                        // alternative_identifier is optional but, when filled,
                        // must start with MS and be unique across creators.
                        $g(Forms\Components\TextInput::make('alternative_identifier')
                            ->label(ColumnLabels::get('authority', 'alternative_identifier'))
                            ->maxLength(32)
                            ->unique(ignoreRecord: true)
                            ->helperText('Starts with MS (optional)')
                            ->rules([
                                'nullable',
                                'regex:/^MS/i',
                            ])
                            ->validationMessages([
                                'regex' => ColumnLabels::get('authority', 'alternative_identifier') . ' must start with MS.',
                            ])),
                        // Client 2026-09-16 — the warrant number, a third
                        // identifier alongside the NAM code and the MS number.
                        // No MS prefix rule: it is a different numbering scheme,
                        // not a variant of the alternative identifier.
                        $g(Forms\Components\TextInput::make('alternative_identifier_warrant')
                            ->label(ColumnLabels::get('authority', 'alternative_identifier_warrant'))
                            ->maxLength(191)),
                        // Client 2026-09-22: a third kind of identifier with no
                        // relationship to the others. Textarea, not a text
                        // input: the label is plural and a record may list
                        // several superseded codes.
                        $g(Forms\Components\Textarea::make('previous_temporary_identifiers')
                            ->label(ColumnLabels::get('authority', 'previous_temporary_identifiers'))
                            ->rows(2)
                            ->maxLength(65535)
                            ->columnSpanFull()),
                        $g(Forms\Components\TextInput::make('surname')
                            ->required()
                            ->maxLength(255)),
                        // Feedback1 — "New Creator Given Name should be mandatory".
                        $g(Forms\Components\TextInput::make('given_names')
                            ->label('Given name')
                            ->required()
                            ->maxLength(255)),
                        // Feedback1 — replace free-text "Person" with a
                        // constrained Notary / Interventor vocabulary. Existing
                        // values (e.g. legacy 'PERSON') are merged into the
                        // options so the record stays editable/saveable.
                        $g(Forms\Components\Select::make('entity_type')
                            ->required()
                            ->native(false)
                            ->options(fn (?Authority $record): array => self::entityTypeOptions($record?->entity_type))
                            ->default('Notary')),
                    ]),

                Section::make('Practice dates')
                    ->columns($twoCols)
                    ->schema([
                        // Feedback1 — optional, but each accepts only a 4-digit
                        // year and end must be ≥ start when both are filled.
                        $g(Forms\Components\TextInput::make('practice_dates_start')
                            ->numeric()
                            ->minValue(1000)
                            ->maxValue(9999)
                            ->rule('digits:4')
                            ->validationMessages(['digits' => 'Enter a 4-digit year.'])),
                        $g(Forms\Components\TextInput::make('practice_dates_end')
                            ->numeric()
                            ->minValue(1000)
                            ->maxValue(9999)
                            ->rule('digits:4')
                            ->validationMessages(['digits' => 'Enter a 4-digit year.'])
                            // Closure rule so the comparison is skipped when
                            // either bound is empty (both dates are optional).
                            ->rule(static fn (Get $get): \Closure => static function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                $start = $get('practice_dates_start');
                                if ($value === null || $value === '' || $start === null || $start === '') {
                                    return;
                                }
                                if ((int) $value < (int) $start) {
                                    $fail('End year must be greater than or equal to the start year.');
                                }
                            })),
                        // NTG (Notari tal-Gvern / Notary to Government) dates —
                        // a YEAR RANGE, same shape as the private-practice dates
                        // above. Presence of either bound is what the "worked as
                        // NTG" filter keys off.
                        $g(Forms\Components\TextInput::make('ntg_dates_start')
                            ->label('NTG dates — start')
                            ->helperText('Year the creator started working as Notary to Government (if applicable)')
                            ->numeric()
                            ->minValue(1000)
                            ->maxValue(9999)
                            ->rule('digits:4')
                            ->validationMessages(['digits' => 'Enter a 4-digit year.'])),
                        $g(Forms\Components\TextInput::make('ntg_dates_end')
                            ->label('NTG dates — end')
                            ->numeric()
                            ->minValue(1000)
                            ->maxValue(9999)
                            ->rule('digits:4')
                            ->validationMessages(['digits' => 'Enter a 4-digit year.'])
                            ->rule(static fn (Get $get): \Closure => static function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                                $start = $get('ntg_dates_start');
                                if ($value === null || $value === '' || $start === null || $start === '') {
                                    return;
                                }
                                if ((int) $value < (int) $start) {
                                    $fail('End year must be greater than or equal to the start year.');
                                }
                            })),
                    ]),

                // Client 2026-09-16 — ISAAR(CPF) descriptive fields.
                Section::make('Archival description')
                    ->columns($twoCols)
                    ->schema([
                        $g(Forms\Components\Textarea::make('authorised_form_of_name')
                            ->label('Authorised form of name')
                            ->rows(2)
                            ->columnSpanFull()),
                        $g(Forms\Components\Textarea::make('functions_occupations_activities')
                            ->label('Functions, occupations and activities')
                            ->rows(3)
                            ->columnSpanFull()),
                        $g(Forms\Components\Select::make('level_of_detail')
                            ->label('Level of detail')
                            ->native(false)
                            ->options(array_combine(Authority::LEVELS_OF_DETAIL, Authority::LEVELS_OF_DETAIL))),
                        $g(Forms\Components\Select::make('status')
                            ->label('Status')
                            ->native(false)
                            ->options(array_combine(Authority::RECORD_STATUSES, Authority::RECORD_STATUSES))),
                        $g(Forms\Components\Textarea::make('rules_and_conventions')
                            ->label('Rules and/or conventions')
                            ->rows(2)
                            ->columnSpanFull()),
                        // Free text, not a date picker: an archival creation date
                        // is often a span or an approximation, and a picker would
                        // force it into a single day.
                        $g(Forms\Components\TextInput::make('date_of_creation')
                            ->label('Date of creation')
                            ->helperText('As written in the record — a date, a year, or a span such as 1607-1629.')
                            ->maxLength(255)),
                        $g(Forms\Components\TextInput::make('creator_of_record')
                            ->label('Creator of record')
                            ->helperText('The cataloguer named in the sheet, not the account that imported it.')
                            ->maxLength(255)),
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
        // Layout rule (user mandate): root columns(1), atomic Sections use
        // ['default' => 1, 'md' => 2]; non-atomic content uses columnSpanFull.
        $twoCols = ['default' => 1, 'md' => 2];

        return $schema
            ->columns(1)
            ->components([
                Section::make('Identification')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('identifier')
                            ->label(ColumnLabels::get('authority', 'identifier'))
                            ->badge()
                            ->color('primary')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('alternative_identifier')
                            ->label(ColumnLabels::get('authority', 'alternative_identifier'))
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('alternative_identifier_warrant')
                            ->label(ColumnLabels::get('authority', 'alternative_identifier_warrant'))
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('previous_temporary_identifiers')
                            ->label(ColumnLabels::get('authority', 'previous_temporary_identifiers'))
                            ->copyable()
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('surname')
                            ->label('Surname')
                            ->placeholder('—'),
                        TextEntry::make('given_names')
                            ->label('Given names')
                            ->placeholder('—'),
                        TextEntry::make('entity_type')
                            ->label('Entity type')
                            ->badge()
                            ->color('gray')
                            ->placeholder('—'),
                    ]),

                Section::make('Practice dates')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('practice_dates_start')
                            ->label('From')
                            ->placeholder('—'),
                        TextEntry::make('practice_dates_end')
                            ->label('To')
                            ->placeholder('—'),
                        TextEntry::make('practice_dates_display')
                            ->label('Range')
                            ->state(function (?Authority $record): string {
                                $start = $record?->practice_dates_start;
                                $end = $record?->practice_dates_end;
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
                        // NTG dates shown as separate beginning/end + a combined
                        // range, exactly like the private-practice dates above
                        // (client request 2026-07-29).
                        TextEntry::make('ntg_dates_start')
                            ->label('NTG from')
                            ->placeholder('—'),
                        TextEntry::make('ntg_dates_end')
                            ->label('NTG to')
                            ->placeholder('—'),
                        TextEntry::make('ntg_dates_display')
                            ->label('NTG range')
                            ->state(function (?Authority $record): string {
                                $start = $record?->ntg_dates_start;
                                $end = $record?->ntg_dates_end;
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

                Section::make('Archival description')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('authorised_form_of_name')
                            ->label('Authorised form of name')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('functions_occupations_activities')
                            ->label('Functions, occupations and activities')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('level_of_detail')
                            ->label('Level of detail')
                            ->badge()
                            ->color('gray')
                            ->placeholder('—'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (?string $state): string => $state === 'Complete' ? 'success' : 'warning')
                            ->placeholder('—'),
                        TextEntry::make('rules_and_conventions')
                            ->label('Rules and/or conventions')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('date_of_creation')
                            ->label('Date of creation')
                            ->placeholder('—'),
                        TextEntry::make('creator_of_record')
                            ->label('Creator of record')
                            ->placeholder('—'),
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
            // Feedback1 Wave B (B1) — persist & defer filters so an applied
            // filter set is not lost on navigation/refresh (client complaint:
            // "when filters are applied they seem to reset").
            ->deferFilters()
            ->persistFiltersInSession()
            // Feedback1 — creators sorted by Identifier by default.
            ->defaultSort('identifier')
            // Feedback1 Wave A (A6) — drag-and-drop column reordering, mirroring
            // DocumentResource and BoxResource (spec: all main resource lists).
            ->reorderableColumns()
            // Feedback1 — expose first/last page links in the paginator so
            // users can jump to the ends of large creator lists.
            ->extremePaginationLinks()
            ->columns([
                $gc(Tables\Columns\TextColumn::make('identifier')
                    ->label(ColumnLabels::get('authority', 'identifier'))
                    ->sortable()
                    ->searchable()),
                $gc(Tables\Columns\TextColumn::make('alternative_identifier')
                    ->label(ColumnLabels::get('authority', 'alternative_identifier'))
                    ->sortable()
                    ->searchable()
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('alternative_identifier_warrant')
                    ->label(ColumnLabels::get('authority', 'alternative_identifier_warrant'))
                    ->placeholder('—')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)),
                // Hidden by default like the warrant: useful when tracing an
                // old code, noise the rest of the time.
                $gc(Tables\Columns\TextColumn::make('previous_temporary_identifiers')
                    ->label(ColumnLabels::get('authority', 'previous_temporary_identifiers'))
                    ->placeholder('—')
                    ->limit(40)
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)),
                // Status and Level of detail stay visible: they are how the
                // cataloguer sees at a glance which records still need work.
                // The long descriptive fields are toggleable and off by default
                // — useful on the record, noise in a list.
                $gc(Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'Complete' ? 'success' : 'warning')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('level_of_detail')
                    ->label('Level of detail')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('date_of_creation')
                    ->label('Date of creation')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)),
                $gc(Tables\Columns\TextColumn::make('creator_of_record')
                    ->label('Creator of record')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)),
                $gc(Tables\Columns\TextColumn::make('surname')
                    ->sortable()
                    ->searchable()
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('given_names')
                    ->label('Given name')
                    ->sortable()
                    ->searchable()
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('entity_type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::ENTITY_TYPES[$state] ?? (string) $state)
                    ->sortable()
                    ->searchable()
                    ->toggleable()),
                // A year is a plain 4-digit integer — never a thousands-grouped
                // number. `->numeric()` alone renders 1607 as "1,607"; the empty
                // thousandsSeparator drops the comma the client asked to remove
                // while keeping the numeric right-alignment.
                $gc(Tables\Columns\TextColumn::make('practice_dates_start')
                    ->numeric(thousandsSeparator: '')
                    ->sortable()
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('practice_dates_end')
                    ->numeric(thousandsSeparator: '')
                    ->sortable()
                    ->toggleable()),
                // NTG dates as separate start/end columns, mirroring the
                // private-practice columns above (client request 2026-07-29).
                // Toggleable off by default to keep the default grid focused.
                $gc(Tables\Columns\TextColumn::make('ntg_dates_start')
                    ->label('NTG from')
                    ->numeric(thousandsSeparator: '')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)),
                $gc(Tables\Columns\TextColumn::make('ntg_dates_end')
                    ->label('NTG to')
                    ->numeric(thousandsSeparator: '')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)),
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
                CreatorColumn::make()
                    ->toggleable(),
            ])
            ->filters([
                // Feedback1 Wave B (B1) — rich filter mechanism (#1) on top of
                // the free-text column search (#2). The QueryBuilder natively
                // supports AND/OR/NOT nested groups with per-field dropdown
                // constraints — exactly the "select a field then a dropdown"
                // UX the client asked for. NOTE (client comment): identifier is
                // available as a constraint here but is NOT also added as a
                // standalone free-text filter — there is already a dedicated
                // identifier column, so duplicating it would be redundant.
                QueryBuilder::make()
                    ->constraints([
                        TextConstraint::make('identifier')
                            ->label('Identifier'),
                        TextConstraint::make('alternative_identifier')
                            ->label('MS / alternative identifier'),
                        TextConstraint::make('surname')
                            ->label('Surname'),
                        TextConstraint::make('given_names')
                            ->label('Given name'),
                        SelectConstraint::make('entity_type')
                            ->label('Entity type')
                            ->options(self::ENTITY_TYPES)
                            ->multiple(),
                        NumberConstraint::make('practice_dates_start')
                            ->label('Practice start year')
                            ->integer(),
                        NumberConstraint::make('practice_dates_end')
                            ->label('Practice end year')
                            ->integer(),
                        // NTG year range as numeric constraints (same shape as
                        // the private-practice start/end above).
                        NumberConstraint::make('ntg_dates_start')
                            ->label('NTG start year')
                            ->integer(),
                        NumberConstraint::make('ntg_dates_end')
                            ->label('NTG end year')
                            ->integer(),
                    ]),

                // Feedback1 Wave B (B2) — "worked between X and Y" helper. Two
                // optional year inputs; whichever bound is filled is applied.
                // A notary "worked in [from,to]" if their practice window
                // overlaps it: practice_dates_end >= from AND
                // practice_dates_start <= to. Nulls are treated as open-ended.
                Filter::make('practice_period')
                    ->label('Practice period')
                    ->form([
                        Forms\Components\TextInput::make('from')
                            ->label('Worked after / from (year)')
                            ->numeric(),
                        Forms\Components\TextInput::make('to')
                            ->label('Worked before / to (year)')
                            ->numeric(),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['from'] ?? null,
                            fn (Builder $q, $from): Builder => $q->where(
                                fn (Builder $q) => $q
                                    ->whereNull('practice_dates_end')
                                    ->orWhere('practice_dates_end', '>=', (int) $from)
                            )
                        )
                        ->when(
                            $data['to'] ?? null,
                            fn (Builder $q, $to): Builder => $q->where(
                                fn (Builder $q) => $q
                                    ->whereNull('practice_dates_start')
                                    ->orWhere('practice_dates_start', '<=', (int) $to)
                            )
                        ))
                    ->indicateUsing(function (array $data): array {
                        $i = [];
                        if (! empty($data['from'])) {
                            $i[] = "Worked from {$data['from']}";
                        }
                        if (! empty($data['to'])) {
                            $i[] = "Worked to {$data['to']}";
                        }

                        return $i;
                    }),

                // Feedback1 Wave B (B2) — "has MS number" ternary on the
                // optional MS-prefixed alternative_identifier column.
                TernaryFilter::make('has_ms_number')
                    ->label('Has MS number')
                    ->placeholder('All creators')
                    ->trueLabel('Has MS number')
                    ->falseLabel('No MS number')
                    // TRIM parity so the two branches partition the set exactly:
                    // a whitespace-only alternative_identifier counts as "no MS
                    // number" on BOTH sides (without TRIM, a single space would
                    // slip into "has" but not "no").
                    ->queries(
                        true: fn (Builder $q): Builder => $q->whereRaw("TRIM(COALESCE(alternative_identifier, '')) <> ''"),
                        false: fn (Builder $q): Builder => $q->whereRaw("TRIM(COALESCE(alternative_identifier, '')) = ''"),
                        blank: fn (Builder $q): Builder => $q,
                    ),

                // "Filter which creators worked as NTG ie: have NTG dates
                // associated". A creator counts as NTG when either bound of the
                // NTG year range is present.
                TernaryFilter::make('worked_as_ntg')
                    ->label('Worked as NTG')
                    ->placeholder('All creators')
                    ->trueLabel('Worked as NTG')
                    ->falseLabel('Never NTG')
                    ->queries(
                        true: fn (Builder $q): Builder => $q->where(fn (Builder $sub): Builder => $sub
                            ->whereNotNull('ntg_dates_start')->orWhereNotNull('ntg_dates_end')),
                        false: fn (Builder $q): Builder => $q->whereNull('ntg_dates_start')->whereNull('ntg_dates_end'),
                        blank: fn (Builder $q): Builder => $q,
                    ),
            ])
            ->actions([
                ViewAction::make(),
                EditAction::make(),
                // Feedback1 — hide row Delete when the creator still has
                // documents attached, so deleting one never orphans records.
                DeleteAction::make()
                    ->visible(fn (Authority $record): bool => ! $record->documents()->exists()),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    // Feedback1 — a bulk delete that ignored the document guard
                    // would orphan documents. We override the action so it only
                    // deletes document-free creators in the selection and tells
                    // the operator how many were skipped.
                    DeleteBulkAction::make()
                        ->action(function (EloquentCollection $records): void {
                            $deleted = 0;
                            $skipped = 0;

                            foreach ($records as $record) {
                                /** @var Authority $record */
                                if ($record->documents()->exists()) {
                                    $skipped++;

                                    continue;
                                }
                                $record->delete();
                                $deleted++;
                            }

                            $notification = Notification::make()
                                ->title($skipped === 0
                                    ? "Deleted {$deleted} creator(s)."
                                    : "Deleted {$deleted} creator(s); skipped {$skipped} that still have documents.");

                            ($skipped === 0 ? $notification->success() : $notification->warning())->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    /**
     * Build the entity_type Select options: the constrained vocabulary plus
     * any legacy value already stored on the record (so existing rows such
     * as 'PERSON' remain editable and saveable without data loss).
     *
     * @return array<string, string>
     */
    public static function entityTypeOptions(?string $current = null): array
    {
        $options = self::ENTITY_TYPES;

        if ($current !== null && $current !== '' && ! array_key_exists($current, $options)) {
            $options[$current] = $current;
        }

        return $options;
    }

    /**
     * Eager-load the first 'created' audit with its user so CreatorColumn
     * can render the inputter name without N+1 queries.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            // A9 — creator resolution: first 'created' audit with its user.
            'audits' => fn ($q) => $q->oldest('id')->with('user'),
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
            'index' => Pages\ListAuthorities::route('/'),
            'create' => Pages\CreateAuthority::route('/create'),
            'view' => Pages\ViewAuthority::route('/{record}'),
            'edit' => Pages\EditAuthority::route('/{record}/edit'),
        ];
    }
}
