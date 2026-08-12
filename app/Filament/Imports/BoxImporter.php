<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\LogsImportRows;
use App\Filament\Imports\Concerns\SkipsExistingRows;
use App\Models\Batch;
use App\Models\Box;
use App\Models\CustomFieldDefinition;
use App\Models\Repository;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Rules\ValidBoxTypeCode;
use App\Support\BulkImport\EntityResolver;
use App\Support\BulkImport\SpreadsheetParsers;
use App\Support\CustomFields\CustomFieldResolver;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
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
 *   - #5 (PERM_OUT requires disinfestation_date + location) was REMOVED per
 *     the client's 2026-08-01 feedback (later than the RFQ, so it governs).
 *
 * The importer accepts a Batch via either its numeric id OR its
 * `batch_number` (the latter is what operators have on hand in the
 * spreadsheet). Parent boxes can be referenced by their barcode.
 */
class BoxImporter extends Importer
{
    use LogsImportRows;
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

        // Resolution trace: log WHAT each weak cell resolved to (batch, location,
        // parent, disinfestation date), not only whether the row failed. When the
        // next client sample has a different shape (UK dates, spaced location
        // codes, parent-by-number vs barcode) this shows how the importer read
        // each cell. `import` channel → storage/logs/import-*.log, debug level.
        Log::channel('import')->debug('BoxImporter: resolved row', [
            'import_id' => $this->import->getKey(),
            'box_number' => $record->box_number,
            'box_type' => $record->box_type,
            'barcode' => $record->barcode,
            'barcode_status' => $record->barcode_status,
            'batch_id' => $record->batch_id,
            'location_id' => $record->location_id,
            'disinfestation_date' => $record->disinfestation_date?->toDateString(),
            'parent_box_id' => $record->parent_box_id,
            'is_legacy' => $record->is_legacy,
            'created' => $record->wasRecentlyCreated,
        ]);
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
                ->withTrashed()
                ->where('barcode', trim((string) $barcode))
                ->first();
            if ($existing !== null) {
                // F023: stash repo id so the static closures can use it.
                self::$rowRepositoryStash[spl_object_id($existing)] = $repoId;

                // Soft-deleted barcode hit: the operator is re-importing a box
                // they deleted. Restore + update it rather than INSERT a new row
                // that would collide with the (soft-deleted) unique barcode
                // (NAF Feedback-1 comment #3 — re-import not working).
                if ($existing->trashed()) {
                    // Defer the un-delete to saveRecord() (resolveRecord runs
                    // before validateData) so a row that fails validation
                    // doesn't leave the box restored — see SeriesImporter.
                    $existing->{$existing->getDeletedAtColumn()} = null;

                    return $existing;
                }

                // RFQ §3.1.3 — honour the "skip duplicates" checkbox for the
                // ONE case we can detect an existing box: a (live) barcode hit.
                $this->skipIfDuplicate($existing);

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
     *  (RFQ #5 PERM_OUT preconditions were dropped — client feedback 2026-08-01.)
     */
    public function afterFill(): void
    {
        /** @var Box $record */
        $record = $this->record;

        if (in_array($record->box_type, ['MAV', 'STVC'], true)) {
            $record->is_legacy = true;
        }

        // Default is_legacy to false when the column is mapped but the cell is
        // blank. The ->boolean() cast turns an empty cell into null and the
        // importer then writes null into the NOT NULL is_legacy column, failing
        // the row with "a required value is missing" — which would reject EVERY
        // non-legacy box whose is_legacy cell is left empty (the normal case).
        if ($record->is_legacy === null) {
            $record->is_legacy = false;
        }

        // Default barcode_status to 'IN' when the cell is blank — the column is
        // NOT NULL DEFAULT 'IN', but the importer writes an explicit null for an
        // empty cell, which fails the row. The "Unknown"/"NULL" catch-all boxes
        // carry no status; treat a box with no recorded status as IN.
        if ($record->barcode_status === null || $record->barcode_status === '') {
            $record->barcode_status = 'IN';
        }

        // Attribute a bulk-imported destroyed state to the operator running the
        // import (the `destroyed` column set destroyed_at/reason but has no
        // instance context for the user).
        if ($record->destroyed_at !== null && $record->destroyed_by_user_id === null) {
            $record->destroyed_by_user_id = $this->import->user->getKey();
        }

        // Client 2026-08-12: a box with no batch (IN_SITU / NRA / MAV / STVC /
        // MUS may legitimately have none) would otherwise be invisible — tenancy
        // is derived from the batch. Stamp its OWN repository_id from the import
        // user's repository (the queue has no active-repository context, so the
        // model's creating() hook cannot resolve it here) so it stays visible.
        if ($record->batch_id === null && $record->repository_id === null) {
            $record->repository_id = self::$rowRepositoryStash[spl_object_id($record)] ?? null;
        }

        // RFQ App.1 #5 PERM_OUT preconditions (disinfestation_date + Location)
        // were REMOVED here per the client's 2026-08-01 feedback (later than the
        // RFQ, so it governs): the legacy perm-out / destroyed boxes being loaded
        // often have neither recorded, and location is now a document-level
        // concept. A PERM_OUT box row imports without them.

        if (in_array($record->box_type, ['IN_SITU', 'NRA'], true)
            && $record->parent_box_id === null
            && $record->provenance_unknown !== true
        ) {
            // Drain the stashes on this failure path too.
            unset(
                self::$rowRepositoryStash[spl_object_id($record)],
                self::$rowCustomFieldStash[spl_object_id($record)],
            );

            // RFQ #3 — IN_SITU / NRA boxes MUST reference a parent RAS box.
            // Reject the row instead of inserting an orphan — UNLESS the row
            // sets provenance_unknown=true, the same escape hatch the create
            // form offers (client 2026-08-10: importing In-Situ / NRA boxes
            // that genuinely have no RAS parent). Mirrors the model saving()
            // guard, which already allows a null parent when provenance_unknown.
            throw ValidationException::withMessages([
                'parent_box_id' => __('IN_SITU and NRA boxes must reference a parent RAS box (via its barcode or an unambiguous RAS box number), or set "Provenance unknown" to Yes when there is genuinely no RAS parent.'),
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
                ->label('Box type')
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
                // Value now optional (was 'required'): the "Unknown"/"NULL"
                // catch-all boxes carry no box type (client feedback 2026-08-01).
                //
                // Client 2026-08-10: box_type is driven by the Box Types LOOKUP
                // (the create form uses BoxType::optionsWith() and the model
                // enforces Lookups::assertActive(BoxType::class, …)), so an
                // operator who adds a new type (e.g. MUS) via the Box Types
                // admin can import it. ValidBoxTypeCode is a ValidationRule
                // OBJECT (not a Closure) on purpose: the ImportWizard preflight
                // preview keeps string / array / ValidationRule rules and drops
                // Closures, so a Closure would validate at import time but stay
                // INVISIBLE in the "N rows would fail validation" preview.
                ->rules(['nullable', new ValidBoxTypeCode]),

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
                // The generated Box template names this column `parent_box_number`
                // (it holds the parent RAS box's BARCODE — the name is legacy).
                // It MUST be in the guess list or the app's own template imports
                // with the parent link silently dropped. The round-trip test
                // (BoxTemplateRoundTripTest) guards this against future drift.
                ->guess([
                    'Parent barcode', 'parent_barcode', 'Parent RAS barcode',
                    'parent_box_number', 'Parent box number', 'Parent Box Number',
                ])
                ->fillRecordUsing(function (Box $record, ?string $state): void {
                    $state = $state !== null ? trim($state) : '';
                    if ($state === '') {
                        return;
                    }

                    // Derive the row's effective repository once: the batch already
                    // resolved onto the record (batch_number fills before this
                    // column), falling back to the stash from resolveRecord().
                    $rowRepoId = null;
                    if ($record->batch_id !== null) {
                        $raw = Batch::query()
                            ->withoutGlobalScopes()
                            ->whereKey($record->batch_id)
                            ->value('repository_id');
                        $rowRepoId = $raw !== null ? (int) $raw : null;
                    }
                    $rowRepoId ??= self::$rowRepositoryStash[spl_object_id($record)] ?? null;

                    // (1) Try the value as a BARCODE — globally unique, so
                    // resolveBox() is intentionally unscoped (BUG-09). We only
                    // ACCEPT it if it identifies a LIVE RAS box: resolveBox()
                    // (withoutGlobalScopes) can return a non-RAS or soft-deleted
                    // box, and a numeric barcode could collide with a valid RAS
                    // box number — accepting it blindly would link a bad parent and
                    // skip the number fallback below. Cross-tenant correctness is
                    // validated too.
                    $res = EntityResolver::resolveBox($state);
                    if ($res !== null) {
                        $parentBox = Box::query()
                            ->withoutGlobalScopes()
                            ->whereKey($res['box_id'])
                            ->first(['id', 'box_type', 'deleted_at']);

                        if ($parentBox !== null && $parentBox->box_type === 'RAS' && $parentBox->deleted_at === null) {
                            $parentRepoId = Batch::query()
                                ->withoutGlobalScopes()
                                ->whereKey($res['batch_id'])
                                ->value('repository_id');
                            $parentRepoId = $parentRepoId !== null ? (int) $parentRepoId : null;

                            if ($parentRepoId !== null && $rowRepoId !== null && $parentRepoId !== $rowRepoId) {
                                unset(
                                    self::$rowRepositoryStash[spl_object_id($record)],
                                    self::$rowCustomFieldStash[spl_object_id($record)],
                                );

                                throw ValidationException::withMessages([
                                    'parent_barcode' => __(
                                        'The parent box (barcode :barcode) belongs to a different repository and cannot be linked to this box.',
                                        ['barcode' => $state],
                                    ),
                                ]);
                            }

                            $record->parent_box_id = $res['box_id'];

                            return;
                        }
                        // Not a live RAS box → fall through to the number resolution
                        // (the value may be a RAS box NUMBER, not a barcode).
                    }

                    // (2) Not a (RAS) barcode → try the value as a parent RAS box's
                    // BOX NUMBER within this row's repository. The client's sheet
                    // references the parent this way (the `parent_box_number`
                    // column holds "1", the RAS box's number). Scoped to RAS boxes
                    // in the repository and demanding EXACTLY ONE match so a wrong
                    // parent can never be linked (RFQ A1.3 provenance).
                    $byNumber = EntityResolver::resolveRasParentByBoxNumber($state, $rowRepoId);

                    if (is_array($byNumber) && isset($byNumber['ambiguous'])) {
                        unset(
                            self::$rowRepositoryStash[spl_object_id($record)],
                            self::$rowCustomFieldStash[spl_object_id($record)],
                        );

                        throw ValidationException::withMessages([
                            'parent_barcode' => __(
                                'More than one RAS box has number :number in this repository, so the parent is ambiguous. Reference the parent by its unique barcode instead.',
                                ['number' => $state],
                            ),
                        ]);
                    }

                    if (is_array($byNumber)) {
                        // Only the {box_id, batch_id} shape can reach here — the
                        // ambiguous case threw above and a no-match is null. Same
                        // repository by construction (scoped in the resolver).
                        $record->parent_box_id = $byNumber['box_id'];

                        return;
                    }

                    // Neither a known barcode nor a resolvable RAS box number —
                    // leave parent_box_id null; afterFill() rejects IN_SITU/NRA
                    // rows that end up without a parent, with a clear message.
                    // The parent's TYPE (must be RAS) is validated centrally in
                    // Box::saving (RFQ App.1 #3).
                }),

            ImportColumn::make('barcode')
                ->label('Barcode')
                ->guess(['Barcode', 'barcode', 'Barcode (IN)'])
                // RAS boxes require a barcode (Feedback1 C2.1) — enforced here at
                // the importer input boundary (and in the Filament form), not in
                // the model save, so legitimate bulk/seed/provisional creation of
                // a not-yet-barcoded RAS record isn't blocked. Legacy MAV/STVC and
                // provenance IN_SITU/NRA rows may import without a barcode.
                // Barcode is now optional even for RAS boxes (was
                // required_if:box_type,RAS): some legacy boxes lost their barcode
                // trail or never had one (client feedback 2026-08-01).
                ->rules(['nullable', 'string', 'max:64']),

            ImportColumn::make('barcode_status')
                ->label('Barcode status (IN / OUT / PERM_OUT)')
                ->guess(['Barcode status', 'Status', 'barcode_status'])
                ->castStateUsing(function (?string $state): ?string {
                    if ($state === null || trim($state) === '') {
                        // Blank → null; afterFill() defaults it to 'IN'.
                        return null;
                    }
                    $s = strtoupper(trim($state));
                    // Normalise common aliases from the legacy sheets.
                    $s = match ($s) {
                        'PERM OUT', 'PERMOUT', 'PERM-OUT' => 'PERM_OUT',
                        default => $s,
                    };

                    // Keep a non-matching value AS-IS so the in: rule rejects it
                    // with a clear message, rather than silently coercing it to
                    // null (which the old code did, surfacing a misleading
                    // "required value missing" error).
                    return $s;
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

            // Client 2026-08-10 — In-Situ / NRA boxes that genuinely have no RAS
            // parent. Yes here lets the row skip the parent-RAS requirement
            // (see afterFill()), the same escape hatch the create form exposes.
            ImportColumn::make('provenance_unknown')
                ->label('Provenance unknown (no RAS parent)')
                ->guess(['Provenance unknown', 'provenance_unknown', 'Provenance Unknown', 'No RAS parent', 'No parent'])
                ->boolean()
                ->rules(['nullable', 'boolean']),

            ImportColumn::make('notes')
                ->label('Notes')
                ->guess(['Notes', 'notes', 'Note'])
                ->rules(['nullable', 'string']),

            // Client feedback 2026-08-04: a Tracking Note distinct from the
            // general note (movement / stock-take context).
            ImportColumn::make('tracking_note')
                ->label('Tracking Note')
                // The client's real box source (2026-08-01_NAF_Boxes_1_Blue)
                // uses a plain 'Tracking' header alongside 'Note', so guess it too.
                ->guess(['Tracking Note', 'tracking_note', 'Tracking note', 'Tracking'])
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

            // Client feedback 2026-08-01: 905 boxes were already destroyed during
            // the first parent-box import — import the destroyed state in bulk
            // instead of destroying one-by-one. A date cell → destroyed on that
            // date; "Yes"/truthy → destroyed now (the legacy sheet has no per-box
            // destroy date). destroyed_by is stamped to the importing user in
            // afterFill(). Anything unrecognised leaves the box not-destroyed.
            ImportColumn::make('destroyed')
                ->label('Destroyed (Yes / a date / blank)')
                ->guess(['Destroyed', 'destroyed', 'Box Destroyed', 'Destroyed?'])
                ->fillRecordUsing(function (Box $record, ?string $state): void {
                    if ($state === null || trim($state) === '') {
                        return;
                    }
                    $s = trim($state);
                    // Truthy flags FIRST — parseDate('1') would read "1" as the
                    // Excel serial 1900-01-01, not a truthy "yes". Only fall back
                    // to date parsing for a value that isn't a known flag.
                    if (in_array(mb_strtolower($s), ['yes', 'y', '1', 'true', 'x', 'destroyed'], true)) {
                        $record->destroyed_at = now();
                    } elseif (($date = SpreadsheetParsers::parseDate($s)) !== null) {
                        $record->destroyed_at = Carbon::parse($date);
                    } else {
                        return;
                    }
                    if ($record->destroyed_reason === null || trim((string) $record->destroyed_reason) === '') {
                        $record->destroyed_reason = 'Imported as already destroyed (legacy)';
                    }
                }),

            // The physical container type (RAS Box / Big Brown Box / Small Brown
            // Box / Standard Blue Box / …), distinct from the archival box_type.
            // Client's source sheet carries it in a "Current Box" column.
            ImportColumn::make('current_box_type')
                ->label('Current box type (RAS Box / Big Brown Box / Small Brown Box / …)')
                ->guess(['Current Box Type', 'current_box_type', 'Current Box', 'Current box'])
                ->rules(['nullable', 'string', 'max:64']),
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
