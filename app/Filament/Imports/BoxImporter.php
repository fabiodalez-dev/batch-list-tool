<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\SkipsExistingRows;
use App\Models\Batch;
use App\Models\Box;
use App\Models\CustomFieldDefinition;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Support\BulkImport\EntityResolver;
use App\Support\BulkImport\SpreadsheetParsers;
use App\Support\CustomFields\CustomFieldResolver;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;

/**
 * RFQ §3.1.3 — Bulk import for {@see Box}.
 *
 * A Box (RAS, IN_SITU, NRA, MAV, STVC) is a physical container that holds
 * a set of documents and lives inside a Batch. Critical RFQ rules
 * enforced here:
 *
 *   - #3: IN_SITU and NRA boxes MUST reference a parent RAS box.
 *   - #4: MAV / STVC types cannot be created for new records, only existing
 *     legacy boxes can have those types — enforced as `is_legacy=true`
 *     when type ∈ {MAV, STVC}.
 *   - #5: a box marked PERM_OUT MUST have a non-null disinfestation_date.
 *
 * The importer accepts a Batch via either its numeric id OR its
 * `batch_number` (the latter is what operators have on hand in the
 * spreadsheet). Parent boxes can be referenced by their barcode.
 */
class BoxImporter extends Importer
{
    use SkipsExistingRows;

    protected static ?string $model = Box::class;

    /**
     * Per-row stash for custom-field key→value data. Persisted in
     * {@see afterSave()} via $record->setCustomFieldData(..., false).
     * Keyed by spl_object_id of the record.
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
     * Persist custom-field side effects after the Box row has been saved.
     * Uses merge semantics (replaceMissing=false): only explicitly mapped
     * columns touch stored values. Failures are swallowed so a bad custom
     * cell never fails an otherwise valid row.
     */
    public function afterSave(): void
    {
        /** @var Box $record */
        $record = $this->record;
        $key = spl_object_id($record);

        $customData = self::$rowCustomFieldStash[$key] ?? null;
        unset(self::$rowCustomFieldStash[$key]);

        if ($customData !== null && method_exists($record, 'setCustomFieldData')) {
            // No try/catch: setCustomFieldData() coerces with a total (string)
            // cast that cannot throw on a malformed cell, so the only realistic
            // exception is a DB persistence error — which MUST fail the row
            // (surfacing in the failed-rows report) rather than commit the Box
            // with partial/missing custom fields.
            $record->setCustomFieldData($customData, false);
        }
    }

    /**
     * Idempotent matching: only the (unique) barcode is used for duplicate
     * detection. A (batch_id, box_number) fallback is NOT implemented — see the
     * note in the body: batch_id is resolved later in the column fill closures,
     * not here, so barcode-less rows always insert a new Box.
     */
    public function resolveRecord(): ?Box
    {
        $barcode = $this->data['barcode'] ?? null;
        if ($barcode !== null && trim((string) $barcode) !== '') {
            $existing = Box::query()
                ->withoutGlobalScope(ThroughBatchRepositoryScope::class)
                ->where('barcode', trim((string) $barcode))
                ->first();
            if ($existing !== null) {
                // RFQ §3.1.3 — honour the "skip duplicates" checkbox for the
                // ONE case we can detect an existing box: a barcode hit.
                $this->skipIfDuplicate($existing);

                return $existing;
            }
        }

        // note: skip_duplicates is a no-op for barcode-less rows. The only
        // idempotent key BoxImporter can match on is the (unique) barcode;
        // a (batch_id + box_number) lookup is not possible here because
        // batch_id is resolved later, in the column fill closures, not in
        // resolveRecord(). Such rows always insert a new Box.
        return new Box;
    }

    /**
     * Apply the three RFQ rules that depend on multiple fields:
     *
     *  #3 — IN_SITU and NRA require a parent RAS box. If the operator
     *       supplied no parent barcode we cannot proceed safely; we leave
     *       parent_box_id null and let the row fail with an explicit
     *       validation message via the `before save` check.
     *  #4 — MAV / STVC force `is_legacy = true`.
     *  #5 — PERM_OUT requires `disinfestation_date`.
     */
    public function afterFill(): void
    {
        /** @var Box $record */
        $record = $this->record;

        if (in_array($record->box_type, ['MAV', 'STVC'], true)) {
            $record->is_legacy = true;
        }

        if ($record->barcode_status === 'PERM_OUT' && $record->disinfestation_date === null) {
            // Drain the stash before throwing so the static map does not grow
            // unboundedly when many rows fail — afterSave() never runs for a
            // row rejected here.
            unset(self::$rowCustomFieldStash[spl_object_id($record)]);

            // We refuse the row with a validation exception — this is what
            // surfaces in the per-row failed export.
            throw ValidationException::withMessages([
                'disinfestation_date' => __('Boxes marked PERM_OUT must carry a disinfestation_date.'),
            ]);
        }

        // #5b — A new box marked PERM_OUT must also carry a Location: a box that
        // has permanently left storage has to record where it now lives. The
        // model guard skips this for new records (and for the documented legacy
        // mirror), so enforce it at the row level for fresh imports only.
        if (! $record->exists && $record->barcode_status === 'PERM_OUT' && $record->location_id === null) {
            // Drain the stash before throwing — afterSave() never runs for a
            // row rejected here.
            unset(self::$rowCustomFieldStash[spl_object_id($record)]);

            throw ValidationException::withMessages([
                'location' => __('A box marked PERM OUT must have a location. Add a Location column (a valid location code for this repository) for this row.'),
            ]);
        }

        if (in_array($record->box_type, ['IN_SITU', 'NRA'], true) && $record->parent_box_id === null) {
            // Drain the stash on this failure path too.
            unset(self::$rowCustomFieldStash[spl_object_id($record)]);

            // RFQ #3 — IN_SITU / NRA boxes MUST reference a parent RAS box.
            // Reject the row instead of inserting an orphan.
            throw ValidationException::withMessages([
                'parent_box_id' => __('IN_SITU and NRA boxes must reference a parent RAS box (via barcode).'),
            ]);
        }
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Boxes import completed: '
            . number_format($import->successful_rows) . ' rows processed';
        if (($failed = $import->getFailedRowsCount()) > 0) {
            $body .= ', ' . number_format($failed) . ' failed';
        }

        return $body;
    }

    /**
     * Static (non-EAV) import columns for Box.
     *
     * @return array<ImportColumn>
     */
    protected static function getStaticColumns(): array
    {
        return [
            ImportColumn::make('box_number')
                ->label('Box number')
                ->requiredMapping()
                ->guess(['Box number', 'box_number', 'Box', 'Number'])
                ->rules(['required', 'string', 'max:32']),

            ImportColumn::make('box_type')
                ->label('Box type (RAS / IN_SITU / NRA / MAV / STVC)')
                ->requiredMappingForNewRecordsOnly()
                ->guess(['Box type', 'Type', 'box_type'])
                ->castStateUsing(function (?string $state): ?string {
                    if ($state === null) {
                        return null;
                    }
                    $s = strtoupper(trim($state));

                    // Accept some common aliases.
                    return match ($s) {
                        'IN SITU', 'IN-SITU' => 'IN_SITU',
                        'PERM_OUT', 'PERMOUT' => $s, // not a type, but be tolerant
                        default => $s,
                    };
                })
                ->rules(['required', 'in:RAS,IN_SITU,NRA,MAV,STVC']),

            // Batch lookup by batch_number — the friendlier alternative to
            // forcing operators to type DB ids.
            ImportColumn::make('batch_number')
                ->label('Batch number')
                ->requiredMappingForNewRecordsOnly()
                ->integer()
                ->guess(['Batch number', 'Batch', 'batch_number'])
                ->fillRecordUsing(function (Box $record, mixed $state): void {
                    $n = SpreadsheetParsers::parseInt($state);
                    if ($n === null) {
                        return;
                    }
                    $res = EntityResolver::resolveBatch($n);
                    if ($res === null || isset($res['forbidden'])) {
                        // We don't throw here — `rules()` on a separate
                        // column would, but Box requires the batch FK to
                        // satisfy NOT NULL on insert, so leave the column
                        // empty and let the resulting SQL constraint
                        // failure surface in the failed-rows export.
                        return;
                    }
                    $record->batch_id = $res['batch_id'];
                }),

            ImportColumn::make('parent_barcode')
                ->label('Parent box barcode (for IN_SITU / NRA)')
                ->guess(['Parent barcode', 'parent_barcode', 'Parent RAS barcode'])
                ->fillRecordUsing(function (Box $record, ?string $state): void {
                    if ($state === null || trim($state) === '') {
                        return;
                    }
                    $res = EntityResolver::resolveBox(trim($state));
                    if ($res !== null) {
                        $record->parent_box_id = $res['box_id'];
                    }
                    // The parent's TYPE is validated centrally in Box::saving
                    // (RFQ App.1 #3: the parent must be a RAS box). A barcode
                    // that resolves to a non-RAS box — or no box at all — is
                    // rejected there on save and surfaces in the failed-rows
                    // export, so we deliberately do not duplicate that check.
                }),

            ImportColumn::make('barcode')
                ->label('Barcode')
                ->guess(['Barcode', 'barcode', 'Barcode (IN)'])
                // RAS boxes require a barcode (Feedback1 C2.1) — enforced here at
                // the importer input boundary (and in the Filament form), not in
                // the model save, so legitimate bulk/seed/provisional creation of
                // a not-yet-barcoded RAS record isn't blocked. Legacy MAV/STVC and
                // provenance IN_SITU/NRA rows may import without a barcode.
                ->rules(['nullable', 'string', 'max:64', 'required_if:box_type,RAS']),

            ImportColumn::make('barcode_status')
                ->label('Barcode status (IN / OUT / PERM_OUT)')
                ->guess(['Barcode status', 'Status', 'barcode_status'])
                ->castStateUsing(function (?string $state): ?string {
                    if ($state === null) {
                        return null;
                    }
                    $s = strtoupper(trim($state));

                    return in_array($s, ['IN', 'OUT', 'PERM_OUT'], true) ? $s : null;
                })
                ->rules(['nullable', 'in:IN,OUT,PERM_OUT']),

            ImportColumn::make('disinfestation_date')
                ->label('Disinfestation date')
                ->guess(['Disinfestation date', 'Disinfestation Date', 'disinfestation_date'])
                ->castStateUsing(fn (mixed $state) => SpreadsheetParsers::parseDate($state))
                ->rules(['nullable', 'date']),

            ImportColumn::make('is_legacy')
                ->label('Is legacy box?')
                ->guess(['Is legacy', 'is_legacy', 'Legacy'])
                ->boolean()
                ->rules(['nullable', 'boolean']),

            ImportColumn::make('notes')
                ->label('Notes')
                ->guess(['Notes', 'notes', 'Note'])
                ->rules(['nullable', 'string']),

            // F05 (feedback review) — Seal Number and Location columns added
            // per client request ("The following fields should also be part
            // of the importation process: Seal Number, Location").
            ImportColumn::make('seal_number')
                ->label('Seal Number')
                ->guess(['Seal Number', 'seal_number', 'Seal No', 'Seal no'])
                ->rules(['nullable', 'string', 'max:64']),

            ImportColumn::make('location')
                ->label('Location (code / identifier)')
                ->guess(['Location', 'location', 'Location Code', 'Location Identifier'])
                ->fillRecordUsing(function (Box $record, ?string $state): void {
                    if ($state === null || trim($state) === '') {
                        return;
                    }
                    // Tenancy: location codes are unique per repository, so
                    // the lookup must be scoped to the row's effective
                    // repository — the batch's repository when already
                    // resolved (the batch_number column fills before this
                    // one), else the importing user's default.
                    $repoId = null;
                    if ($record->batch_id !== null) {
                        $raw = Batch::query()
                            ->withoutGlobalScopes()
                            ->whereKey($record->batch_id)
                            ->value('repository_id');
                        $repoId = $raw !== null ? (int) $raw : null;
                    }
                    $repoId ??= auth()->user()?->default_repository_id;

                    $res = EntityResolver::resolveLocation(trim($state), $repoId);
                    if ($res === null) {
                        throw ValidationException::withMessages([
                            'location' => "Unknown location code: '{$state}'. Ensure the location exists in this repository before importing.",
                        ]);
                    }
                    $record->location_id = $res['location_id'];
                }),
        ];
    }

    /**
     * Dynamic custom-field columns for the 'box' entity type.
     *
     * Mirrors DocumentImporter::getCustomFieldColumns() / BatchImporter equivalent.
     * Values are stashed in {@see $rowCustomFieldStash} and persisted in
     * {@see afterSave()} with merge semantics so unmapped columns are untouched.
     *
     * A bad custom-field cell must NOT fail the row — the try/catch in
     * afterSave() absorbs any type-coercion error after the Box row is saved.
     *
     * @return array<ImportColumn>
     */
    protected static function getCustomFieldColumns(): array
    {
        /** @var EloquentCollection<int, CustomFieldDefinition> $defs */
        $defs = CustomFieldResolver::definitionsFor('box');
        if ($defs->isEmpty()) {
            return [];
        }

        $columns = [];
        foreach ($defs as $def) {
            $columns[] = ImportColumn::make('custom_field_' . $def->key)
                ->label($def->label . ' (custom field)')
                ->guess([$def->label, $def->key, 'cf_' . $def->key])
                ->rules(['nullable', 'string'])
                ->fillRecordUsing(static function (Box $record, ?string $state) use ($def): void {
                    $key = spl_object_id($record);
                    static::$rowCustomFieldStash[$key][$def->key] = ($state !== null && trim($state) !== '')
                        ? trim($state)
                        : null;
                });
        }

        return $columns;
    }
}
