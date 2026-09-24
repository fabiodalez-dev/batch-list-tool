<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\LogsImportRows;
use App\Filament\Imports\Concerns\SkipsExistingRows;
use App\Models\Authority;
use App\Support\BulkImport\SpreadsheetParsers;
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
     * @return array<ImportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ImportColumn::make('identifier')
                ->label('NAM Authority Reference Code')
                // `requiredMapping` (not `requiredMappingForNewRecordsOnly`)
                // because Authority rows are matched on this column — we
                // cannot dedupe without it.
                ->requiredMapping()
                // The template header became "Authority Record Identifier (NAM)"
                // on 2026-09-16. The old spellings stay in the list so sheets
                // saved before that keep importing without remapping.
                // Renamed 2026-09-24. Every earlier spelling stays in the
                // list: sheets downloaded before today must keep importing
                // without the operator remapping anything by hand.
                ->guess([
                    'NAM Authority Reference Code',
                    'Authority Record Identifier (NAM)', 'Authority Record Identifier',
                    'Identifier', 'identifier', 'ID', 'R-code', 'Code', 'NAM',
                ])
                ->rules(['required', 'string', 'max:32']),

            ImportColumn::make('alternative_identifier')
                ->label('Citing Reference Code')
                ->guess(['Citing Reference Code', 'Alternative Identifier', 'Alt Identifier', 'MS', 'MS code'])
                ->rules(['nullable', 'string', 'max:32']),

            ImportColumn::make('alternative_identifier_warrant')
                ->label('Alternate Reference Code')
                ->guess([
                    'Alternate Reference Code',
                    'Alternative Identifier (Warrant Number)', 'Warrant Number',
                    'Warrant', 'alternative_identifier_warrant',
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
        ];
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
        $record = Authority::withTrashed()
            ->where('identifier', $this->data['identifier'] ?? null)
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
}
