<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\SkipsExistingRows;
use App\Models\Series;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

/**
 * RFQ §3.1.3 — Bulk import for {@see Series} (record-group / "fond" codes:
 * R, REG, RWL, O, …).
 *
 * `Series_Sample.xlsx` has 29 rows and an unusual layout: column A is a
 * label like `"R: Register Copies (Registro)"` (combining code+title), and
 * column B has the canonical code as `"Identifier"`. We map column B → code
 * primarily, falling back to A's prefix when the operator's spreadsheet
 * lacks the canonical column.
 *
 * `is_wills_series` is derived heuristically: any code containing "WL" or
 * any title containing "will" is flagged. RFQ App.1 #2 requires wills to
 * land in batch 50, and the flag is what triggers the cross-checks
 * downstream — getting it right at import is critical.
 */
class SeriesImporter extends Importer
{
    use SkipsExistingRows;

    protected static ?string $model = Series::class;

    /**
     * @return array<ImportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ImportColumn::make('code')
                ->label('Identifier (code)')
                ->requiredMapping()
                ->guess(['Identifier', 'Code', 'identifier', 'code'])
                ->castStateUsing(function (?string $state): ?string {
                    if ($state === null) {
                        return null;
                    }
                    // Operators sometimes paste the full label
                    // "REG: Registers Private Practice" into the code column
                    // — split on the colon and keep the part before it.
                    $candidate = trim($state);
                    if (str_contains($candidate, ':')) {
                        $candidate = trim(explode(':', $candidate, 2)[0]);
                    }
                    // Schema limit: VARCHAR(16). Anything longer is a typo.
                    $candidate = mb_substr($candidate, 0, 16);

                    return $candidate === '' ? null : $candidate;
                })
                ->rules(['required', 'string', 'max:16']),

            ImportColumn::make('title')
                ->label('Standard title in English (Plural)')
                ->requiredMappingForNewRecordsOnly()
                ->guess([
                    'Standard title in English (Plural)',
                    'Standard title',
                    'Title',
                    'title',
                ])
                ->rules(['required', 'string', 'max:255']),

            // Column A in the sample file is the combined "R: Register Copies
            // (Registro)" label. We capture it as `description` so it's not
            // lost on import (the operator can see the legacy label later).
            ImportColumn::make('description')
                ->label('Description / legacy label')
                ->guess([
                    'Description',
                    'Legacy label',
                    'Title and code',
                    'R: Register Copies (Registro)', // exact column-A header in sample
                ])
                ->rules(['nullable', 'string', 'max:65535']),

            // is_wills_series is derived heuristically post-fill — see the
            // `afterFill` hook below. Exposing it as an optional column lets
            // the operator override the heuristic when their data is already
            // tagged.
            ImportColumn::make('is_wills_series')
                ->label('Is wills series?')
                ->guess(['Is wills series', 'Wills', 'is_wills_series'])
                ->boolean()
                ->rules(['nullable', 'boolean']),

            ImportColumn::make('is_active')
                ->label('Is active?')
                ->guess(['Is active', 'Active', 'is_active'])
                ->boolean()
                ->rules(['nullable', 'boolean']),
        ];
    }

    /**
     * Idempotent matching by `code` — re-running the same file updates
     * existing rows instead of creating duplicates.
     */
    public function resolveRecord(): ?Series
    {
        $code = $this->data['code'] ?? null;
        if ($code === null) {
            return new Series;
        }

        $record = Series::query()
            ->whereRaw('LOWER(code) = ?', [mb_strtolower((string) $code)])
            ->first() ?? new Series;
        $this->skipIfDuplicate($record);

        return $record;
    }

    /**
     * Derive `is_wills_series` from the code/title when the operator did
     * not map an explicit column for it. Heuristic: any code containing
     * "WL" (RWL, OWL, WL) or any title containing the word "will" (case-
     * insensitive) marks the series as wills.
     */
    public function afterFill(): void
    {
        /** @var Series $record */
        $record = $this->record;

        // Default is_active when missing — Series defaults to active. The
        // model's column default would handle this on INSERT, but we set it
        // explicitly so the Filament audit row shows the value chosen
        // rather than "(null)".
        if ($record->is_active === null) {
            $record->is_active = true;
        }

        // Only auto-derive when the operator did not map an explicit value.
        if (! array_key_exists('is_wills_series', $this->columnMap) || blank($this->columnMap['is_wills_series'])) {
            $code = (string) ($record->code ?? '');
            $title = (string) ($record->title ?? '');
            $record->is_wills_series =
                str_contains(strtolower($code), 'wl')
                || str_contains(strtolower($title), 'will');
        }
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Series import completed: '
            . number_format($import->successful_rows) . ' rows processed';
        if (($failed = $import->getFailedRowsCount()) > 0) {
            $body .= ', ' . number_format($failed) . ' failed';
        }

        return $body;
    }
}
