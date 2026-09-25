<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\LogsImportRows;
use App\Filament\Imports\Concerns\SkipsExistingRows;
use App\Models\Authority;
use App\Support\BulkImport\SpreadsheetParsers;
use App\Support\ColumnLabels\ColumnLabels;
use App\Support\CustomFields\CustomFieldResolver;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Validation\ValidationException;

/**
 * RFQ §3.1.3 — Bulk import for {@see Authority} (notaries / "Creators").
 *
 * Maps the eight columns of `Authorities_Sample.xlsx`:
 *
 *     Identifier | Alternative Identifier | Type of Entity |
 *     Private Practice Dates Active | NTG Dates Active |
 *     Name Suffix | Maiden Surname | Creator Surname | Creator Name
 *
 * The headline UX feature (per the operator's brief) is the *per-column*
 * dropdown mapping inside the Filament action modal — operators can pick
 * any spreadsheet column for any importer field. The `->guess()` aliases
 * below pre-select the right column when the operator drops the official
 * sample file in, so first-run feels like zero-config.
 *
 * Duplicate handling: rows are matched on `identifier` (the unique R-code),
 * so re-running the same file is idempotent — existing rows get updated,
 * new rows get inserted.
 */
class AuthorityImporter extends Importer
{
    use LogsImportRows;
    use SkipsExistingRows;

    protected static ?string $model = Authority::class;

    /**
     * Accept one of a fixed list of values, case- and spacing-insensitively,
     * and reject anything else with a message naming the options.
     *
     * The alternative — storing whatever arrives — gives the cataloguer a
     * "Level of detail" of "ful" that no filter will ever match and nothing
     * will ever flag. Rejecting the row puts it in the failed-rows file with
     * the valid values spelled out, which is something she can act on.
     *
     * @param array<int, string> $allowed
     */
    /**
     * Apply the repository's column names to every renameable column.
     *
     * Client 2026-09-24: built-in columns can be renamed from the admin. The
     * template header comes from the same resolver, so the guess must carry
     * the CURRENT name first — otherwise a renamed column lands in the sheet
     * under its new name while the importer still looks for the old one, and
     * the operator fills in a column the import silently discards.
     *
     * Done here rather than on each column so a column added later is covered
     * by being listed in ColumnLabels::DEFAULTS, and nowhere else.
     *
     * @param array<ImportColumn> $columns
     * @return array<ImportColumn>
     */
    /**
     * Per-row stash for custom-field key→value data, persisted in
     * {@see afterSave()} with merge semantics.
     *
     * @var array<int, array<string, string|null>>
     */
    protected static array $rowCustomFieldStash = [];

    /**
     * @return array<ImportColumn>
     */
    public static function getColumns(): array
    {
        return self::applyRenames([
            // Client 2026-09-25: the NAM code is OPTIONAL. Her file carries it
            // on 80 of 676 creators, so requiring it failed 596 rows on a value
            // the archive simply does not hold for most notaries.
            ImportColumn::make('identifier')
                ->label('NAM Authority Reference Code')
                // The template header became "Authority Record Identifier (NAM)"
                // on 2026-09-16. The old spellings stay in the list so sheets
                // saved before that keep importing without remapping.
                // Renamed 2026-09-24. Every earlier spelling stays in the
                // list: sheets downloaded before today must keep importing
                // without the operator remapping anything by hand.
                // The generic spellings — "Identifier", "ID", "R-code",
                // "Code" — moved to the Citing Reference Code below. In every
                // sheet saved before 2026-09-25 that column held R1, R2, R3…,
                // and those are the values that now live in the Citing code.
                // Leaving them here would file an old sheet's R-codes under
                // the NAM code and then fail the row for the missing key.
                ->guess([
                    'NAM Authority Reference Code',
                    'Authority Record Identifier (NAM)', 'Authority Record Identifier',
                    'NAM',
                ])
                // No unique rule here, though the column still is unique in the
                // database. An ImportColumn's rules are static, so the rule
                // could not ignore the row's OWN record — and re-importing the
                // same sheet would then fail every row that carries a NAM code,
                // against itself. The database catches a real collision; this
                // would only catch the re-import.
                ->rules(['nullable', 'string', 'max:32']),

            // Client 2026-09-25: "The Citing Reference Code is the primary key".
            // Her file agrees — 676 of 676 filled in, all distinct — so this is
            // the column rows are matched on, and the one that must be present.
            ImportColumn::make('alternative_identifier')
                ->label('Citing Reference Code')
                ->requiredMapping()
                // No generic spellings here. A sheet saved before 2026-09-25
                // has an "Identifier" column holding R1, R2, R3… — this
                // column's data under the old meaning — but the field NAME
                // `identifier` matches that header first, and no guess list can
                // outrank it. The row is then rejected for a missing Citing
                // Reference Code, which is the safe outcome: the operator
                // remaps the column in step 4 and sees what went where, rather
                // than the import quietly filing R-codes under the NAM code.
                ->guess(['Citing Reference Code'])
                // No format rule on purpose. Her codes read R1…R675 with one I,
                // but pinning the shape here is how the import ends up blocked
                // again the first time a code does not fit, waiting on a
                // release — which is the loop this work exists to break.
                ->rules(['required', 'string', 'max:32']),

            ImportColumn::make('alternative_identifier_warrant')
                ->label('Alternate Reference Code')
                // "Alternative Identifier" and the MS spellings land here: in
                // the old sheets that column held the MS number, which is what
                // the Alternate Reference Code holds today (511, 512, 513…).
                ->guess([
                    'Alternate Reference Code',
                    'Alternative Identifier (Warrant Number)', 'Warrant Number',
                    'Warrant', 'alternative_identifier_warrant',
                    'Alternative Identifier', 'Alt Identifier', 'MS', 'MS code',
                ])
                ->rules(['nullable', 'string', 'max:191']),

            // Client 2026-09-22. Plural by design: a record may list several
            // superseded identifiers, so no length cap beyond the column's.
            ImportColumn::make('previous_temporary_identifiers')
                ->label('Past Reference Code')
                ->guess([
                    'Past Reference Code',
                    'Previous Temporary Identifiers', 'Previous Temporary Identifier',
                    'Prev Temporary Identifiers', 'Previous Temp Identifiers',
                    'previous_temporary_identifiers',
                ])
                ->rules(['nullable', 'string', 'max:65535']),

            ImportColumn::make('surname')
                ->label('Creator Surname')
                ->requiredMappingForNewRecordsOnly()
                ->guess(['Creator Surname', 'Surname', 'Last Name', 'surname'])
                ->rules(['required', 'string', 'max:255']),

            ImportColumn::make('given_names')
                ->label('Creator Name')
                ->guess(['Creator Name', 'Name', 'Given Names', 'First Name'])
                ->rules(['nullable', 'string', 'max:255']),

            ImportColumn::make('entity_type')
                ->label('Type of Entity')
                ->guess(['Type of Entity', 'Entity Type', 'Type'])
                ->castStateUsing(fn (?string $state) => SpreadsheetParsers::normaliseEntityType($state))
                ->rules(['nullable', 'in:PERSON,INSTITUTION']),

            // Year range — we parse "1607-1629" → two integer columns. The
            // virtual column name (`practice_dates_active`) does NOT map to
            // a real DB column; the closure splits it.
            ImportColumn::make('practice_dates_active')
                ->label('Private Practice Dates Active')
                ->guess(['Private Practice Dates Active', 'Practice Dates', 'Dates Active'])
                ->fillRecordUsing(function (Authority $record, ?string $state): void {
                    [$start, $end] = SpreadsheetParsers::parseYearRange($state);
                    if ($start !== null) {
                        $record->practice_dates_start = $start;
                    }
                    if ($end !== null) {
                        $record->practice_dates_end = $end;
                    }
                }),

            // NTG = Notari tal-Gvern (Government Notaries). The client's sheet
            // carries this as a YEAR RANGE ("1882-1893"), identical in shape to
            // the private-practice dates, so it is parsed into the two integer
            // year columns ntg_dates_start / ntg_dates_end (a single date column
            // could not hold a range, which is why NTG dates previously never
            // imported — the value was dropped into `notes` as free text).
            ImportColumn::make('ntg_dates_active')
                ->label('NTG Dates Active')
                ->guess(['NTG Dates Active', 'NTG Dates'])
                ->fillRecordUsing(function (Authority $record, ?string $state): void {
                    [$start, $end] = SpreadsheetParsers::parseYearRange($state);
                    if ($start !== null) {
                        $record->ntg_dates_start = $start;
                    }
                    if ($end !== null) {
                        $record->ntg_dates_end = $end;
                    }
                }),

            // Client 2026-09-16 — ISAAR(CPF) descriptive fields.
            //
            // These sit BEFORE name_suffix and maiden_surname on purpose.
            // maiden_surname appends a line to `notes`, and Filament fills
            // columns in the order declared here: with `notes` declared after
            // it, the imported note would overwrite the appended maiden-surname
            // line and lose it without a word.
            ImportColumn::make('authorised_form_of_name')
                ->label('Authorised form of name')
                ->guess(['Authorised form of name', 'Authorized form of name', 'Authorised name'])
                ->rules(['nullable', 'string', 'max:65535']),

            ImportColumn::make('functions_occupations_activities')
                ->label('Functions, occupations and activities')
                ->guess([
                    'Functions, occupations and activities',
                    'Functions occupations and activities',
                    'Functions', 'Occupations',
                ])
                ->rules(['nullable', 'string', 'max:65535']),

            ImportColumn::make('level_of_detail')
                ->label('Level of detail')
                ->guess(['Level of detail', 'Level'])
                ->fillRecordUsing(function (Authority $record, ?string $state): void {
                    $record->level_of_detail = self::matchAllowed($state, Authority::LEVELS_OF_DETAIL, 'Level of detail', 'level_of_detail');
                }),

            ImportColumn::make('status')
                ->label('Status')
                ->guess(['Status', 'Record status'])
                ->fillRecordUsing(function (Authority $record, ?string $state): void {
                    $record->status = self::matchAllowed($state, Authority::RECORD_STATUSES, 'Status', 'status');
                }),

            ImportColumn::make('rules_and_conventions')
                ->label('Rules and/or conventions')
                ->guess(['Rules and/or conventions', 'Rules and conventions', 'Rules', 'Conventions'])
                ->rules(['nullable', 'string', 'max:65535']),

            ImportColumn::make('date_of_creation')
                ->label('Date of creation')
                ->guess(['Date of creation', 'Creation date', 'Dates'])
                ->fillRecordUsing(function (Authority $record, ?string $state): void {
                    $state = $state !== null ? trim($state) : '';
                    if ($state === '') {
                        return;
                    }
                    // Same treatment as series.date_of_creation: free text, so a
                    // span ("1607-1629") survives as written, and only a number
                    // that is plausibly an Excel serial is read as a date —
                    // "46232" on screen would be meaningless in the record.
                    $record->date_of_creation = SpreadsheetParsers::freeTextDateSerial($state) ?? $state;
                }),

            ImportColumn::make('creator_of_record')
                ->label('Creator of record')
                ->guess(['Creator of record', 'Record creator', 'Cataloguer'])
                ->rules(['nullable', 'string', 'max:65535']),

            // Notes has existed on the form and in the database all along but
            // had no import column, so it could be typed and never imported.
            ImportColumn::make('notes')
                ->label('Notes')
                ->guess(['Notes', 'Note', 'Remarks'])
                ->rules(['nullable', 'string', 'max:65535']),

            ImportColumn::make('name_suffix')
                ->label('Name Suffix')
                ->guess(['Name Suffix', 'Suffix'])
                ->fillRecordUsing(function (Authority $record, ?string $state): void {
                    if ($state === null || trim($state) === '') {
                        return;
                    }
                    // Append to given_names: "Antonio" + "Jr." → "Antonio Jr."
                    $given = trim((string) $record->given_names);
                    $record->given_names = $given === ''
                        ? trim($state)
                        : ($given . ' ' . trim($state));
                }),

            ImportColumn::make('maiden_surname')
                ->label('Maiden Surname')
                ->guess(['Maiden Surname', 'Maiden Name'])
                ->fillRecordUsing(function (Authority $record, ?string $state): void {
                    if ($state === null || trim($state) === '') {
                        return;
                    }
                    $prev = trim((string) $record->notes);
                    $line = 'Maiden surname: ' . trim($state);
                    $record->notes = $prev === '' ? $line : ($prev . "\n" . $line);
                }),
        ]);
    }

    /**
     * Idempotent matching by `identifier`. Re-importing the same sample
     * file updates existing rows in place instead of creating duplicates.
     */
    public function resolveRecord(): ?Authority
    {
        // `authorities.identifier` is globally unique — the unique index counts
        // soft-deleted rows too. Match withTrashed so re-importing an identifier
        // whose row was soft-deleted finds and RESTORES it instead of INSERTing a
        // duplicate that violates the unique constraint. Mirrors SeriesImporter /
        // BatchImporter's soft-delete handling.
        $citingReferenceCode = $this->data['alternative_identifier'] ?? null;

        // Matched on the Citing Reference Code since 2026-09-25: it is the one
        // the archive fills in for every creator. Matching on a column that is
        // empty on 88% of rows would make each import insert duplicates of the
        // same notary.
        $record = $citingReferenceCode === null || $citingReferenceCode === ''
            ? null
            : Authority::withTrashed()
                ->where('alternative_identifier', $citingReferenceCode)
                ->first();

        if ($record === null) {
            return new Authority;
        }

        // Defer the un-delete to saveRecord() (resolveRecord runs before
        // validateData) so a row that fails validation doesn't leave the
        // record restored — see SeriesImporter for the full rationale.
        if ($record->trashed()) {
            $record->{$record->getDeletedAtColumn()} = null;

            return $record;
        }

        $this->skipIfDuplicate($record);

        return $record;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Authorities import completed: '
            . number_format($import->successful_rows) . ' rows processed';
        if (($failed = $import->getFailedRowsCount()) > 0) {
            $body .= ', ' . number_format($failed) . ' failed';
        }

        return $body;
    }

    /**
     * Persist the row's custom-field values once the Authority is saved.
     *
     * Merge semantics (replaceMissing = false), as in the other importers: a
     * sheet that omits a custom column must not wipe a value already stored.
     */
    public function afterSave(): void
    {
        /** @var Authority $record */
        $record = $this->record;
        $key = spl_object_id($record);

        $customData = self::$rowCustomFieldStash[$key] ?? null;
        unset(self::$rowCustomFieldStash[$key]);

        if ($customData !== null && method_exists($record, 'setCustomFieldData')) {
            $record->setCustomFieldData($customData, false);
        }
    }

    /**
     * Dynamic custom-field columns for the 'authority' entity type.
     *
     * Client 2026-09-24: Authorities joined the entities whose extra columns
     * the cataloguer adds herself, instead of waiting on a release for a
     * column that depends on nothing. Mirrors VolumeImporter.
     *
     * @return array<ImportColumn>
     */
    protected static function getCustomFieldColumns(): array
    {
        $defs = CustomFieldResolver::definitionsFor('authority');
        if ($defs->isEmpty()) {
            return [];
        }

        $columns = [];
        foreach ($defs as $def) {
            $columns[] = ImportColumn::make('custom_field_' . $def->key)
                ->label($def->label . ' (custom field)')
                ->guess([$def->label, $def->key, 'cf_' . $def->key])
                ->rules(['nullable', 'string'])
                ->fillRecordUsing(static function (Authority $record, ?string $state) use ($def): void {
                    $key = spl_object_id($record);
                    static::$rowCustomFieldStash[$key][$def->key] = ($state !== null && trim($state) !== '')
                        ? trim($state)
                        : null;
                });
        }

        return $columns;
    }

    protected static function matchAllowed(?string $state, array $allowed, string $label, string $attribute): ?string
    {
        $state = $state !== null ? trim($state) : '';
        if ($state === '') {
            return null;
        }

        $normalise = static fn (string $v): string => preg_replace('/\s+/', ' ', mb_strtolower(trim($v))) ?? '';
        $wanted = $normalise($state);

        foreach ($allowed as $option) {
            if ($normalise($option) === $wanted) {
                // Store the canonical spelling, not the operator's casing, so
                // filters and grouping see one value rather than three.
                return $option;
            }
        }

        throw ValidationException::withMessages([
            $attribute => __(':label must be one of: :options (got ":given").', [
                'label' => $label,
                'options' => implode(', ', $allowed),
                'given' => $state,
            ]),
        ]);
    }

    private static function applyRenames(array $columns): array
    {
        $renameable = ColumnLabels::DEFAULTS['authority'];

        foreach ($columns as $column) {
            $key = $column->getName();
            if (! array_key_exists($key, $renameable)) {
                continue;
            }

            // Setting the LABEL is enough for the header to be recognised:
            // guessSingleColumn tries the field name, the label and the guess
            // list in that order, so the renamed header matches on the label.
            // The factory name keeps working because it is still in each
            // column's own guess list — see the test that pins exactly that.
            $label = ColumnLabels::get('authority', $key);
            $column->label($label);

            // Filament builds "The :attribute field is required" from
            // Str::lcfirst($label), which turns "Citing Reference Code" into
            // "citing Reference Code" — a column name with its first letter
            // knocked down, in the one message the cataloguer reads most.
            // Naming the attribute explicitly keeps the column's own name.
            $column->validationAttribute($label);
        }

        return [...$columns, ...self::getCustomFieldColumns()];
    }
}
