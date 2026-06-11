<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\SkipsExistingRows;
use App\Models\Batch;
use App\Models\Box;
use App\Models\CustomFieldDefinition;
use App\Models\Repository;
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
     * F023: Per-row stash for the importing user's effective repository id.
     * Set in {@see resolveRecord()} on both return paths (barcode-hit existing
     * box AND new Box) so the batch_number closure can pass a tenant-scoped id
     * to EntityResolver::resolveBatch() instead of leaving it null.
     *
     * Mirrors DocumentImporter::$rowRepositoryStash (BUG-05 fix). Keyed by
     * spl_object_id($record) so the static column closures can access the
     * per-row value. Drained in afterSave() and on every afterFill() throw path.
     *
     * @var array<int, int|null>
     */
    protected static array $rowRepositoryStash = [];

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

        // F023: drain the repository stash for this row.
        unset(self::$rowRepositoryStash[$key]);

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
     *
     * F023: Resolves and stashes the acting user's repository id once per row
     * so the batch_number and parent_barcode closures can use a tenant-scoped id.
     */
    public function resolveRecord(): ?Box
    {
        $user = auth()->user();
        $repoId = $user?->default_repository_id !== null ? (int) $user->default_repository_id : null;

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

                // F023: stash repo id so the static closures can use it.
                self::$rowRepositoryStash[spl_object_id($existing)] = $repoId;

                return $existing;
            }
        }

        // note: skip_duplicates is a no-op for barcode-less rows. The only
        // idempotent key BoxImporter can match on is the (unique) barcode;
        // a (batch_id + box_number) lookup is not possible here because
        // batch_id is resolved later, in the column fill closures, not in
        // resolveRecord(). Such rows always insert a new Box.
        $record = new Box;
        // F023: stash repo id so the static closures can use it.
        self::$rowRepositoryStash[spl_object_id($record)] = $repoId;

        return $record;
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
            // Drain the stashes before throwing so the static maps do not grow
            // unboundedly when many rows fail — afterSave() never runs for a
            // row rejected here.
            unset(
                self::$rowRepositoryStash[spl_object_id($record)],
                self::$rowCustomFieldStash[spl_object_id($record)],
            );

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
            // Drain the stashes before throwing — afterSave() never runs for a
            // row rejected here.
            unset(
                self::$rowRepositoryStash[spl_object_id($record)],
                self::$rowCustomFieldStash[spl_object_id($record)],
            );

            throw ValidationException::withMessages([
                'location' => __('A box marked PERM OUT must have a location. Add a Location column (a valid location code for this repository) for this row.'),
            ]);
        }

        if (in_array($record->box_type, ['IN_SITU', 'NRA'], true) && $record->parent_box_id === null) {
            // Drain the stashes on this failure path too.
            unset(
                self::$rowRepositoryStash[spl_object_id($record)],
                self::$rowCustomFieldStash[spl_object_id($record)],
            );

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
                    // F023: FORBIDDEN_NUMBERS short-circuit fires BEFORE tenant
                    // scoping — a forbidden batch is never a valid target regardless
                    // of which repository is importing.
                    if (in_array($n, Batch::FORBIDDEN_NUMBERS, true)) {
                        // Leave batch_id null — let the NOT NULL constraint surface
                        // as a failed-row error (same as the original path).
                        return;
                    }
                    // F023: read the per-row repository id from the static stash
                    // (set in resolveRecord()) so the batch lookup is tenant-scoped
                    // and a same-numbered batch in another repository is never picked.
                    // BoxImporter must NOT auto-create batches (create:false).
                    $repoId = self::$rowRepositoryStash[spl_object_id($record)] ?? null;
                    $res = EntityResolver::resolveBatch($n, $repoId);
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
                    // Barcodes are globally unique — resolveBox() is intentionally
                    // unscoped (see BUG-09 fix). Its docblock delegates cross-tenant
                    // validation to the caller (this closure).
                    $res = EntityResolver::resolveBox(trim($state));
                    if ($res === null) {
                        // Unknown barcode — leave parent_box_id null; afterFill()
                        // will reject IN_SITU/NRA rows with no parent.
                        return;
                    }

                    // F030: validate that the parent box belongs to the same
                    // repository as the row being imported. A parent RAS box from
                    // another tenant must never be linked (cross-tenant contamination).
                    // resolveBox() echoes back batch_id so we avoid a second query.
                    $parentRepoId = Batch::query()
                        ->withoutGlobalScopes()
                        ->whereKey($res['batch_id'])
                        ->value('repository_id');
                    $parentRepoId = $parentRepoId !== null ? (int) $parentRepoId : null;

                    // Derive the row's effective repository: use the batch already
                    // resolved onto the record (batch_number fills before this column
                    // in the column order), falling back to the stash from resolveRecord().
                    $rowRepoId = null;
                    if ($record->batch_id !== null) {
                        $raw = Batch::query()
                            ->withoutGlobalScopes()
                            ->whereKey($record->batch_id)
                            ->value('repository_id');
                        $rowRepoId = $raw !== null ? (int) $raw : null;
                    }
                    $rowRepoId ??= self::$rowRepositoryStash[spl_object_id($record)] ?? null;

                    // Cross-tenant: both sides resolved non-null and they differ.
                    if ($parentRepoId !== null && $rowRepoId !== null && $parentRepoId !== $rowRepoId) {
                        unset(
                            self::$rowRepositoryStash[spl_object_id($record)],
                            self::$rowCustomFieldStash[spl_object_id($record)],
                        );

                        throw ValidationException::withMessages([
                            'parent_barcode' => __(
                                'The parent box (barcode :barcode) belongs to a different repository and cannot be linked to this box.',
                                ['barcode' => trim($state)],
                            ),
                        ]);
                    }

                    $record->parent_box_id = $res['box_id'];
                    // The parent's TYPE is validated centrally in Box::saving
                    // (RFQ App.1 #3: the parent must be a RAS box). A barcode
                    // that resolves to a non-RAS box is rejected there on save
                    // and surfaces in the failed-rows export, so we do not
                    // duplicate that check here.
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
                        $repoCode = $repoId !== null
                            ? (Repository::query()->withoutGlobalScopes()->whereKey($repoId)->value('code') ?? (string) $repoId)
                            : 'unknown';

                        throw ValidationException::withMessages([
                            'location' => "Unknown location code: '{$state}'. Ensure the location exists in repository '{$repoCode}' before importing.",
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
