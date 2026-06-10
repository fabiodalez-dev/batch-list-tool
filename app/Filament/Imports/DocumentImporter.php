<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\SkipsExistingRows;
use App\Models\Authority;
use App\Models\Box;
use App\Models\CustomFieldDefinition;
use App\Models\Document;
use App\Models\Scopes\RepositoryScope;
use App\Support\BulkImport\EntityResolver;
use App\Support\BulkImport\SpreadsheetParsers;
use App\Support\CustomFields\CustomFieldResolver;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\SchemalessAttributes\SchemalessAttributes;

/**
 * RFQ §3.1.3 — Bulk import for {@see Document}: the showcase importer.
 *
 * This is where the headline "FK resolution by name" feature lives. The
 * legacy POC spreadsheet `Batch_List_Sample.xlsx` has ~47 columns; this
 * importer maps every column either to a normalised destination (via FK
 * resolution) OR to a denormalised legacy POC column kept for read-parity.
 *
 * Key behaviours:
 *
 *   - The "Identifier" column (the R-code, col 33 in the sample) is treated
 *     as a *resolver* — it identifies the Authority that owns the document.
 *     The DOCUMENT's own identifier is built from `Catalogue Identifier`
 *     where present, falling back to the R-code, falling back to an
 *     `AUTO-<row>` synthetic id.
 *
 *   - The "Creator" column (col 36) is free-text catalogator data — it is
 *     NOT a formal FK. We persist it verbatim in `extra.legacy_creator_text`
 *     so the operator can review it later, AND we try a best-effort match
 *     against `authorities.surname`. Ambiguous matches (F-009) are
 *     persisted as `extra.ambiguous_candidates` and the row goes through
 *     WITHOUT any pivot row — operators must resolve manually.
 *
 *   - The "Series" column (col 40) typically arrives as
 *     "REG: Registers Private Practice"; we split on ":" and resolve by
 *     code, falling back to title exact match.
 *
 *   - RFQ App.1 #1 (forbidden batches 34/36; batch 33 is reserved for old
 *     MAV boxes) and #5 (PERM_OUT requires disinfestation_date) are enforced
 *     by `afterFill` validation.
 *
 * Pivot writes (document_authority) happen in `afterSave` because they
 * need the freshly-saved `id`. Resolved authority ids are stashed on the
 * record via `_resolvedAuthorityId` (an unsaved attribute used as a
 * stash slot — Eloquent ignores unknown attributes that aren't in
 * $fillable on save).
 */
class DocumentImporter extends Importer
{
    use SkipsExistingRows;

    /**
     * Multi-value cell delimiter per RFQ Appendix-2 §xi. Hard-coded by the
     * RFQ — do NOT swap for `,` or `|` without a contract amendment, since
     * authority names legitimately contain commas ("Buttigieg, John").
     */
    public const SEMICOLON_DELIMITER = ';';

    protected static ?string $model = Document::class;

    /**
     * Per-row stash for authority ids resolved during fill (column closures
     * see `$this->record` but the pivot insert needs the model id, which
     * only exists after save). Reset in {@see beforeFill} and consumed in
     * {@see afterSave}. Static + indexed by spl_object_id so columns can be
     * declared statically while still scoping the stash to the current row.
     *
     * Holding it here keeps `$record` itself free of stray attributes —
     * Eloquent would otherwise try to persist an unknown property as a
     * column on save and break the insert.
     *
     * @var array<int, array<int, int>>
     */
    protected static array $rowAuthorityStash = [];

    /**
     * Per-row stash for the box barcode supplied via the `current_box_barcode`
     * column. Resolved (and consistency-checked against the document's batch)
     * centrally in {@see afterFill}, not in the column closure, so it can be
     * cross-validated with the batch the document resolved separately. Keyed
     * by `spl_object_id` of the record, like {@see $rowAuthorityStash}.
     *
     * @var array<int, string>
     */
    protected static array $rowBoxBarcodeStash = [];

    /**
     * Per-row stash for the box-by-number supplied via `current_box_number`.
     * Same lifecycle/keying as {@see $rowBoxBarcodeStash}.
     *
     * @var array<int, string>
     */
    protected static array $rowBoxNumberStash = [];

    /**
     * Per-row stash for the box barcode status (B4) derived from the legacy
     * `status_*` columns. Applied to the BOX (authoritative since Task 7) in
     * {@see afterSave} once the document carries a `current_box_id`; the Task-7
     * mirror then propagates the value down onto the document. Keyed by
     * `spl_object_id` of the record.
     *
     * @var array<int, string>
     */
    protected static array $rowBoxStatusStash = [];

    /**
     * Per-row stash for custom-field key→value data extracted from columns
     * whose header matches a custom-field definition label or key. Persisted
     * in {@see persistRowSideEffects()} via $record->setCustomFieldData().
     * Keyed by spl_object_id of the record, same pattern as the other stashes.
     *
     * @var array<int, array<string, string>>
     */
    protected static array $rowCustomFieldStash = [];

    /**
     * Per-row transactional safety (review I2 / Fix 5).
     *
     * Filament's import job wraps an entire CHUNK in one DB::transaction and
     * SWALLOWS a failing row (it logs the failure but does NOT roll back). The
     * base flow runs: beforeSave → saveRecord() [persists the row] → afterSave.
     * So a box-status / pivot write that throws in afterSave() would otherwise
     * leave a half-saved Document committed with the chunk while the row is
     * reported failed.
     *
     * We close that gap with an explicit per-row SAVEPOINT: open a nested
     * transaction in {@see beforeSave()} (just before the record is persisted)
     * and release it in {@see afterSave()} once the side effects have
     * succeeded. Any throw in between rolls back to the savepoint, undoing the
     * document save — the row becomes atomic: it persists fully (document +
     * box status + pivots) or not at all.
     */
    protected bool $rowSavepointOpen = false;

    /**
     * BUG-05: Repository id resolved once per import from the authenticated
     * user's default_repository_id. Set in resolveRecord() and reused by the
     * batch_number fillRecordUsing closure so batch resolution is always
     * tenant-scoped to the correct repository.
     *
     * Stored in a static array keyed by spl_object_id($record) so that the
     * static getStaticColumns() closures (which cannot capture $this) can
     * still access the per-row resolved value. Cleared in afterSave/afterFill.
     *
     * @var array<int, int|null>
     */
    protected static array $rowRepositoryStash = [];

    /**
     * @return array<ImportColumn>
     */
    public static function getColumns(): array
    {
        $staticColumns = static::getStaticColumns();
        $customColumns = static::getCustomFieldColumns();

        return array_merge($staticColumns, $customColumns);
    }

    /**
     * Idempotent matching by (identifier, repository_id) — repository
     * tenancy is inferred from the BelongsToRepository hook on save.
     *
     * BUG-05: Resolves and caches the acting user's repository id once per
     * row so the batch_number closure can pass a tenant-scoped id to
     * EntityResolver::resolveBatch() instead of leaving it null.
     */
    public function resolveRecord(): ?Document
    {
        $identifier = $this->data['identifier'] ?? null;

        $user = auth()->user();
        $repoId = $user?->default_repository_id;

        if ($identifier === null || trim((string) $identifier) === '') {
            $record = new Document;
            // BUG-05: stash repo id so the static batch_number closure can use it.
            self::$rowRepositoryStash[spl_object_id($record)] = $repoId !== null ? (int) $repoId : null;

            return $record;
        }

        $q = Document::query()
            ->withoutGlobalScope(RepositoryScope::class)
            ->where('identifier', trim((string) $identifier));
        if ($repoId !== null) {
            $q->where('repository_id', $repoId);
        }

        $record = $q->first() ?? new Document;
        // BUG-05: stash repo id so the static batch_number closure can use it.
        self::$rowRepositoryStash[spl_object_id($record)] = $repoId !== null ? (int) $repoId : null;

        $this->skipIfDuplicate($record);

        return $record;
    }

    /**
     * Post-fill validations and derived fields:
     *
     *  - Parse Year range from `dates` text into dates_year_start/end.
     *  - PERM_OUT (legacy status_1 in {OUT, PERM_OUT, …}) requires
     *    disinfestation_date — RFQ App.1 #5.
     *  - When the operator supplies neither Identifier nor Catalogue
     *    Identifier we synthesise an AUTO id so the row can still be
     *    inserted (the spreadsheet column is often blank for new
     *    documents awaiting cataloguing).
     */
    public function afterFill(): void
    {
        /** @var Document $record */
        $record = $this->record;

        // Year range derived from free-text "Dates" column.
        if (! empty($record->dates) && $record->dates_year_start === null) {
            [$y0, $y1] = SpreadsheetParsers::parseYearRange((string) $record->dates);
            if ($y0 !== null) {
                $record->dates_year_start = $y0;
            }
            if ($y1 !== null) {
                $record->dates_year_end = $y1;
            }
        }

        // Fall back the document identifier to catalogue_identifier when
        // operator left it blank — the schema indexes `identifier`, so
        // every row must have one.
        if ((empty($record->identifier) || $record->identifier === 'AUTO-')
            && ! empty($record->catalogue_identifier)
        ) {
            $record->identifier = $record->catalogue_identifier;
        }

        // Task 8 (B5) — resolve the current box now that the document's batch
        // is known, then validate batch/box consistency BEFORE save.
        $this->resolveCurrentBox($record);

        // Task 8 (B4) — reconcile the legacy `status_*` columns to a single
        // authoritative barcode status. The legacy spreadsheet encodes status
        // in several columns (status_1..4, *_alt); we collapse them: PERM_OUT
        // wins (it is the strongest custody state), then OUT, then IN.
        $resolvedStatus = $this->resolveLegacyBarcodeStatus($record);

        // RFQ App.1 #5 — PERM_OUT requires disinfestation_date.
        if ($resolvedStatus === 'PERM_OUT' && $record->disinfestation_date === null) {
            throw ValidationException::withMessages([
                'disinfestation_date' => __(
                    'Documents with a PERM_OUT status must carry a disinfestation_date (RFQ App.1 #5).'
                ),
            ]);
        }

        // The box is the authoritative source of truth for barcode status
        // (Task 7). When the document HAS a box, stash the resolved status and
        // apply it to the BOX in afterSave (the document must already point at
        // the box so the Task-7 mirror can propagate the value back down). We
        // deliberately do NOT write documents.barcode_status here in that case —
        // the box mirror owns it.
        if ($resolvedStatus !== null) {
            if ($record->current_box_id !== null) {
                self::$rowBoxStatusStash[spl_object_id($record)] = $resolvedStatus;
            } else {
                // Fallback (review F3): no box to be authoritative about, so
                // write the resolved status directly onto the document column
                // rather than silently dropping the operator's data. A1.2 still
                // holds: PERM_OUT without a disinfestation_date already failed
                // the row above, and the document-level saving guard re-checks
                // it on persist — so an invalid state can never be stored.
                $record->barcode_status = $resolvedStatus;
            }
        }
    }

    public function beforeSave(): void
    {
        // Defensive: if a PRIOR row's saveRecord() threw, afterSave() never ran
        // and its savepoint could still be open (Filament's chunk job swallows
        // the row error and moves on). Close it before opening a fresh one so
        // the transaction nesting level can never drift across rows.
        if ($this->rowSavepointOpen) {
            DB::rollBack();
            $this->rowSavepointOpen = false;
        }

        // The box-status precondition (PERM_OUT ⇒ disinfestation_date, RFQ
        // App.1 #5) is already validated in afterFill() BEFORE we reach here,
        // so a precondition failure never even attempts a save. This savepoint
        // guards the residual risk: the box save() in afterSave() throwing
        // (e.g. a model-level guard) AFTER the document row is already written.
        DB::beginTransaction();
        $this->rowSavepointOpen = true;
    }

    /**
     * Attach resolved authorities AFTER the Document row has its id, then
     * commit (or roll back) the per-row savepoint opened in {@see beforeSave()}.
     * The pivot + box-status writes live in {@see persistRowSideEffects()} so
     * any failure there rolls back the just-saved document atomically.
     */
    public function afterSave(): void
    {
        try {
            $this->persistRowSideEffects();
        } catch (\Throwable $e) {
            // Undo the document save (and anything written since beforeSave)
            // so a failed box-status / pivot write never leaves a half-saved
            // row. Re-throw so Filament's job logs this as a FAILED row.
            if ($this->rowSavepointOpen) {
                DB::rollBack();
                $this->rowSavepointOpen = false;
            }

            throw $e;
        }

        if ($this->rowSavepointOpen) {
            DB::commit();
            $this->rowSavepointOpen = false;
        }
    }

    /**
     * Append an Authority id to the per-row stash. Keyed by the record's
     * Object id so a single Document row accumulates multiple authorities
     * (legacy POC allowed it) while different rows stay isolated.
     */
    public static function stashAuthority(Document $record, int $authorityId): void
    {
        $key = spl_object_id($record);
        self::$rowAuthorityStash[$key][] = $authorityId;
    }

    /**
     * Split a {@see SEMICOLON_DELIMITER}-delimited spreadsheet cell into a
     * clean list of trimmed, non-empty pieces. RFQ Appendix-2 §xi — the
     * legacy Excel encodes multiple creators in a SINGLE column delimited
     * by `;`:
     *
     *   "520; 178"                       → ["520", "178"]
     *   "Calcedonio Gatt; Angelo Cauchi" → ["Calcedonio Gatt", "Angelo Cauchi"]
     *   "520; ; 178"                     → ["520", "178"]  (empty piece dropped)
     *   "  R520  "                       → ["R520"]
     *   ""                               → []
     *
     * Semantics worth noting:
     *
     * - Empty pieces (e.g. trailing `;`, double `;;`, whitespace-only) are
     *   SILENTLY SKIPPED. This is deliberate: legacy spreadsheets routinely
     *   carry sloppy editor whitespace and we do not want to fail an entire
     *   row because of a stray separator.
     * - The delimiter is fixed at {@see SEMICOLON_DELIMITER}. A comma is
     *   NOT a fallback — names in the legacy data legitimately contain
     *   commas (e.g. "Buttigieg, John") and treating `,` as a separator
     *   would corrupt every such row.
     * - Whitespace around each piece is trimmed; internal whitespace is
     *   preserved verbatim.
     *
     * @return array<int, string>
     */
    public static function splitSemicolonList(string $raw): array
    {
        if (! str_contains($raw, self::SEMICOLON_DELIMITER)) {
            $only = trim($raw);

            return $only === '' ? [] : [$only];
        }

        $pieces = array_map('trim', explode(self::SEMICOLON_DELIMITER, $raw));

        return array_values(array_filter(
            $pieces,
            static fn (string $p): bool => $p !== '',
        ));
    }

    /**
     * Merge an array of keys into `document.extra` without clobbering
     * existing entries. The cast returns a SchemalessAttributes object on
     * read (always truthy, never `null`), so the more obvious
     * `$record->extra = array_merge($record->extra ?? [], $merge)` does
     * not behave correctly. We pull `->toArray()` to get the canonical
     * snapshot, merge, and re-assign — which routes through the cast's
     * `set()` and stores the JSON-encoded result on the model.
     *
     * @param array<string, mixed> $merge
     */
    public static function mergeExtra(Document $record, array $merge): void
    {
        $current = $record->extra instanceof SchemalessAttributes
            ? $record->extra->toArray()
            : (is_array($record->extra) ? $record->extra : []);
        $record->extra = array_merge($current, $merge);
    }

    /**
     * Test helper — peek into the stash from a unit test without going
     * through the full import job. Returns the pending authority ids for
     * the given Document (or an empty array when none are stashed).
     *
     * @return array<int, int>
     */
    public static function peekStashedAuthorities(Document $record): array
    {
        return self::$rowAuthorityStash[spl_object_id($record)] ?? [];
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Documents import completed: '
            . number_format($import->successful_rows) . ' rows processed';
        if (($failed = $import->getFailedRowsCount()) > 0) {
            $body .= ', ' . number_format($failed) . ' failed';
        }

        return $body;
    }

    /**
     * Build ImportColumn entries for every active custom-field definition in the
     * current user's default repository (document entity type). The column is
     * guessed by both the field key and the field label (case-insensitive).
     * Values are stashed in {@see $rowCustomFieldStash} and written via
     * $record->setCustomFieldData() in persistRowSideEffects().
     *
     * GROUP C+D fix: an empty mapped cell (operator mapped the column but the
     * cell is blank) is stored as null in the stash so persistRowSideEffects()
     * passes it through to setCustomFieldData(null for that key), which then
     * deletes the existing value for that field on update. Unmapped/absent
     * columns are never added to the stash, so they are untouched when
     * setCustomFieldData is called with replaceMissing=false (merge semantics).
     *
     * @return array<ImportColumn>
     */
    protected static function getCustomFieldColumns(): array
    {
        $defs = static::resolveCustomFieldDefinitions();
        if ($defs->isEmpty()) {
            return [];
        }

        $columns = [];
        foreach ($defs as $def) {
            $columns[] = ImportColumn::make('custom_field_' . $def->key)
                ->label($def->label . ' (custom field)')
                ->guess([$def->label, $def->key, 'cf_' . $def->key])
                ->rules(['nullable', 'string'])
                ->fillRecordUsing(static function (Document $record, ?string $state) use ($def): void {
                    $key = spl_object_id($record);
                    // Always stash the key so persistRowSideEffects() knows this
                    // column was mapped. A null/empty value clears the existing
                    // stored value on update (merge-mode delete). An absent column
                    // (never reaches this closure) is left untouched.
                    static::$rowCustomFieldStash[$key][$def->key] = ($state !== null && trim($state) !== '')
                        ? trim($state)
                        : null;
                });
        }

        return $columns;
    }

    /**
     * Resolve active CustomFieldDefinition records for the active repository
     * (document entity type). Delegates to CustomFieldResolver so the
     * active-repo logic (ActiveRepository topbar switcher → user default →
     * null) is centralised.
     *
     * The resolver's per-request memo replaces the local
     * $customFieldDefinitionsCache: GROUP C fix (cross-tenant isolation) is
     * now owned by the resolver's "{repoId}:{entityType}" cache key.
     *
     * @return EloquentCollection<int, CustomFieldDefinition>
     */
    protected static function resolveCustomFieldDefinitions(): EloquentCollection
    {
        return CustomFieldResolver::definitionsFor('document');
    }

    /**
     * The fixed (non-EAV) import columns. Extracted so getColumns() can merge
     * them with the dynamic custom-field columns without duplication.
     *
     * @return array<ImportColumn>
     */
    protected static function getStaticColumns(): array
    {
        return [
            // ── Identification ──────────────────────────────────────────
            // F-004: 'Identifier' is intentionally NOT in the guess list here.
            // In the legacy Batch_List_Sample.xlsx the column named 'Identifier'
            // (col 33) holds the Authority R-code, not the document's own working
            // identifier. Adding 'Identifier' to this column's guess list would
            // cause every legacy import to auto-map the authority's R-code to the
            // Document.identifier field and leave the authority_identifier column
            // unmapped — all documents silently unlinked from their authorities.
            // The document's own identifier should be mapped from 'Catalogue
            // Identifier' or 'Document Identifier' columns in the source sheet.
            ImportColumn::make('identifier')
                ->label('Document identifier (R-code or composite)')
                ->requiredMappingForNewRecordsOnly()
                ->guess(['identifier', 'Document Identifier', 'Doc ID'])
                ->rules(['required', 'string', 'max:64']),

            ImportColumn::make('catalogue_identifier')
                ->label('Catalogue Identifier')
                ->guess(['Catalogue Identifier', 'catalogue_identifier', 'Catalogue ID'])
                ->rules(['nullable', 'string', 'max:191']),

            ImportColumn::make('document_type')
                ->label('Document Type')
                ->guess(['Document Type', 'document_type', 'Type'])
                ->rules(['nullable', 'string', 'max:64']),

            ImportColumn::make('practice')
                ->label('Practice')
                ->guess(['Practice', 'practice'])
                ->rules(['nullable', 'string', 'max:100']),

            ImportColumn::make('volume_number')
                ->label('Volume')
                ->guess(['Volume', 'volume', 'Volume No', 'Volume Number', 'Volume label', 'volume_label', 'volume_number'])
                // F-005: normalise Excel float artefacts ('2.0' → '2') while
                // keeping genuinely non-numeric values ('180A/181', '18+20') verbatim.
                ->castStateUsing(function (mixed $state): ?string {
                    if ($state === null || trim((string) $state) === '') {
                        return null;
                    }
                    $str = trim((string) $state);
                    if (is_numeric($str)) {
                        return (string) (int) $str;
                    }

                    return $str;
                })
                ->rules(['nullable', 'string', 'max:64']),

            // ── Dates ───────────────────────────────────────────────────
            ImportColumn::make('dates')
                ->label('Dates (free-text)')
                ->guess(['Dates', 'dates', 'Date range'])
                ->rules(['nullable', 'string', 'max:191']),

            // Year range derived from the free-text "Dates" column. We do
            // this in `afterFill` because the parsed values flow to two
            // different real columns (dates_year_start/end).
            ImportColumn::make('deeds')
                ->label('Deeds')
                ->guess(['Deeds', 'deeds'])
                ->rules(['nullable', 'string']),

            // Wave F — DECISION F2: new document fields.
            ImportColumn::make('number_of_acts')
                ->label('No of Acts')
                ->guess(['No of Acts', 'No. of Acts', 'Number of Acts', 'Acts', 'no_of_acts'])
                ->rules(['nullable', 'string', 'max:64']),

            ImportColumn::make('pages_folios')
                ->label('Pages/Folios')
                ->guess(['Pages/Folios', 'Pages / Folios', 'Pages', 'Folios', 'pages_folios'])
                ->rules(['nullable', 'string', 'max:128']),

            // ── Series — FK by code/title ──────────────────────────────
            ImportColumn::make('series')
                ->label('Series (code or "CODE: Title")')
                ->requiredMappingForNewRecordsOnly()
                ->guess(['Series', 'series', 'series_id'])
                ->fillRecordUsing(function (Document $record, ?string $state): void {
                    if ($state === null || trim($state) === '') {
                        return;
                    }
                    $res = EntityResolver::resolveSeries($state);
                    if ($res === null) {
                        throw ValidationException::withMessages([
                            'series' => __("Series ':code' not found", ['code' => trim($state)]),
                        ]);
                    }
                    $record->series_id = $res['series_id'];
                }),

            // ── Authority — FK by identifier OR free-text Creator ──────
            // Two columns intentionally: the "Identifier" column (col 33 in
            // Batch_List_Sample) is a formal R-code → exact FK match. The
            // separate "Creator" column (col 36) is free-text catalogator
            // data that gets fuzzy-matched.
            //
            // RFQ Appendix-2 §xi — both columns may carry MULTIPLE values in
            // a single spreadsheet cell, delimited by ";". Real examples:
            //   Identifier:  "520; 178"   → two notaries (R520 + R178)
            //   Creator:     "Calcedonio Gatt; Angelo Cauchi"
            // We split on ";", trim each piece, and resolve each piece
            // independently. The FIRST non-empty piece becomes is_primary;
            // every subsequent successful match attaches as a co-creator.
            // Empty / whitespace-only pieces are skipped silently.
            ImportColumn::make('authority_identifier')
                ->label('Authority identifier (R-code, optionally ";"-separated)')
                // F-004: 'Identifier' is the column header in legacy Batch_List_Sample.xlsx
                // for the Authority R-code (col 33). Now that the Document.identifier
                // column no longer claims this guess, mapping it here gives operators
                // the correct auto-mapping: 'Identifier' → authority_identifier.
                // 'Creator code' covers other legacy naming conventions.
                ->guess(['Identifier', 'Authority identifier', 'Authority code', 'Creator code'])
                ->fillRecordUsing(function (Document $record, ?string $state): void {
                    if ($state === null || trim($state) === '') {
                        return;
                    }
                    $pieces = self::splitSemicolonList($state);
                    if ($pieces === []) {
                        return;
                    }
                    foreach ($pieces as $piece) {
                        $res = EntityResolver::resolveAuthority($piece);
                        if ($res === null) {
                            continue; // soft miss — try fallback by surname
                        }
                        if (isset($res['ambiguous_count'])) {
                            // Defensive: identifier should be unique → no
                            // ambiguity is possible. Treat as soft miss and log.
                            self::mergeExtra($record, [
                                'creator_match_log' => 'ambiguous_' . $res['ambiguous_count'] . '_candidates',
                            ]);

                            continue;
                        }
                        // Stash on the importer (NOT the record — Eloquent would
                        // try to persist an unknown property as a column).
                        // afterSave() assigns is_primary=true to the first
                        // stashed id and is_primary=false to all subsequent.
                        self::stashAuthority($record, (int) $res['authority_id']);
                    }
                }),

            ImportColumn::make('creator_legacy_text')
                ->label('Creator (free-text, optionally ";"-separated)')
                ->guess(['Creator', 'creator', 'Creator name'])
                ->fillRecordUsing(function (Document $record, ?string $state): void {
                    if ($state === null || trim($state) === '') {
                        return;
                    }
                    $raw = trim($state);
                    $merge = ['legacy_creator_text' => $raw];

                    // Split on ";" — RFQ Appendix-2 §xi: the legacy Creator
                    // column can encode multiple notaries in one cell, e.g.
                    // "Calcedonio Gatt; Angelo Cauchi".
                    $pieces = self::splitSemicolonList($raw);
                    if ($pieces === []) {
                        self::mergeExtra($record, $merge);

                        return;
                    }

                    $logs = [];
                    $ambiguous = [];
                    $matchedAny = false;

                    foreach ($pieces as $piece) {
                        // Best-effort name resolution (F-009 / F-001 aware).
                        $res = EntityResolver::resolveAuthority(null, null, $piece);
                        if ($res === null) {
                            $logs[] = 'unresolved:' . $piece;
                        } elseif (isset($res['ambiguous_count'])) {
                            $logs[] = 'ambiguous_' . $res['ambiguous_count']
                                . '_candidates:' . $piece;
                            foreach ($res['candidates'] as $c) {
                                $ambiguous[] = (int) $c;
                            }
                        } else {
                            $logs[] = 'matched:' . $res['method'] . ':' . $piece;
                            self::stashAuthority($record, (int) $res['authority_id']);
                            $matchedAny = true;
                        }
                    }

                    // Preserve the single-piece log shape ("unresolved",
                    // "ambiguous_N_candidates", "matched:<method>") when only
                    // one creator was in the cell — keeps assertions in the
                    // existing test suite stable.
                    if (count($pieces) === 1) {
                        $only = $logs[0];
                        if (str_starts_with($only, 'unresolved')) {
                            $merge['creator_match_log'] = 'unresolved';
                        } elseif (str_starts_with($only, 'ambiguous_')) {
                            // strip the trailing ":<piece>" we added above
                            $colon = strpos($only, ':');
                            $merge['creator_match_log'] = $colon !== false
                                ? substr($only, 0, $colon)
                                : $only;
                            if ($ambiguous !== []) {
                                $merge['ambiguous_candidates']
                                    = array_values(array_unique($ambiguous));
                            }
                        } else {
                            // matched:<method>:<piece> → matched:<method>
                            $parts = explode(':', $only, 3);
                            $merge['creator_match_log'] = isset($parts[1])
                                ? $parts[0] . ':' . $parts[1]
                                : $only;
                        }
                    } else {
                        // Multi-creator cell: aggregate the per-piece log
                        // entries; keep the array of ambiguous candidate ids
                        // so an operator can still resolve manually.
                        $merge['creator_match_log'] = $matchedAny
                            ? 'matched_multi:' . count($pieces)
                            : 'unresolved_multi:' . count($pieces);
                        $merge['creator_match_details'] = $logs;
                        if ($ambiguous !== []) {
                            $merge['ambiguous_candidates']
                                = array_values(array_unique($ambiguous));
                        }
                    }
                    self::mergeExtra($record, $merge);
                }),

            // ── Disinfestation ─────────────────────────────────────────
            ImportColumn::make('disinfestation_date')
                ->label('Disinfestation Date')
                ->guess(['Disinfestation Date', 'disinfestation_date'])
                ->castStateUsing(fn (mixed $state) => SpreadsheetParsers::parseDate($state))
                ->rules(['nullable', 'date']),

            // ── Locations ──────────────────────────────────────────────
            ImportColumn::make('nra_location')
                ->label('NRA Location')
                ->guess(['NRA Location', 'nra_location'])
                ->rules(['nullable', 'string']),

            ImportColumn::make('museum_location')
                ->label('Museum Location')
                ->guess(['Museum Location', 'museum_location'])
                ->rules(['nullable', 'string']),

            // ── Current location (legacy POC text + new FK) ────────────
            ImportColumn::make('batch_number')
                ->label('Batch number (current — RAS Batch 1)')
                ->integer()
                ->guess(['RAS Batch 1', 'Batch number', 'batch_number', 'Batch'])
                ->fillRecordUsing(function (Document $record, mixed $state): void {
                    $n = SpreadsheetParsers::parseInt($state);
                    if ($n === null) {
                        return;
                    }
                    // Task 8 (B5) — dedup-OR-CREATE: a document referencing a
                    // batch that does not yet exist stands the batch up in the
                    // same run (forbidden numbers are still rejected below).
                    // BUG-05: read the per-row repository id from the static stash
                    // (set in resolveRecord()) so the lookup is tenant-scoped.
                    $repoIdForBatch = self::$rowRepositoryStash[spl_object_id($record)] ?? null;
                    $res = EntityResolver::resolveBatch($n, $repoIdForBatch, create: true);
                    if ($res === null) {
                        return; // unknown batch — leave FK null
                    }
                    if (isset($res['forbidden'])) {
                        // RFQ App.1 #1 — operators are not allowed to import
                        // documents into reserved batches.
                        throw ValidationException::withMessages([
                            'batch_number' => __(
                                'Batch :n is reserved (RFQ App.1 #1) and cannot be assigned',
                                ['n' => (int) $res['forbidden']],
                            ),
                        ]);
                    }
                    $record->batch_id = $res['batch_id'];
                    // Also keep the legacy denormalised text column populated.
                    $record->ras_batch_1 = (string) $n;
                }),

            ImportColumn::make('current_box_number')
                ->label('Current box number (RAS Box 1)')
                ->guess(['RAS Box 1', 'Current Box', 'current_box', 'Box'])
                ->fillRecordUsing(function (Document $record, ?string $state): void {
                    if ($state === null || trim($state) === '') {
                        return;
                    }
                    // BUG-06: normalise Excel float artefacts ('1.0' → '1').
                    $raw = trim($state);
                    $normalized = SpreadsheetParsers::parseInt($raw);
                    $boxNum = $normalized !== null ? (string) $normalized : $raw;
                    // Resolution is deferred to afterFill: we must know the
                    // document's batch (resolved by a separate column) before
                    // we create-or-match the box inside it, and we must
                    // cross-check batch/box consistency (B5) before save.
                    self::$rowBoxNumberStash[spl_object_id($record)] = $boxNum;
                    $record->ras_box_1 = $boxNum;
                }),

            // Box resolution by barcode — names a SPECIFIC existing physical
            // box (never created on a miss). Used to detect batch/box
            // inconsistencies (B5): the resolved box's batch must equal the
            // document's own batch.
            ImportColumn::make('current_box_barcode')
                ->label('Current box barcode')
                ->guess(['Current box barcode', 'current_box_barcode', 'Box barcode'])
                ->fillRecordUsing(function (Document $record, ?string $state): void {
                    if ($state === null || trim($state) === '') {
                        return;
                    }
                    self::$rowBoxBarcodeStash[spl_object_id($record)] = trim($state);
                }),

            // ── Barcodes ───────────────────────────────────────────────
            ImportColumn::make('barcode_in')
                ->label('Barcode (IN)')
                ->guess(['Barcode (IN)', 'Barcode IN', 'barcode_in'])
                ->rules(['nullable', 'string', 'max:50']),

            // Legacy custody status (IN / OUT / PERM_OUT). Task 8 (B4): the box
            // is the authoritative source of truth for barcode status since
            // Task 7, so this column does NOT write documents.barcode_status —
            // it lands on the legacy `status_1` column and is reconciled to the
            // BOX in afterSave (the box mirror then propagates it back down).
            ImportColumn::make('status_1')
                ->label('Status (IN / OUT / PERM_OUT)')
                ->guess(['Status 1', 'status_1', 'Status', 'Barcode status'])
                ->castStateUsing(function (?string $state): ?string {
                    if ($state === null) {
                        return null;
                    }
                    $s = strtoupper(trim($state));

                    return in_array($s, ['IN', 'OUT', 'PERM_OUT'], true) ? $s : null;
                })
                ->rules(['nullable', 'in:IN,OUT,PERM_OUT']),

            // ── Notes & free-form ──────────────────────────────────────
            ImportColumn::make('notes')
                ->label('Notes')
                ->guess(['Note', 'Notes', 'notes'])
                ->rules(['nullable', 'string']),

            ImportColumn::make('digitised')
                ->label('Digitised')
                ->guess(['Digitised', 'digitised'])
                ->rules(['nullable', 'string', 'max:100']),

            ImportColumn::make('torre')
                ->label('Torre (legacy flag)')
                ->guess(['Torre', 'torre'])
                ->boolean()
                ->rules(['nullable', 'boolean']),

            ImportColumn::make('accession_code_legacy')
                ->label('Accession (legacy code)')
                ->guess(['Accession', 'accession', 'accession_code_legacy'])
                ->rules(['nullable', 'string', 'max:191']),

            ImportColumn::make('object_reference_number')
                ->label('Object Reference Number')
                ->guess(['Object Reference Number', 'object_reference_number'])
                ->rules(['nullable', 'string']),

            ImportColumn::make('tracking')
                ->label('Tracking')
                ->guess(['Tracking', 'tracking'])
                ->rules(['nullable', 'string']),

            ImportColumn::make('museum_reference')
                ->label('Museum Reference')
                ->guess(['Museum Reference', 'museum_reference'])
                ->rules(['nullable', 'string']),
        ];
    }

    /**
     * Post-save side effects (box-status reconciliation + authority pivot
     * writes). Extracted from {@see afterSave()} so they run inside the per-row
     * savepoint and roll back atomically with the document on any failure.
     *
     * Reads from the static stash keyed by `spl_object_id` of the record —
     * populated inside the column closures during fill.
     */
    protected function persistRowSideEffects(): void
    {
        /** @var Document $record */
        $record = $this->record;
        $key = spl_object_id($record);

        // Task 8 (B4) — apply the reconciled barcode status to the BOX (the
        // authoritative source since Task 7). Done here, after the document
        // has its `current_box_id`, so the box→documents mirror reaches this
        // document. We never write documents.barcode_status directly: the
        // box's `updated`/`created` hooks own the mirror.
        $this->applyBoxBarcodeStatus($record);

        // Custom fields (EAV) — persist stashed key→value pairs via the trait.
        // The stash is populated by the dynamic custom-field ImportColumn closures.
        //
        // GROUP C+D fix: use merge semantics (replaceMissing=false) so unmapped
        // columns are left untouched on update. Only keys that were explicitly
        // mapped in this import run are processed (upsert or delete); absent keys
        // (columns the operator did not map) are not touched. An empty mapped cell
        // is stored as null in the stash → deletes that specific field value.
        $customData = self::$rowCustomFieldStash[$key] ?? null;
        unset(self::$rowCustomFieldStash[$key]);
        if ($customData !== null && method_exists($record, 'setCustomFieldData')) {
            // No try/catch here: setCustomFieldData() coerces every value with a
            // total (string) cast that cannot throw on a malformed cell — the
            // only realistic exception is a DB-level persistence error
            // (QueryException, deadlock, …). Swallowing that would commit the
            // Document row with partial/missing custom fields and hide the
            // failure. A persistence error MUST fail the row so it surfaces in
            // the failed-rows report. (Per-cell "lenient" handling is moot: a
            // bad value is stored verbatim and cast on read, never on write.)
            $record->setCustomFieldData($customData, false);  // false = merge/import semantics
        }

        // BUG-05: clean up the repository stash for this row.
        unset(self::$rowRepositoryStash[$key]);

        $ids = self::$rowAuthorityStash[$key] ?? [];
        // Drain the stash for this row so a future row reusing the same
        // object id (rare but theoretically possible) does not inherit it.
        unset(self::$rowAuthorityStash[$key]);

        if (count($ids) === 0) {
            return;
        }
        $ids = array_values(array_unique($ids));

        // First id = primary authority (matches LinkCreatorTextToAuthorities).
        $pivot = [];
        foreach ($ids as $i => $authorityId) {
            $pivot[$authorityId] = ['is_primary' => $i === 0];
        }
        $record->authorities()->syncWithoutDetaching($pivot);
    }

    /**
     * Collapse the legacy `status_*` columns into ONE barcode status, or null
     * when none of them carries a recognised value. PERM_OUT outranks OUT,
     * OUT outranks IN — the strongest custody state for the document/box wins.
     */
    protected function resolveLegacyBarcodeStatus(Document $record): ?string
    {
        $statuses = [
            $record->status_1, $record->status_2, $record->status_3, $record->status_4,
            $record->status_1_alt, $record->status_2_alt,
        ];

        $found = [];
        foreach ($statuses as $s) {
            if (! is_string($s)) {
                continue;
            }
            $v = strtoupper(trim($s));
            if (in_array($v, ['IN', 'OUT', 'PERM_OUT'], true)) {
                $found[$v] = true;
            }
        }

        return match (true) {
            isset($found['PERM_OUT']) => 'PERM_OUT',
            isset($found['OUT']) => 'OUT',
            isset($found['IN']) => 'IN',
            default => null,
        };
    }

    /**
     * Task 8 (B5) — resolve the current box for this row and enforce
     * batch/box consistency.
     *
     * Two resolution paths, in priority order:
     *   1. by barcode (`current_box_barcode`) — names a specific existing
     *      box; never created on a miss.
     *   2. by number (`current_box_number`) — create-if-absent inside the
     *      document's resolved batch (the accession may bring a brand-new box).
     *
     * Consistency (B5): whichever path resolves the box, the resolved box's
     * `batch_id` MUST equal the document's `batch_id`. A mismatch is a failed
     * row (RowImportFailedException) — never silently saved.
     */
    protected function resolveCurrentBox(Document $record): void
    {
        $key = spl_object_id($record);
        $barcode = self::$rowBoxBarcodeStash[$key] ?? null;
        $number = self::$rowBoxNumberStash[$key] ?? null;
        unset(self::$rowBoxBarcodeStash[$key], self::$rowBoxNumberStash[$key]);

        $box = null;
        if ($barcode !== null) {
            // Barcode names a specific existing box — no create.
            $box = EntityResolver::resolveBox($barcode);
        }
        if ($box === null && $number !== null && $record->batch_id !== null) {
            // Create-or-match inside the document's resolved batch.
            $box = EntityResolver::resolveBox(
                null,
                $record->batch_id,
                $number,
                create: true,
            );
        }

        if ($box === null) {
            return; // no resolvable box reference on this row
        }

        // B5 consistency — the document's batch must match its box's batch.
        if ($record->batch_id !== null && (int) $box['batch_id'] !== (int) $record->batch_id) {
            throw new RowImportFailedException('Document batch does not match its box batch.');
        }

        $record->current_box_id = $box['box_id'];
    }

    /**
     * Task 8 (B4) — push the reconciled barcode status onto the BOX.
     *
     * The box has been authoritative for barcode status since Task 7: rather
     * than writing `documents.barcode_status`, we set the value on the box and
     * let its `updated` mirror propagate to every document inside it (this
     * document included, because `current_box_id` is already set by now).
     *
     * For a PERM_OUT transition the box-level A1.2 guard requires the box to
     * carry a `disinfestation_date`; we backfill it from the document's own
     * disinfestation date when the box has none, so the guard passes and the
     * data stays coherent (the document's date is the accession's date).
     */
    protected function applyBoxBarcodeStatus(Document $record): void
    {
        $key = spl_object_id($record);
        $status = self::$rowBoxStatusStash[$key] ?? null;
        unset(self::$rowBoxStatusStash[$key]);

        if ($status === null || $record->current_box_id === null) {
            return;
        }

        $box = Box::withoutGlobalScopes()->find($record->current_box_id);
        if ($box === null) {
            return;
        }

        // A1.2 at the box — PERM_OUT needs a disinfestation_date. Seed it from
        // the document's date before flipping the status so the box guard passes.
        if ($status === 'PERM_OUT'
            && $box->disinfestation_date === null
            && $record->disinfestation_date !== null
        ) {
            $box->disinfestation_date = $record->disinfestation_date;
        }

        if ($box->barcode_status !== $status) {
            $box->barcode_status = $status;
        }

        if ($box->isDirty()) {
            // Import pipeline — legacy rows may lack a location; bypass the
            // PERM_OUT location guard (RFQ §3.1.7-A) for this programmatic
            // write while still allowing the Task-7 mirror observer to fire.
            $box->skipPermOutGuard = true;
            $box->save(); // triggers the Task-7 mirror onto the document(s)
        }
    }
}
