<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\SkipsExistingRows;
use App\Models\Authority;
use App\Models\Document;
use App\Models\Scopes\RepositoryScope;
use App\Support\BulkImport\EntityResolver;
use App\Support\BulkImport\SpreadsheetParsers;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
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
     * @return array<ImportColumn>
     */
    public static function getColumns(): array
    {
        return [
            // ── Identification ──────────────────────────────────────────
            ImportColumn::make('identifier')
                ->label('Document identifier (R-code or composite)')
                ->requiredMappingForNewRecordsOnly()
                ->guess(['Identifier', 'identifier', 'Document Identifier', 'Doc ID'])
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

            ImportColumn::make('volume_label')
                ->label('Volume')
                ->guess(['Volume', 'volume', 'Volume label', 'volume_label'])
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
                // NB: the legacy sample column header is just "Identifier",
                // which collides with the Document's own identifier column.
                // We disambiguate at the operator level: they're shown both
                // dropdowns and pick which spreadsheet column feeds which.
                ->guess(['Authority identifier', 'Authority code', 'Creator code'])
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
                    $res = EntityResolver::resolveBatch($n);
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
                    $box = EntityResolver::resolveBox(null, $record->batch_id, trim($state));
                    if ($box !== null) {
                        $record->current_box_id = $box['box_id'];
                    }
                    $record->ras_box_1 = trim($state);
                }),

            // ── Barcodes ───────────────────────────────────────────────
            ImportColumn::make('barcode_in')
                ->label('Barcode (IN)')
                ->guess(['Barcode (IN)', 'Barcode IN', 'barcode_in'])
                ->rules(['nullable', 'string', 'max:50']),

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
     * Idempotent matching by (identifier, repository_id) — repository
     * tenancy is inferred from the BelongsToRepository hook on save.
     */
    public function resolveRecord(): ?Document
    {
        $identifier = $this->data['identifier'] ?? null;
        if ($identifier === null || trim((string) $identifier) === '') {
            return new Document;
        }

        $user = auth()->user();
        $repoId = $user?->default_repository_id;

        $q = Document::query()
            ->withoutGlobalScope(RepositoryScope::class)
            ->where('identifier', trim((string) $identifier));
        if ($repoId !== null) {
            $q->where('repository_id', $repoId);
        }

        $record = $q->first() ?? new Document;
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

        // RFQ App.1 #5 — PERM_OUT requires disinfestation_date. The
        // document-level fields here are the legacy status columns
        // (status_1, status_2 …) so we treat ANY of them being PERM_OUT
        // as the trigger.
        $statuses = [
            $record->status_1, $record->status_2, $record->status_3, $record->status_4,
            $record->status_1_alt, $record->status_2_alt,
        ];
        $isPermOut = false;
        foreach ($statuses as $s) {
            if (is_string($s) && strtoupper(trim($s)) === 'PERM_OUT') {
                $isPermOut = true;
                break;
            }
        }
        if ($isPermOut && $record->disinfestation_date === null) {
            throw ValidationException::withMessages([
                'disinfestation_date' => __(
                    'Documents with a PERM_OUT status must carry a disinfestation_date (RFQ App.1 #5).'
                ),
            ]);
        }
    }

    /**
     * Attach resolved authorities AFTER the Document row has its id.
     * Done here (not in `fillRecord`) because the pivot insert needs the
     * parent id. Reads from the static stash keyed by `spl_object_id` of
     * the record — we populate the stash inside the column closures.
     */
    public function afterSave(): void
    {
        /** @var Document $record */
        $record = $this->record;
        $key = spl_object_id($record);
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
}
