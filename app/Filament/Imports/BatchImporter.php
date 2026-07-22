<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\SkipsExistingRows;
use App\Models\Batch;
use App\Models\CustomFieldDefinition;
use App\Models\Scopes\RepositoryScope;
use App\Support\BulkImport\EntityResolver;
use App\Support\CustomFields\CustomFieldResolver;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * RFQ §3.1.3 — Bulk import for {@see Batch}.
 *
 * Batches are the top-level grouping unit (1..29 = Main Collection,
 * 30+ = Notary Accession, 50 = Wills only). Batch 33 is RESERVED for old
 * MAV boxes (valid, not forbidden). Batches 34 and 36 are unused/forbidden.
 * See {@see Batch::FORBIDDEN_NUMBERS} and {@see Batch::RESERVED_MAV_BATCH}.
 * The importer enforces the forbidden-number rule client-side via a custom
 * closure rule (driven by Batch::isForbidden()) so the operator gets a clean
 * per-row error instead of a SQL-level 1452 constraint violation.
 *
 * Repository scoping: every batch belongs to exactly one Repository
 * (tenant). When the operator launches the import we read the active
 * `default_repository_id` off the user as the *default* tenant, but
 * an optional `repository_code` column lets the spreadsheet override it
 * row-by-row when staff are loading multi-tenant data.
 */
class BatchImporter extends Importer
{
    use SkipsExistingRows;

    protected static ?string $model = Batch::class;

    /**
     * Per-row stash for custom-field key→value data extracted from columns
     * whose header matches a custom-field definition label or key. Persisted
     * in {@see afterSave()} via $record->setCustomFieldData().
     * Keyed by spl_object_id of the record, same pattern as DocumentImporter.
     *
     * @var array<int, array<string, string|null>>
     */
    protected static array $rowCustomFieldStash = [];

    /**
     * @return array<ImportColumn>
     */
    public static function getColumns(): array
    {
        return array_merge(static::getStaticColumns(), static::getCustomFieldColumns());
    }

    /**
     * Persist custom-field side effects after the Batch row has been saved.
     * Uses merge semantics (replaceMissing=false) so unmapped columns are not
     * touched — identical pattern to DocumentImporter::persistRowSideEffects().
     */
    public function afterSave(): void
    {
        /** @var Batch $record */
        $record = $this->record;
        $key = spl_object_id($record);

        $customData = self::$rowCustomFieldStash[$key] ?? null;
        unset(self::$rowCustomFieldStash[$key]);

        if ($customData !== null && method_exists($record, 'setCustomFieldData')) {
            // No try/catch: setCustomFieldData() coerces with a total (string)
            // cast that cannot throw on a malformed cell, so the only realistic
            // exception is a DB persistence error — which MUST fail the row
            // (surfacing in the failed-rows report) rather than commit the Batch
            // with partial/missing custom fields.
            $record->setCustomFieldData($customData, false);
        }
    }

    /**
     * Idempotent matching by `batch_number` (unique in schema). Re-running
     * the same file updates existing rows; new numbers get inserted.
     *
     * We bypass the RepositoryScope here because operators with
     * super_admin / admin privileges can legitimately update batches in any
     * tenant — the BelongsToRepository hook does the real tenancy check
     * when we call save().
     *
     * Soft-deletes: Batch uses SoftDeletes and the (batch_number,
     * repository_id) unique index covers trashed rows. If the operator
     * imported a file, deleted a created batch, then re-imported the same
     * file, a fresh INSERT would collide with the soft-deleted row and fail
     * the row with an opaque SQL error (NAF Feedback-1 comment #3). We
     * therefore match WITH trashed rows and restore a soft-deleted hit so the
     * re-import un-deletes and updates it in place instead of inserting.
     */
    public function resolveRecord(): ?Batch
    {
        $number = $this->data['batch_number'] ?? null;
        if ($number === null) {
            return new Batch;
        }

        // The DB unique key is (batch_number, repository_id) — each repository
        // may own its own Batch 50, etc. Match within the SAME repository the
        // row targets (its repository_code, else the importing user's default),
        // otherwise re-importing batch N for repo B would match repo A's row and
        // silently reassign (steal) it on save.
        $repositoryId = null;
        $repoCode = $this->data['repository_code'] ?? null;
        if ($repoCode !== null && trim((string) $repoCode) !== '') {
            $resolved = EntityResolver::resolveRepository(trim((string) $repoCode));
            $repositoryId = $resolved['repository_id'] ?? null;
        }
        $repositoryId ??= auth()->user()?->default_repository_id;

        // No repository determinable (neither repository_code nor a user default
        // — the likely profile of a cross-tenant super_admin): we CANNOT match on
        // batch_number alone across every repository, or we reintroduce the very
        // cross-tenant steal this fix prevents. Treat it as a new record; the
        // insert then fails cleanly on the NOT NULL repository_id instead.
        if ($repositoryId === null) {
            return new Batch;
        }

        /** @var Batch|null $record */
        $record = Batch::query()
            ->withoutGlobalScope(RepositoryScope::class)
            ->withTrashed()
            ->where('batch_number', (int) $number)
            ->where('repository_id', $repositoryId)
            ->first();

        if ($record === null) {
            return new Batch;
        }

        // A soft-deleted match means the operator is re-importing a row they
        // previously deleted: restore + update it (idempotent un-delete) and
        // never treat it as a skippable duplicate — they clearly want it back.
        if ($record->trashed()) {
            $record->restore();

            return $record;
        }

        // Only a live record can be a "duplicate" for skip-duplicates semantics.
        $this->skipIfDuplicate($record);

        return $record;
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

    /**
     * Static (non-EAV) import columns for Batch.
     *
     * @return array<ImportColumn>
     */
    protected static function getStaticColumns(): array
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
                    // RFQ App.1 #1 — batch 34 and 36 are unused and will never
                    // be used (forbidden). Batch 33 is reserved for old MAV
                    // boxes and IS a valid batch number. We drive this rule from
                    // Batch::isForbidden() so there is a single source of truth.
                    function (string $attribute, mixed $value, \Closure $fail): void {
                        $candidate = new Batch(['batch_number' => (int) $value]);
                        if ($candidate->isForbidden()) {
                            $fail("Batch number {$value} is reserved/forbidden (RFQ rule): cannot be imported.");
                        }
                    },
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
     * Dynamic custom-field columns for the 'batch' entity type.
     *
     * Mirrors DocumentImporter::getCustomFieldColumns(). Each column is guessed
     * by the definition label, the cf_{key} form, and the bare key so operators
     * can use either the friendly label or the internal key as a column header.
     *
     * Values are stashed in {@see $rowCustomFieldStash} and persisted in
     * {@see afterSave()} via $record->setCustomFieldData(..., false) (merge
     * semantics — only mapped columns touch existing values).
     *
     * A bad custom-field cell (wrong type, unrecognised value) must NOT fail
     * the row. Type coercion is lenient: if the raw string cannot be parsed
     * the value is stored as-is (the trait handles the final cast on read).
     *
     * @return array<ImportColumn>
     */
    protected static function getCustomFieldColumns(): array
    {
        /** @var EloquentCollection<int, CustomFieldDefinition> $defs */
        $defs = CustomFieldResolver::definitionsFor('batch');
        if ($defs->isEmpty()) {
            return [];
        }

        $columns = [];
        foreach ($defs as $def) {
            $columns[] = ImportColumn::make('custom_field_' . $def->key)
                ->label($def->label . ' (custom field)')
                ->guess([$def->label, $def->key, 'cf_' . $def->key])
                ->rules(['nullable', 'string'])
                ->fillRecordUsing(static function (Batch $record, ?string $state) use ($def): void {
                    $key = spl_object_id($record);
                    // Always stash the key — null/empty means "clear this field".
                    // Absent columns (never reach this closure) are left untouched.
                    static::$rowCustomFieldStash[$key][$def->key] = ($state !== null && trim($state) !== '')
                        ? trim($state)
                        : null;
                });
        }

        return $columns;
    }
}
