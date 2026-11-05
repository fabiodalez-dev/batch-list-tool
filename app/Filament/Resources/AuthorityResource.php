<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\AppliesFieldPermissions;
use App\Filament\Resources\AuthorityResource\Pages;
use App\Filament\Support\CreatorColumn;
use App\Filament\Support\EqualsFirst;
use App\Filament\Support\ListRules;
use App\Filament\Support\MatchQueryBuilder;
use App\Filament\Support\Operators\WorkedBetweenOperator;
use App\Models\Authority;
use App\Models\Lookup\EntityType;
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
use Filament\Tables\Filters\QueryBuilder\Constraints\Constraint;
use Filament\Tables\Filters\QueryBuilder\Constraints\SelectConstraint;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\LazyCollection;

class AuthorityResource extends Resource
{
    use AppliesFieldPermissions;

    /** RFQ §3.1.8 — see config/field_permissions.php */
    private const string FIELD_PERMISSIONS_KEY = 'authority';

    // Client 2026-11-05, her wording. These columns are not renameable, so they
    // are not in ColumnLabels.
    private const string PRACTICE_START_LABEL = 'Practice date start';

    private const string PRACTICE_END_LABEL = 'Practice date end';

    private const string NTG_START_LABEL = 'NTG date start';

    private const string NTG_END_LABEL = 'NTG date end';

    private const int FIRST_YEAR = 1400;

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
                // Client 2026-11-05: the three areas, in her order, on New, Edit
                // and View alike (see infolist()).
                Section::make('Control Area')
                    ->columns($twoCols)
                    ->schema([
                        // Client 2026-09-25: the two identifiers swapped roles.
                        // The Citing Reference Code is the one the archive fills
                        // in for every creator (676 of 676 in her file, all
                        // distinct), so it is required and unique. The NAM code
                        // is held for 80 of them, so it is optional — unique
                        // where given, because two creators cannot share one.
                        //
                        // Neither carries a format rule any more. "Starts with
                        // R or I" sat on the NAM code, whose values read
                        // "MT AF-P000058", and "starts with MS" sat on the
                        // Citing code, whose values read "R1". Both rules
                        // described the other column, and a shape pinned in
                        // code is what makes the next unusual code wait on a
                        // release.
                        $g(Forms\Components\TextInput::make('alternative_identifier')
                            ->label(ColumnLabels::get('authority', 'alternative_identifier'))
                            ->required()
                            ->maxLength(32)
                            ->unique(ignoreRecord: true)),
                        $g(Forms\Components\TextInput::make('identifier')
                            ->label(ColumnLabels::get('authority', 'identifier'))
                            ->maxLength(32)
                            ->unique(ignoreRecord: true)
                            ->helperText('Optional')),
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
                        $g(Forms\Components\Textarea::make('notes')
                            ->label(ColumnLabels::get('authority', 'notes'))
                            ->rows(3)
                            ->columnSpanFull()),
                        // Client 2026-09-16 — ISAAR(CPF) descriptive fields.
                        $g(Forms\Components\Select::make('level_of_detail')
                            ->searchable()
                            ->label(ColumnLabels::get('authority', 'level_of_detail'))
                            ->native(false)
                            ->options(array_combine(Authority::LEVELS_OF_DETAIL, Authority::LEVELS_OF_DETAIL))),
                        $g(Forms\Components\Select::make('status')
                            ->searchable()
                            ->label(ColumnLabels::get('authority', 'status'))
                            ->native(false)
                            ->options(array_combine(Authority::RECORD_STATUSES, Authority::RECORD_STATUSES))),
                        $g(Forms\Components\Textarea::make('rules_and_conventions')
                            ->label(ColumnLabels::get('authority', 'rules_and_conventions'))
                            ->rows(2)
                            ->columnSpanFull()),
                        // Free text, not a date picker: an archival creation date
                        // is often a span or an approximation, and a picker would
                        // force it into a single day.
                        $g(Forms\Components\TextInput::make('date_of_creation')
                            ->label(ColumnLabels::get('authority', 'date_of_creation'))
                            ->helperText('As written in the record — a date, a year, or a span such as 1607-1629.')
                            ->maxLength(255)),
                        $g(Forms\Components\TextInput::make('creator_of_record')
                            ->label(ColumnLabels::get('authority', 'creator_of_record'))
                            ->helperText('The cataloguer named in the sheet, not the account that imported it.')
                            ->maxLength(255)),
                    ]),

                Section::make('Identity Area')
                    ->columns($twoCols)
                    ->schema([
                        // Client 2026-11-05: the options are the Entity Types
                        // page. A value it does not offer (deactivated, or from
                        // before the page) stays selectable, so the record stays
                        // editable and saveable.
                        $g(Forms\Components\Select::make('entity_type')
                            ->searchable()
                            ->label(ColumnLabels::get('authority', 'entity_type'))
                            ->required()
                            ->native(false)
                            ->options(fn (?Authority $record): array => EntityType::optionsWith($record?->entity_type))
                            ->default(fn (): ?string => EntityType::defaultCode())),
                        $g(Forms\Components\TextInput::make('surname')
                            ->label(ColumnLabels::get('authority', 'surname'))
                            ->required()
                            ->maxLength(255)),
                        // Feedback1 — "New Creator Given Name should be mandatory".
                        $g(Forms\Components\TextInput::make('given_names')
                            ->label(ColumnLabels::get('authority', 'given_names'))
                            ->required()
                            ->maxLength(255)),
                        $g(Forms\Components\TextInput::make('name_suffix')
                            ->label(ColumnLabels::get('authority', 'name_suffix'))
                            ->maxLength(64)),
                        $g(Forms\Components\TextInput::make('maiden_surname')
                            ->label(ColumnLabels::get('authority', 'maiden_surname'))
                            ->maxLength(255)),
                        $g(Forms\Components\Textarea::make('authorised_form_of_name')
                            ->label(ColumnLabels::get('authority', 'authorised_form_of_name'))
                            ->helperText('Created automatically when saved, from the name and the dates. You may edit it: an edited form is kept.')
                            ->rows(2)
                            ->columnSpanFull()),
                    ]),

                Section::make('Description Area')
                    ->columns($twoCols)
                    ->schema([
                        // Feedback1 — optional, but each accepts only a 4-digit
                        // year and end must be ≥ start when both are filled.
                        $g(self::yearSelect('practice_dates_start', self::PRACTICE_START_LABEL)),
                        $g(self::yearSelect('practice_dates_end', self::PRACTICE_END_LABEL, startField: 'practice_dates_start')),
                        // NTG (Notari tal-Gvern / Notary to Government) dates —
                        // a YEAR RANGE, same shape as the private-practice dates
                        // above. Presence of either bound is what the "worked as
                        // NTG" filter keys off.
                        $g(self::yearSelect('ntg_dates_start', self::NTG_START_LABEL)
                            ->helperText('Year the creator started working as Notary to Government (if applicable)')),
                        $g(self::yearSelect('ntg_dates_end', self::NTG_END_LABEL, startField: 'ntg_dates_start')),
                        $g(Forms\Components\Textarea::make('functions_occupations_activities')
                            ->label(ColumnLabels::get('authority', 'functions_occupations_activities'))
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
                Section::make('Control Area')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('alternative_identifier')
                            ->label(ColumnLabels::get('authority', 'alternative_identifier'))
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('identifier')
                            ->label(ColumnLabels::get('authority', 'identifier'))
                            ->badge()
                            ->color('primary')
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
                        TextEntry::make('notes')
                            ->label(ColumnLabels::get('authority', 'notes'))
                            ->prose()
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('level_of_detail')
                            ->label(ColumnLabels::get('authority', 'level_of_detail'))
                            ->badge()
                            ->color('gray')
                            ->placeholder('—'),
                        TextEntry::make('status')
                            ->label(ColumnLabels::get('authority', 'status'))
                            ->badge()
                            ->color(fn (?string $state): string => $state === 'Complete' ? 'success' : 'warning')
                            ->placeholder('—'),
                        TextEntry::make('rules_and_conventions')
                            ->label(ColumnLabels::get('authority', 'rules_and_conventions'))
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('date_of_creation')
                            ->label(ColumnLabels::get('authority', 'date_of_creation'))
                            ->placeholder('—'),
                        TextEntry::make('creator_of_record')
                            ->label(ColumnLabels::get('authority', 'creator_of_record'))
                            ->placeholder('—'),
                    ]),

                Section::make('Identity Area')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('entity_type')
                            ->label(ColumnLabels::get('authority', 'entity_type'))
                            ->badge()
                            ->color('gray')
                            ->formatStateUsing(fn (?string $state): string => EntityType::labels()[$state] ?? (string) $state)
                            ->placeholder('—'),
                        TextEntry::make('surname')
                            ->label(ColumnLabels::get('authority', 'surname'))
                            ->placeholder('—'),
                        TextEntry::make('given_names')
                            ->label(ColumnLabels::get('authority', 'given_names'))
                            ->placeholder('—'),
                        TextEntry::make('name_suffix')
                            ->label(ColumnLabels::get('authority', 'name_suffix'))
                            ->placeholder('—'),
                        TextEntry::make('maiden_surname')
                            ->label(ColumnLabels::get('authority', 'maiden_surname'))
                            ->placeholder('—'),
                        TextEntry::make('authorised_form_of_name')
                            ->label(ColumnLabels::get('authority', 'authorised_form_of_name'))
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),

                Section::make('Description Area')
                    ->columns($twoCols)
                    ->schema([
                        TextEntry::make('practice_dates_start')
                            ->label(self::PRACTICE_START_LABEL)
                            ->placeholder('—'),
                        TextEntry::make('practice_dates_end')
                            ->label(self::PRACTICE_END_LABEL)
                            ->placeholder('—'),
                        TextEntry::make('ntg_dates_start')
                            ->label(self::NTG_START_LABEL)
                            ->placeholder('—'),
                        TextEntry::make('ntg_dates_end')
                            ->label(self::NTG_END_LABEL)
                            ->placeholder('—'),
                        TextEntry::make('functions_occupations_activities')
                            ->label(ColumnLabels::get('authority', 'functions_occupations_activities'))
                            ->placeholder('—')
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
        // Read once per table, not once per row.
        $entityTypeLabels = EntityType::labels();

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
                    // Client 2026-11-05: "Past Reference Code does not have a sort".
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true)),
                // Status and Level of detail stay visible: they are how the
                // cataloguer sees at a glance which records still need work.
                // The long descriptive fields are toggleable and off by default
                // — useful on the record, noise in a list.
                $gc(Tables\Columns\TextColumn::make('status')
                    ->label(ColumnLabels::get('authority', 'status'))
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'Complete' ? 'success' : 'warning')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('level_of_detail')
                    ->label(ColumnLabels::get('authority', 'level_of_detail'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('date_of_creation')
                    ->label(ColumnLabels::get('authority', 'date_of_creation'))
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)),
                $gc(Tables\Columns\TextColumn::make('creator_of_record')
                    ->label(ColumnLabels::get('authority', 'creator_of_record'))
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)),
                $gc(Tables\Columns\TextColumn::make('surname')
                    ->label(ColumnLabels::get('authority', 'surname'))
                    ->sortable()
                    ->searchable()
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('given_names')
                    ->label(ColumnLabels::get('authority', 'given_names'))
                    ->sortable()
                    ->searchable()
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('name_suffix')
                    ->label(ColumnLabels::get('authority', 'name_suffix'))
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('maiden_surname')
                    ->label(ColumnLabels::get('authority', 'maiden_surname'))
                    ->placeholder('—')
                    ->sortable()
                    ->searchable()
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('entity_type')
                    ->label(ColumnLabels::get('authority', 'entity_type'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $entityTypeLabels[$state] ?? (string) $state)
                    ->sortable()
                    ->searchable()
                    ->toggleable()),
                // A year is a plain 4-digit integer — never a thousands-grouped
                // number. `->numeric()` alone renders 1607 as "1,607"; the empty
                // thousandsSeparator drops the comma the client asked to remove
                // while keeping the numeric right-alignment.
                $gc(Tables\Columns\TextColumn::make('practice_dates_start')
                    ->label(self::PRACTICE_START_LABEL)
                    ->numeric(thousandsSeparator: '')
                    ->sortable()
                    ->toggleable()),
                $gc(Tables\Columns\TextColumn::make('practice_dates_end')
                    ->label(self::PRACTICE_END_LABEL)
                    ->numeric(thousandsSeparator: '')
                    ->sortable()
                    ->toggleable()),
                // NTG dates as separate start/end columns, mirroring the
                // private-practice columns above (client request 2026-07-29).
                // Toggleable off by default to keep the default grid focused.
                $gc(Tables\Columns\TextColumn::make('ntg_dates_start')
                    ->label(self::NTG_START_LABEL)
                    ->numeric(thousandsSeparator: '')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)),
                $gc(Tables\Columns\TextColumn::make('ntg_dates_end')
                    ->label(self::NTG_END_LABEL)
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
                // Client 2026-10-30: one rule per column, AND / OR above them,
                // as on Documents. What the panel's ready-made filters did is a
                // rule too: "Has MS number", "Practice period" and "Worked as
                // NTG" are rules of their own.
                MatchQueryBuilder::make()
                    ->constraints([
                        EqualsFirst::text('identifier', ColumnLabels::get('authority', 'identifier')),
                        EqualsFirst::text('alternative_identifier', ColumnLabels::get('authority', 'alternative_identifier'), nullable: true),
                        EqualsFirst::text('alternative_identifier_warrant', ColumnLabels::get('authority', 'alternative_identifier_warrant'), nullable: true),
                        EqualsFirst::text('previous_temporary_identifiers', ColumnLabels::get('authority', 'previous_temporary_identifiers'), nullable: true),
                        SelectConstraint::make('status')
                            ->label(ColumnLabels::get('authority', 'status'))
                            ->options(array_combine(Authority::RECORD_STATUSES, Authority::RECORD_STATUSES))
                            ->multiple()
                            ->nullable(),
                        SelectConstraint::make('level_of_detail')
                            ->label(ColumnLabels::get('authority', 'level_of_detail'))
                            ->options(array_combine(Authority::LEVELS_OF_DETAIL, Authority::LEVELS_OF_DETAIL))
                            ->multiple()
                            ->nullable(),
                        EqualsFirst::text('date_of_creation', ColumnLabels::get('authority', 'date_of_creation'), nullable: true),
                        EqualsFirst::text('creator_of_record', ColumnLabels::get('authority', 'creator_of_record'), nullable: true),
                        EqualsFirst::text('surname', ColumnLabels::get('authority', 'surname'), nullable: true),
                        EqualsFirst::text('given_names', ColumnLabels::get('authority', 'given_names'), nullable: true),
                        EqualsFirst::text('name_suffix', ColumnLabels::get('authority', 'name_suffix'), nullable: true),
                        EqualsFirst::text('maiden_surname', ColumnLabels::get('authority', 'maiden_surname'), nullable: true),
                        SelectConstraint::make('entity_type')
                            ->label(ColumnLabels::get('authority', 'entity_type'))
                            ->options($entityTypeLabels)
                            ->multiple(),
                        EqualsFirst::number('practice_dates_start', self::PRACTICE_START_LABEL),
                        EqualsFirst::number('practice_dates_end', self::PRACTICE_END_LABEL),
                        EqualsFirst::number('ntg_dates_start', self::NTG_START_LABEL),
                        EqualsFirst::number('ntg_dates_end', self::NTG_END_LABEL),
                        Constraint::make('practice_period')
                            ->label('Practice period')
                            ->icon('heroicon-o-calendar')
                            ->operators([WorkedBetweenOperator::class]),
                        // "Filter which creators worked as NTG ie: have NTG
                        // dates associated": either NTG year is there.
                        ListRules::yesNo('worked_as_ntg', 'Worked as NTG', 'Worked as NTG', 'Never NTG', fn (Builder $q): Builder => $q
                            ->whereNotNull($q->qualifyColumn('ntg_dates_start'))
                            ->orWhereNotNull($q->qualifyColumn('ntg_dates_end'))),
                        // Feedback1 Wave B (B2) — a whitespace-only MS number
                        // is no MS number, both ways (TRIM parity).
                        ListRules::yesNo('has_ms_number', 'Has MS number', 'Has MS number', 'No MS number', fn (Builder $q): Builder => $q
                            ->whereRaw("TRIM(COALESCE(alternative_identifier, '')) <> ''")),
                        ListRules::date('created_at'),
                        ListRules::date('updated_at'),
                        ListRules::date('deleted_at'),
                        ListRules::inputter(),
                    ]),
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
                        // LazyCollection too: every built-in bulk delete loads its
                        // selection in chunks (AppServiceProvider), and this override
                        // only iterates, so it takes either.
                        ->action(function (EloquentCollection|LazyCollection $records): void {
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

    /**
     * Client 2026-11-05: "(Select Year)". Newest first, from 1400 to this year.
     * A year already stored outside that range stays selectable (the Select only
     * accepts its own options), and the 4-digit and end >= start rules stay on
     * the server.
     */
    private static function yearSelect(string $field, string $label, ?string $startField = null): Forms\Components\Select
    {
        $select = Forms\Components\Select::make($field)
            ->label($label)
            ->placeholder('Select Year')
            ->searchable()
            ->native(false)
            ->options(function (?Authority $record) use ($field): array {
                $years = range((int) now()->year, self::FIRST_YEAR);
                $stored = $record?->getAttribute($field);
                if ($stored !== null && ! in_array((int) $stored, $years, true)) {
                    $years[] = (int) $stored;
                    rsort($years);
                }

                return array_combine($years, array_map(strval(...), $years));
            })
            ->rule('digits:4')
            ->validationMessages(['digits' => 'Enter a 4-digit year.']);

        if ($startField === null) {
            return $select;
        }

        // Closure rule so the comparison is skipped when either bound is empty
        // (both dates are optional).
        return $select->rule(static fn (Get $get): \Closure => static function (string $attribute, mixed $value, \Closure $fail) use ($get, $startField): void {
            $start = $get($startField);
            if ($value === null || $value === '' || $start === null || $start === '') {
                return;
            }
            if ((int) $value < (int) $start) {
                $fail('End year must be greater than or equal to the start year.');
            }
        });
    }
}
