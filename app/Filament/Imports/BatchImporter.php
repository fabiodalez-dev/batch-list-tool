<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Models\Batch;
use App\Models\Scopes\RepositoryScope;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

/**
 * RFQ §3.1.3 — Bulk import for {@see Batch}.
 *
 * Batches are the top-level grouping unit (1..29 = Main Collection,
 * 30+ = Notary Accession, 50 = Wills only, and 33/34/36 are FORBIDDEN —
 * see {@see Batch::FORBIDDEN_NUMBERS}). The importer enforces the
 * forbidden-number rule client-side via `rules(['not_in:33,34,36'])`
 * so the operator gets a clean per-row error instead of a SQL-level
 * 1452 constraint violation.
 *
 * Repository scoping: every batch belongs to exactly one Repository
 * (tenant). When the operator launches the import we read the active
 * `default_repository_id` off the user as the *default* tenant, but
 * an optional `repository_code` column lets the spreadsheet override it
 * row-by-row when staff are loading multi-tenant data.
 */
class BatchImporter extends Importer
{
    protected static ?string $model = Batch::class;

    /**
     * @return array<ImportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ImportColumn::make('batch_number')
                ->label('Batch number')
                ->requiredMapping()
                ->integer()
                ->guess(['Batch number', 'Batch', 'batch_number', 'Number'])
                ->rules([
                    'required',
                    'integer',
                    'min:1',
                    // RFQ App.1 #1 — 33 (reserved for old MAV boxes only),
                    // 34 and 36 (unused, never to be used) cannot be created
                    // through the import path. The model-level MySQL CHECK
                    // constraint will refuse anyway, but rejecting here
                    // produces a readable per-row error.
                    'not_in:33,34,36',
                ]),

            ImportColumn::make('description')
                ->label('Description')
                ->guess(['Description', 'description', 'Notes', 'Label'])
                ->rules(['nullable', 'string', 'max:255']),

            ImportColumn::make('type')
                ->label('Type (MAIN_COLLECTION / NOTARY_ACCESSION)')
                ->guess(['Type', 'type', 'Batch type'])
                ->castStateUsing(function (?string $state): string {
                    $s = strtoupper(trim((string) $state));
                    if (in_array($s, ['MAIN_COLLECTION', 'NOTARY_ACCESSION'], true)) {
                        return $s;
                    }

                    // Auto-derive when the spreadsheet doesn't say: 1..29 →
                    // Main Collection, 30+ → Notary Accession (RFQ rule).
                    return 'MAIN_COLLECTION';
                })
                ->rules(['nullable', 'in:MAIN_COLLECTION,NOTARY_ACCESSION']),

            ImportColumn::make('is_active')
                ->label('Is active?')
                ->guess(['Active', 'is_active', 'Is active'])
                ->boolean()
                ->rules(['nullable', 'boolean']),

            // Optional repository override — when the operator uploads a
            // multi-tenant spreadsheet they can supply a Repository code
            // ("NRA", "MUS", etc.) per row. The resolver looks up the
            // tenant id and stamps it onto the record; if absent we fall
            // back to the user's default_repository_id (handled by the
            // BelongsToRepository creating-hook).
            ImportColumn::make('repository_code')
                ->label('Repository code')
                ->guess(['Repository', 'Repo', 'repository_code', 'Tenant'])
                ->fillRecordUsing(function (Batch $record, ?string $state): void {
                    if ($state === null || trim($state) === '') {
                        return;
                    }
                    $res = EntityResolver::resolveRepository($state);
                    if ($res !== null) {
                        $record->repository_id = $res['repository_id'];
                    }
                }),
        ];
    }

    /**
     * Idempotent matching by `batch_number` (unique in schema). Re-running
     * the same file updates existing rows; new numbers get inserted.
     *
     * We bypass the RepositoryScope here because operators with
     * super_admin / admin privileges can legitimately update batches in any
     * tenant — the BelongsToRepository hook does the real tenancy check
     * when we call save().
     */
    public function resolveRecord(): ?Batch
    {
        $number = $this->data['batch_number'] ?? null;
        if ($number === null) {
            return new Batch;
        }

        return Batch::query()
            ->withoutGlobalScope(RepositoryScope::class)
            ->where('batch_number', (int) $number)
            ->first() ?? new Batch;
    }

    /**
     * Default type=NOTARY_ACCESSION when batch_number ≥ 30 and the
     * operator left the Type column unmapped or blank — matches the
     * sample-data convention used by `nra:import-samples`.
     */
    public function afterFill(): void
    {
        /** @var Batch $record */
        $record = $this->record;
        if ($record->batch_number !== null
            && (int) $record->batch_number >= 30
            && empty($record->type)
        ) {
            $record->type = 'NOTARY_ACCESSION';
        }
        if (empty($record->type)) {
            $record->type = 'MAIN_COLLECTION';
        }
        if ($record->is_active === null) {
            $record->is_active = true;
        }
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Batches import completed: '
            . number_format($import->successful_rows) . ' rows processed';
        if (($failed = $import->getFailedRowsCount()) > 0) {
            $body .= ', ' . number_format($failed) . ' failed';
        }

        return $body;
    }
}
