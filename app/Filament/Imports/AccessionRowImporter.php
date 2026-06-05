<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\SkipsExistingRows;
use App\Filament\Pages\ImportWizard;
use App\Models\Accession;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\Document;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Support\BulkImport\EntityResolver;
use App\Support\BulkImport\SpreadsheetParsers;
use App\Support\BulkImport\TemplateGenerator;
use App\Support\CustomFields\CustomFieldResolver;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Wave C (DECISIONS 2, 3, 4, 5, 10, 11) — Bottom-up Accession Importer.
 *
 * One XLSX row = one Document at the bottom of the cascade:
 *
 *   Authority(;-multi) → Accession → Batch (N:N via pivot) → Box → Document
 *
 * For each row the importer resolve-or-creates every ancestor in order, so a
 * single file can represent a brand-new accession, its batches, boxes, and
 * all documents without any prior setup work.
 *
 * DECISION 2: import is bottom-up — every parent entity is auto-created from
 * the same row when absent. Missing any required ref code is a row error.
 *
 * DECISION 3: multi-author via "Authority Identifier" (;-delimited). Optional
 * "Authority Name" / "Authority Surname" columns (same ; order) are used only
 * for VALIDATION against the resolved authority. A mismatch is a row error.
 * On create the entity_type defaults to "Notary".
 *
 * DECISION 4: document identifier = operator-provided "Identifier" column when
 * present; otherwise auto-generated as "{AccessionNumber}-{BoxNo}-{rowSeq}".
 * catalogue_identifier is left blank (not catalogued yet).
 *
 * DECISION 5: new field `part_number` (nullable string) on Document.
 *
 * DECISION 10: document barcode column omitted from the accession form; the
 * document inherits its custody status from the box. custody_status defaults
 * to "in_box". nra_location / museum_location / dates_precise are hidden.
 *
 * DECISION 11: same engine handles both new accessions and the current batch-
 * list mass import (no separate path required).
 *
 * Expected sheet column headers (in any order — the importer auto-guesses):
 *
 *   Authority Identifier   — required; ;-delimited R-codes (e.g. "R12;R88")
 *   Authority Name         — optional; ;-delimited given names (same order)
 *   Authority Surname      — optional; ;-delimited surnames (same order)
 *   Accession Number       — required; the accession's unique code/number
 *   Accession Title        — optional; human-readable title for the accession
 *   Batch Number           — required; integer batch number (not 34 or 36)
 *   Accession Type         — optional; type code for the batch (NOTARY_ACCESSION etc.)
 *   Repository             — optional; repository code (defaults to user's default repo)
 *   Box No                 — required; box number (unique within batch)
 *   Box Barcode            — required for RAS boxes; globally unique
 *   Box Type               — optional; defaults to RAS
 *   Identifier             — optional; document working identifier
 *   Document Type          — required; document type ref code
 *   Series                 — required; series code or "CODE: Title"
 *   Volume Number          — optional; volume label/number (DECISION 7: renamed)
 *   Part Number            — optional; part number within volume (DECISION 5)
 *   Practice               — optional; practice ref code
 *   Dates                  — optional; free-text date range
 *   Deeds                  — optional; number/description of deeds
 *   Notes                  — optional; free-text notes
 *
 * @see TemplateGenerator (entity key: 'accession')
 * @see ImportWizard (key: 'accessions')
 */
class AccessionRowImporter extends Importer
{
    use SkipsExistingRows;

    /**
     * Multi-value cell delimiter (inherited convention from DocumentImporter).
     * Hard-coded per RFQ Appendix-2 §xi — must not change to comma because
     * Maltese authority names legitimately contain commas.
     */
    public const SEMICOLON_DELIMITER = ';';

    protected static ?string $model = Document::class;

    // ── Per-row static stashes (same pattern as DocumentImporter) ─────────

    /**
     * Resolved authority ids accumulated during column fill; written to the
     * document_authority pivot in afterSave(). Key = spl_object_id($record).
     *
     * @var array<int, array<int, int>>
     */
    protected static array $rowAuthorityStash = [];

    /**
     * Resolved accession id for the row. Written to the document in
     * resolveRecord()/beforeSave() after the cascade completes.
     *
     * @var array<int, int|null>
     */
    protected static array $rowAccessionStash = [];

    /**
     * Row-local sequence counter per (accessionNumber, boxNumber) pair.
     * Used for auto-generating document identifiers (DECISION 4).
     * Key = "{accessionNumber}|{boxNo}", value = integer sequence.
     *
     * The counter is NOT flushed between rows (intentionally — it must
     * increment across rows in the same import run). Flushed only in
     * EntityResolver::flushMemo() called at test setup.
     *
     * @var array<string, int>
     */
    protected static array $boxRowSeq = [];

    /**
     * Per-row savepoint flag (same pattern as DocumentImporter).
     */
    protected bool $rowSavepointOpen = false;

    // ── Shared stash for custom fields (mirrors DocumentImporter) ──────────

    /**
     * @var array<int, array<string, string|null>>
     */
    protected static array $rowCustomFieldStash = [];

    // ── Column definition ─────────────────────────────────────────────────

    /**
     * @return array<ImportColumn>
     */
    public static function getColumns(): array
    {
        return array_merge(static::getStaticColumns(), static::getCustomFieldColumns());
    }

    /**
     * Idempotent matching: by (identifier, repository_id) if an identifier
     * column is provided; otherwise always insert a new Document row.
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
     * Post-fill cascade: resolve/create the full ancestor chain
     * (Authority → Accession → Batch → Box) then assign the resulting
     * FKs to the Document record. Any unresolvable ref code is a row error.
     *
     * This is the heart of DECISION 2 (bottom-up).
     */
    public function afterFill(): void
    {
        /** @var Document $record */
        $record = $this->record;
        $key = spl_object_id($record);

        // 1. Authorities (multi, DECISION 3)
        $this->resolveAuthorities($record);

        // 2. Accession → 3. Batch (N:N) → 4. Box
        $this->resolveAccessionBatchBox($record);

        // 5. Auto-generate document identifier if not supplied (DECISION 4)
        $this->ensureDocumentIdentifier($record);

        // 6. Year range from the free-text "dates" column.
        if (! empty($record->dates) && $record->dates_year_start === null) {
            [$y0, $y1] = SpreadsheetParsers::parseYearRange((string) $record->dates);
            if ($y0 !== null) {
                $record->dates_year_start = $y0;
            }
            if ($y1 !== null) {
                $record->dates_year_end = $y1;
            }
        }

        // 7. custody_status default (DECISION 10).
        if (empty($record->custody_status)) {
            $record->custody_status = 'in_box';
        }

        // 8. catalogue_identifier stays blank at accession time (DECISION 4).
        $record->catalogue_identifier = null;
    }

    public function beforeSave(): void
    {
        if ($this->rowSavepointOpen) {
            DB::rollBack();
            $this->rowSavepointOpen = false;
        }
        DB::beginTransaction();
        $this->rowSavepointOpen = true;
    }

    public function afterSave(): void
    {
        try {
            $this->persistRowSideEffects();
        } catch (\Throwable $e) {
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

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Accession import completed: '
            . number_format($import->successful_rows) . ' rows processed';
        if (($failed = $import->getFailedRowsCount()) > 0) {
            $body .= ', ' . number_format($failed) . ' failed';
        }

        return $body;
    }

    // ── Private helpers ───────────────────────────────────────────────────

    /**
     * Split a ;-delimited cell value into trimmed non-empty pieces.
     *
     * @return array<int, string>
     */
    public static function splitSemicolon(string $raw): array
    {
        if (! str_contains($raw, self::SEMICOLON_DELIMITER)) {
            $only = trim($raw);

            return $only === '' ? [] : [$only];
        }
        $pieces = array_map('trim', explode(self::SEMICOLON_DELIMITER, $raw));

        return array_values(array_filter($pieces, static fn (string $p): bool => $p !== ''));
    }

    /**
     * DECISION 3 — Resolve/create each authority identified in the
     * "Authority Identifier" column. Optional name/surname columns validate
     * (not auto-match) the resolved record. Stash the ids for afterSave().
     */
    protected function resolveAuthorities(Document $record): void
    {
        $key = spl_object_id($record);

        // Pull the stashed values set by the column closures.
        $rawIdentifiers = static::$rowAuthorityStash[$key]['identifiers'] ?? '';
        $rawNames = static::$rowAuthorityStash[$key]['names'] ?? '';
        $rawSurnames = static::$rowAuthorityStash[$key]['surnames'] ?? '';

        // Reset stash slot to a plain id list (afterSave reads it as int[]).
        unset(static::$rowAuthorityStash[$key]);

        if (trim($rawIdentifiers) === '') {
            // No Authority Identifier column supplied — soft-miss, no error.
            return;
        }

        $identifiers = self::splitSemicolon($rawIdentifiers);
        /** @var array<int, string> $names */
        $names = $rawNames !== '' ? self::splitSemicolon($rawNames) : [];
        /** @var array<int, string> $surnames */
        $surnames = $rawSurnames !== '' ? self::splitSemicolon($rawSurnames) : [];

        $authorityIds = [];
        foreach ($identifiers as $idx => $ident) {
            $intIdx = (int) $idx;
            $name = $names[$intIdx] ?? null;
            $surname = $surnames[$intIdx] ?? null;

            $res = EntityResolver::resolveAuthority($ident);

            if ($res !== null && isset($res['authority_id'])) {
                // Validate name/surname if provided (mismatch → row error).
                $authority = Authority::withoutGlobalScopes()->find($res['authority_id']);
                if ($authority !== null) {
                    if ($name !== null
                        && mb_strtolower(trim($name)) !== mb_strtolower((string) $authority->given_names)
                    ) {
                        throw ValidationException::withMessages([
                            'authority_name' => __(
                                "Authority ':ident': given name ':given' does not match record ':stored'.",
                                [
                                    'ident' => $ident,
                                    'given' => $name,
                                    'stored' => $authority->given_names,
                                ]
                            ),
                        ]);
                    }
                    if ($surname !== null
                        && mb_strtolower(trim($surname)) !== mb_strtolower((string) $authority->surname)
                    ) {
                        throw ValidationException::withMessages([
                            'authority_surname' => __(
                                "Authority ':ident': surname ':given' does not match record ':stored'.",
                                [
                                    'ident' => $ident,
                                    'given' => $surname,
                                    'stored' => $authority->surname,
                                ]
                            ),
                        ]);
                    }
                }
                $authorityIds[] = (int) $res['authority_id'];
            } else {
                // Authority not found — create it (DECISION 3: on create,
                // identifier + name + surname are used; entity_type = 'Notary').
                $newAuthority = Authority::withoutGlobalScopes()->create([
                    'identifier' => $ident,
                    'given_names' => $name,
                    'surname' => (string) ($surname ?? ''),
                    'entity_type' => 'Notary',
                ]);
                $authorityIds[] = (int) $newAuthority->id;
                // Flush memo so subsequent rows in the same import can find
                // the newly created authority by identifier.
                EntityResolver::flushMemo();
            }
        }

        // Store as a flat int[] for afterSave()/persistRowSideEffects().
        static::$rowAuthorityStash[$key] = $authorityIds;
    }

    /**
     * DECISIONS 2 + 10 — Cascade: Accession → Batch (N:N) → Box.
     * Any fatal ref-code error is thrown as a ValidationException (row error).
     */
    protected function resolveAccessionBatchBox(Document $record): void
    {
        $key = spl_object_id($record);

        $accessionNumber = static::$rowAccessionStash[$key]['accession_number'] ?? null;
        $accessionTitle = static::$rowAccessionStash[$key]['accession_title'] ?? null;
        $batchNumber = static::$rowAccessionStash[$key]['batch_number'] ?? null;
        $batchType = static::$rowAccessionStash[$key]['batch_type'] ?? null;
        $repositoryCode = static::$rowAccessionStash[$key]['repository_code'] ?? null;
        $boxNumber = static::$rowAccessionStash[$key]['box_number'] ?? null;
        $boxBarcode = static::$rowAccessionStash[$key]['box_barcode'] ?? null;
        $boxType = static::$rowAccessionStash[$key]['box_type'] ?? 'RAS';

        // Reset stash slot — afterSave only needs the accession id.
        unset(static::$rowAccessionStash[$key]);

        // ─── Repository ───────────────────────────────────────────────────
        $repoId = null;
        if ($repositoryCode !== null) {
            $res = EntityResolver::resolveRepository($repositoryCode);
            if ($res === null) {
                throw ValidationException::withMessages([
                    'repository' => __(
                        "Repository ':code' not found. Check the Repository column.",
                        ['code' => $repositoryCode]
                    ),
                ]);
            }
            $repoId = $res['repository_id'];
        }
        if ($repoId === null) {
            $repoId = auth()->user()?->default_repository_id;
        }
        if ($repoId !== null) {
            $record->repository_id = $repoId;
        }

        // ─── Accession ────────────────────────────────────────────────────
        $accessionId = null;
        if ($accessionNumber !== null) {
            $accQuery = Accession::withoutGlobalScope(RepositoryScope::class)
                ->where('accession_number', $accessionNumber);
            if ($repoId !== null) {
                $accQuery->where('repository_id', $repoId);
            }
            $accession = $accQuery->first();

            if ($accession === null) {
                // Create a new accession from this row's data.
                // `code` is NOT NULL in the schema — fall back to accession_number
                // when the operator did not supply a title (DECISION 2: auto-create).
                $createAttrs = [
                    'accession_number' => $accessionNumber,
                    'code' => $accessionTitle ?? $accessionNumber,
                    'repository_id' => $repoId,
                ];
                $accession = Accession::withoutGlobalScope(RepositoryScope::class)
                    ->create($createAttrs);
            } elseif ($accessionTitle !== null && empty($accession->code)) {
                // Update title only if blank (don't overwrite operator edits).
                $accession->update(['code' => $accessionTitle]);
            }
            $accessionId = (int) $accession->id;
        }

        $record->accession_id = $accessionId;
        // Stash for afterSave (needed for batch→accession pivot attach).
        static::$rowAccessionStash[$key] = ['accession_id' => $accessionId];

        // ─── Batch ────────────────────────────────────────────────────────
        $batchId = null;
        if ($batchNumber !== null) {
            $batchNumberInt = (int) $batchNumber;

            $res = EntityResolver::resolveBatch($batchNumberInt, $repoId, create: true);
            if ($res === null) {
                throw ValidationException::withMessages([
                    'batch_number' => __(
                        'Batch :n could not be resolved or created.',
                        ['n' => $batchNumberInt]
                    ),
                ]);
            }
            if (isset($res['forbidden'])) {
                throw ValidationException::withMessages([
                    'batch_number' => __(
                        'Batch :n is reserved (RFQ App.1 #1) and cannot be used.',
                        ['n' => (int) $res['forbidden']]
                    ),
                ]);
            }
            $batchId = $res['batch_id'];

            // Update batch type if provided and the batch is freshly created.
            if ($batchType !== null) {
                $batch = Batch::withoutGlobalScope(RepositoryScope::class)->find($batchId);
                if ($batch !== null && $batch->type !== $batchType) {
                    // Only overwrite if it still has the auto-derived type.
                    $derivedType = $batchNumberInt >= 30 ? 'NOTARY_ACCESSION' : 'MAIN_COLLECTION';
                    if ($batch->type === $derivedType) {
                        $batch->update(['type' => $batchType]);
                    }
                }
            }

            $record->batch_id = $batchId;
            $record->ras_batch_1 = (string) $batchNumberInt;

            // Attach the accession to this batch via the N:N pivot (DECISION 1 / Wave B).
            if ($accessionId !== null) {
                $accession = Accession::withoutGlobalScope(RepositoryScope::class)->find($accessionId);
                if ($accession !== null) {
                    $accession->batches()->syncWithoutDetaching([$batchId]);
                }
            }
        }

        // ─── Box ─────────────────────────────────────────────────────────
        if ($batchId !== null && $boxNumber !== null) {
            // Validate global barcode uniqueness when a barcode is provided
            // (DECISION C2 / Wave A rule A10).
            if ($boxBarcode !== null) {
                $existingByBarcode = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
                    ->where('barcode', $boxBarcode)
                    ->first(['id', 'batch_id', 'box_number']);
                if ($existingByBarcode !== null) {
                    // Box exists by barcode — use it (it may be in a different batch, which
                    // would be a mismatch; catch that below with the batch consistency check).
                    $boxRes = ['box_id' => (int) $existingByBarcode->id, 'batch_id' => (int) $existingByBarcode->batch_id];
                } else {
                    // Barcode not taken — create-or-find by (batch_id, box_number).
                    $boxRes = EntityResolver::resolveBox(
                        null,
                        $batchId,
                        $boxNumber,
                        create: true,
                        boxType: $boxType,
                    );
                    // Stamp the barcode on the freshly created box.
                    if ($boxRes !== null) {
                        $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)->find($boxRes['box_id']);
                        if ($box !== null && $box->barcode === null) {
                            $box->barcode = $boxBarcode;
                            $box->save();
                        }
                    }
                }
            } else {
                // No barcode supplied — resolve/create by (batch_id, box_number).
                $boxRes = EntityResolver::resolveBox(
                    null,
                    $batchId,
                    $boxNumber,
                    create: true,
                    boxType: $boxType,
                );
            }

            if ($boxRes === null) {
                throw ValidationException::withMessages([
                    'box_number' => __(
                        "Box ':box' in Batch :batch could not be resolved or created.",
                        ['box' => $boxNumber, 'batch' => $batchNumber]
                    ),
                ]);
            }

            // Batch/box consistency check (mirrors DocumentImporter B5).
            if ((int) $boxRes['batch_id'] !== (int) $batchId) {
                throw ValidationException::withMessages([
                    'box_number' => __(
                        "Box ':box' belongs to Batch :box_batch but the row specifies Batch :row_batch.",
                        [
                            'box' => $boxNumber,
                            'box_batch' => $boxRes['batch_id'],
                            'row_batch' => $batchId,
                        ]
                    ),
                ]);
            }

            $record->current_box_id = $boxRes['box_id'];
            $record->ras_box_1 = $boxNumber;
        }
    }

    /**
     * DECISION 4 — If no explicit "Identifier" column was provided (or it is
     * blank/AUTO-), generate a provisional identifier:
     *   {AccessionNumber}-{BoxNo}-{running-seq-within-box}
     *
     * If neither accession nor box info is available, fall back to a random
     * UUID fragment so every row gets a non-null identifier.
     */
    protected function ensureDocumentIdentifier(Document $record): void
    {
        $key = spl_object_id($record);
        if (! empty($record->identifier) && ! str_starts_with((string) $record->identifier, 'AUTO-')) {
            return;
        }

        $accessionId = static::$rowAccessionStash[$key]['accession_id'] ?? null;
        $accessionNumber = null;
        if ($accessionId !== null) {
            $accessionNumber = Accession::withoutGlobalScopes()->find($accessionId)?->accession_number;
        }

        $boxNo = $record->ras_box_1 ?? null;

        if ($accessionNumber !== null || $boxNo !== null) {
            $seqKey = ($accessionNumber ?? '') . '|' . ($boxNo ?? '');
            static::$boxRowSeq[$seqKey] = (static::$boxRowSeq[$seqKey] ?? 0) + 1;
            $seq = static::$boxRowSeq[$seqKey];
            $record->identifier = sprintf('%s-%s-%d', $accessionNumber ?? 'ACC', $boxNo ?? 'BOX', $seq);
        } else {
            // Ultimate fallback: a unique enough provisional handle.
            $record->identifier = 'AUTO-' . substr(str_replace('-', '', (string) Str::uuid()), 0, 12);
        }
    }

    /**
     * Persist the authority pivot after the Document row has its PK.
     * Mirrors DocumentImporter::persistRowSideEffects().
     */
    protected function persistRowSideEffects(): void
    {
        /** @var Document $record */
        $record = $this->record;
        $key = spl_object_id($record);

        // Authority pivot.
        $ids = static::$rowAuthorityStash[$key] ?? [];
        unset(static::$rowAuthorityStash[$key]);
        if (is_array($ids) && count($ids) > 0) {
            $ids = array_values(array_unique($ids));
            $pivot = [];
            foreach ($ids as $i => $authorityId) {
                $pivot[$authorityId] = ['is_primary' => $i === 0];
            }
            $record->authorities()->syncWithoutDetaching($pivot);
        }

        // Clear the accession stash slot (no further work needed for it).
        unset(static::$rowAccessionStash[$key]);

        // Custom fields (EAV) — same pattern as DocumentImporter.
        $customData = static::$rowCustomFieldStash[$key] ?? null;
        unset(static::$rowCustomFieldStash[$key]);
        if ($customData !== null && method_exists($record, 'setCustomFieldData')) {
            $record->setCustomFieldData($customData, false);
        }
    }

    // ── Static column definitions ──────────────────────────────────────────

    /**
     * The fixed (non-EAV) import columns for the bottom-up accession sheet.
     *
     * @return array<ImportColumn>
     */
    protected static function getStaticColumns(): array
    {
        return [
            // ── Authority (DECISION 3) ──────────────────────────────────
            ImportColumn::make('authority_identifier')
                ->label('Authority Identifier')
                ->guess(['Authority Identifier', 'authority_identifier', 'Identifier', 'R-code', 'R code'])
                ->fillRecordUsing(function (Document $record, ?string $state): void {
                    $key = spl_object_id($record);
                    static::$rowAuthorityStash[$key]['identifiers'] = $state ?? '';
                }),

            ImportColumn::make('authority_name')
                ->label('Authority Name')
                ->guess(['Authority Name', 'authority_name', 'Given Name', 'Creator Name'])
                ->fillRecordUsing(function (Document $record, ?string $state): void {
                    $key = spl_object_id($record);
                    static::$rowAuthorityStash[$key]['names'] = $state ?? '';
                }),

            ImportColumn::make('authority_surname')
                ->label('Authority Surname')
                ->guess(['Authority Surname', 'authority_surname', 'Creator Surname', 'Surname'])
                ->fillRecordUsing(function (Document $record, ?string $state): void {
                    $key = spl_object_id($record);
                    static::$rowAuthorityStash[$key]['surnames'] = $state ?? '';
                }),

            // ── Accession ──────────────────────────────────────────────
            ImportColumn::make('accession_number')
                ->label('Accession Number')
                ->requiredMappingForNewRecordsOnly()
                ->guess(['Accession Number', 'accession_number', 'Accession No', 'Notary Accession Number'])
                ->fillRecordUsing(function (Document $record, ?string $state): void {
                    $key = spl_object_id($record);
                    static::$rowAccessionStash[$key]['accession_number'] = ($state !== null && trim($state) !== '')
                        ? trim($state)
                        : null;
                }),

            ImportColumn::make('accession_title')
                ->label('Accession Title')
                ->guess(['Accession Title', 'accession_title', 'Accession Code', 'Title'])
                ->fillRecordUsing(function (Document $record, ?string $state): void {
                    $key = spl_object_id($record);
                    if ($state !== null && trim($state) !== '') {
                        static::$rowAccessionStash[$key]['accession_title'] = trim($state);
                    }
                }),

            // ── Batch (N:N, DECISIONS 1+2) ──────────────────────────────
            ImportColumn::make('batch_number')
                ->label('Batch Number')
                ->requiredMappingForNewRecordsOnly()
                ->integer()
                ->guess(['Batch Number', 'batch_number', 'Batch No', 'Batch'])
                ->fillRecordUsing(function (Document $record, mixed $state): void {
                    $n = SpreadsheetParsers::parseInt($state);
                    if ($n !== null) {
                        $key = spl_object_id($record);
                        static::$rowAccessionStash[$key]['batch_number'] = $n;
                    }
                }),

            ImportColumn::make('accession_type')
                ->label('Accession Type')
                ->guess(['Accession Type', 'accession_type', 'Batch Type', 'Type'])
                ->fillRecordUsing(function (Document $record, ?string $state): void {
                    if ($state !== null && trim($state) !== '') {
                        $key = spl_object_id($record);
                        static::$rowAccessionStash[$key]['batch_type'] = strtoupper(trim($state));
                    }
                }),

            ImportColumn::make('repository')
                ->label('Repository')
                ->guess(['Repository', 'repository', 'Repository Code', 'Repo'])
                ->fillRecordUsing(function (Document $record, ?string $state): void {
                    if ($state !== null && trim($state) !== '') {
                        $key = spl_object_id($record);
                        static::$rowAccessionStash[$key]['repository_code'] = trim($state);
                    }
                }),

            // ── Box ────────────────────────────────────────────────────
            ImportColumn::make('box_number')
                ->label('Box No')
                ->requiredMappingForNewRecordsOnly()
                ->guess(['Box No', 'Box Number', 'box_number', 'Box', 'RAS Box', 'RAS Box 1'])
                ->fillRecordUsing(function (Document $record, ?string $state): void {
                    if ($state !== null && trim($state) !== '') {
                        $key = spl_object_id($record);
                        static::$rowAccessionStash[$key]['box_number'] = trim($state);
                    }
                }),

            ImportColumn::make('box_barcode')
                ->label('Box Barcode')
                ->guess(['Box Barcode', 'box_barcode', 'Barcode', 'Barcode (IN)'])
                ->fillRecordUsing(function (Document $record, ?string $state): void {
                    if ($state !== null && trim($state) !== '') {
                        $key = spl_object_id($record);
                        static::$rowAccessionStash[$key]['box_barcode'] = trim($state);
                    }
                }),

            ImportColumn::make('box_type')
                ->label('Box Type')
                ->guess(['Box Type', 'Box Status', 'box_type', 'Type of Box'])
                ->fillRecordUsing(function (Document $record, ?string $state): void {
                    if ($state !== null && trim($state) !== '') {
                        $key = spl_object_id($record);
                        $t = strtoupper(trim($state));
                        static::$rowAccessionStash[$key]['box_type'] = match ($t) {
                            'IN SITU', 'IN-SITU' => 'IN_SITU',
                            default => $t,
                        };
                    }
                }),

            // ── Document fields ─────────────────────────────────────────
            // DECISION 4: operator-provided identifier (optional).
            // Template column header is lowercase 'identifier' (NAf convention).
            ImportColumn::make('identifier')
                ->label('Identifier')
                ->guess(['identifier', 'Identifier', 'Document Identifier', 'Doc ID'])
                ->rules(['nullable', 'string', 'max:64']),

            ImportColumn::make('document_type')
                ->label('Document Type')
                ->requiredMappingForNewRecordsOnly()
                ->guess(['Document Type', 'document_type', 'Doc Type', 'Type'])
                ->rules(['required', 'string', 'max:64']),

            ImportColumn::make('series')
                ->label('Series')
                ->requiredMappingForNewRecordsOnly()
                ->guess(['Series', 'series', 'Series Code'])
                ->fillRecordUsing(function (Document $record, ?string $state): void {
                    if ($state === null || trim($state) === '') {
                        throw ValidationException::withMessages([
                            'series' => __('Series is required.'),
                        ]);
                    }
                    $res = EntityResolver::resolveSeries($state);
                    if ($res === null) {
                        throw ValidationException::withMessages([
                            'series' => __("Series ':code' not found.", ['code' => trim($state)]),
                        ]);
                    }
                    $record->series_id = $res['series_id'];
                }),

            // DECISION 7: volume_label column header renamed to "Volume Number".
            // Template column header is 'Volume No' (NAf Feedback 1 convention).
            ImportColumn::make('volume_label')
                ->label('Volume Number')
                ->guess(['Volume No', 'Volume Number', 'Volume', 'volume_number', 'Volume Label', 'volume_label'])
                ->rules(['nullable', 'string', 'max:64']),

            // DECISION 5: new part_number field.
            ImportColumn::make('part_number')
                ->label('Part Number')
                ->guess(['Part Number', 'part_number', 'Part No', 'Part'])
                ->rules(['nullable', 'string', 'max:64']),

            ImportColumn::make('practice')
                ->label('Practice')
                ->guess(['Practice', 'practice'])
                ->rules(['nullable', 'string', 'max:100']),

            ImportColumn::make('dates')
                ->label('Dates (free-text)')
                ->guess(['Dates', 'dates', 'Date range'])
                ->rules(['nullable', 'string', 'max:191']),

            ImportColumn::make('deeds')
                ->label('Deeds')
                ->guess(['Deeds', 'deeds'])
                ->rules(['nullable', 'string']),

            // Template column header is 'Note' (singular, NAf Feedback 1 convention).
            ImportColumn::make('notes')
                ->label('Notes')
                ->guess(['Note', 'Notes', 'notes'])
                ->rules(['nullable', 'string']),
        ];
    }

    /**
     * Dynamic custom-field columns for the 'document' entity type.
     * Mirrors DocumentImporter::getCustomFieldColumns().
     *
     * @return array<ImportColumn>
     */
    protected static function getCustomFieldColumns(): array
    {
        $defs = CustomFieldResolver::definitionsFor('document');
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
                    static::$rowCustomFieldStash[$key][$def->key] = ($state !== null && trim($state) !== '')
                        ? trim($state)
                        : null;
                });
        }

        return $columns;
    }
}
