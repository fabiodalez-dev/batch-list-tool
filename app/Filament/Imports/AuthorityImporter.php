<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Models\Authority;
use App\Support\BulkImport\SpreadsheetParsers;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

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
    protected static ?string $model = Authority::class;

    /**
     * @return array<ImportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ImportColumn::make('identifier')
                ->label('Identifier')
                // `requiredMapping` (not `requiredMappingForNewRecordsOnly`)
                // because Authority rows are matched on this column — we
                // cannot dedupe without it.
                ->requiredMapping()
                ->guess(['Identifier', 'identifier', 'ID', 'R-code', 'Code'])
                ->rules(['required', 'string', 'max:32']),

            ImportColumn::make('alternative_identifier')
                ->label('Alternative Identifier')
                ->guess(['Alternative Identifier', 'Alt Identifier', 'MS', 'MS code'])
                ->rules(['nullable', 'string', 'max:32']),

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

            // NTG = Notari tal-Gvern (Government Notaries). Stored alongside
            // private practice dates in the `notes` field — the schema does
            // not have dedicated columns for it.
            ImportColumn::make('ntg_dates_active')
                ->label('NTG Dates Active')
                ->guess(['NTG Dates Active', 'NTG Dates'])
                ->fillRecordUsing(function (Authority $record, ?string $state): void {
                    if ($state === null || trim($state) === '') {
                        return;
                    }
                    $prev = trim((string) $record->notes);
                    $line = 'NTG dates: ' . trim($state);
                    $record->notes = $prev === '' ? $line : ($prev . "\n" . $line);
                }),

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
        return Authority::query()
            ->where('identifier', $this->data['identifier'] ?? null)
            ->first() ?? new Authority;
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
}
