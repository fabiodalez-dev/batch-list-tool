<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\SkipsExistingRows;
use App\Models\CustomFieldDefinition;
use App\Models\Document;
use App\Models\Scopes\RepositoryScope;
use App\Models\Volume;
use App\Support\BulkImport\SpreadsheetParsers;
use App\Support\CustomFields\CustomFieldResolver;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Validation\ValidationException;

/**
 * RFQ §3.1.3 / spec §4 — Bulk import for {@see Volume}.
 *
 * A Volume belongs to exactly one Document. The parent Document is resolved by
 * its `identifier` column, scoped to the active repository (multi-tenant):
 * a document that belongs to a different repository is rejected so an operator
 * cannot accidentally move volumes across tenants.
 *
 * Static column contract (mirrors TemplateGenerator::synthesiseVolumeHeaders()):
 *
 *   document_identifier  — required; resolves Volume.document_id.
 *   volume_number        — Volume.volume_number.
 *   dates_start          — Volume.dates_start (Y-m-d or free-text date).
 *   dates_end            — Volume.dates_end   (Y-m-d or free-text date).
 *   notes                — Volume.notes (free text).
 *
 * Followed by dynamic custom-field columns for the 'volume' entity type
 * in the active repository (CustomFieldResolver::definitionsFor('volume')).
 *
 * Idempotent: if (document_id, volume_number) already exists, the row is
 * treated as an update (upsert). The SkipsExistingRows trait honours the
 * "Skip duplicates" checkbox.
 *
 * Custom-field persistence: uses merge semantics (replaceMissing=false) via
 * $record->setCustomFieldData() in {@see afterSave()} — identical to the
 * other three importers (Document, Batch, Box).
 */
class VolumeImporter extends Importer
{
    use SkipsExistingRows;

    protected static ?string $model = Volume::class;

    /**
     * Per-row stash for the resolved Document id. Populated in the
     * document_identifier column closure and consumed in resolveRecord() /
     * afterFill(). Keyed by spl_object_id of the record.
     *
     * @var array<int, int|null>
     */
    protected static array $rowDocumentIdStash = [];

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
     * Idempotent upsert on (document_id, volume_number).
     *
     * We resolve via the stash instead of $this->data because the
     * document_identifier column's fillRecordUsing closure sets the FK on the
     * record (and in the stash) — $this->data still carries the raw string at
     * resolveRecord() time.
     *
     * Falls back to a plain new Volume when the document_id is not yet known
     * (the afterFill validation will catch the missing FK case).
     */
    public function resolveRecord(): ?Volume
    {
        // Peek at the data to attempt an early lookup by document_identifier.
        // The column closure has not run yet at this point, so we do a quick
        // inline resolve here just to detect existing records.
        $identifier = isset($this->data['document_identifier'])
            ? trim((string) $this->data['document_identifier'])
            : null;
        $volumeNumber = isset($this->data['volume_number'])
            ? trim((string) $this->data['volume_number'])
            : null;

        if ($identifier !== null && $identifier !== '' && $volumeNumber !== null && $volumeNumber !== '') {
            $repoId = CustomFieldResolver::activeRepositoryId();

            $docQuery = Document::query()
                ->withoutGlobalScope(RepositoryScope::class)
                ->where('identifier', $identifier);
            if ($repoId !== null) {
                $docQuery->where('repository_id', $repoId);
            }
            $document = $docQuery->first();

            if ($document !== null) {
                $existing = Volume::query()
                    ->where('document_id', $document->getKey())
                    ->where('volume_number', $volumeNumber)
                    ->first();

                if ($existing !== null) {
                    $this->skipIfDuplicate($existing);

                    return $existing;
                }
            }
        }

        return new Volume;
    }

    /**
     * Validate that document_id was resolved (the FK is required).
     * An unresolved document identifier fails the row with a clean message
     * rather than a cryptic NOT NULL constraint violation.
     */
    public function afterFill(): void
    {
        /** @var Volume $record */
        $record = $this->record;
        $key = spl_object_id($record);

        // Peek at the stash — if the closure fired but found no matching document
        // the stash holds null, meaning the identifier was unresolvable.
        if (array_key_exists($key, self::$rowDocumentIdStash) && self::$rowDocumentIdStash[$key] === null) {
            // Drain BOTH stash entries before throwing so the static maps do not
            // grow unboundedly when many rows fail — afterSave() never runs for a
            // row rejected here.
            unset(self::$rowDocumentIdStash[$key], self::$rowCustomFieldStash[$key]);

            throw ValidationException::withMessages([
                'document_identifier' => __('No document found with this identifier in the active repository.'),
            ]);
        }

        // Also guard the case where the column was never mapped at all.
        if ($record->document_id === null) {
            // Drain stash on this failure path too.
            unset(self::$rowDocumentIdStash[$key], self::$rowCustomFieldStash[$key]);

            throw new RowImportFailedException(
                'document_identifier is required and must resolve to an existing document in the active repository.'
            );
        }
    }

    /**
     * Persist custom-field side effects after the Volume row has been saved.
     * Uses merge semantics (replaceMissing=false). Failures are absorbed so
     * a bad custom-field cell never fails an otherwise valid row.
     */
    public function afterSave(): void
    {
        /** @var Volume $record */
        $record = $this->record;
        $key = spl_object_id($record);

        // Clean up the document id stash for this row.
        unset(self::$rowDocumentIdStash[$key]);

        $customData = self::$rowCustomFieldStash[$key] ?? null;
        unset(self::$rowCustomFieldStash[$key]);

        if ($customData !== null && method_exists($record, 'setCustomFieldData')) {
            try {
                $record->setCustomFieldData($customData, false);
            } catch (\Throwable) {
                // Lenient: a bad custom cell must NOT fail the row.
            }
        }
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Volumes import completed: '
            . number_format($import->successful_rows) . ' rows processed';
        if (($failed = $import->getFailedRowsCount()) > 0) {
            $body .= ', ' . number_format($failed) . ' failed';
        }

        return $body;
    }

    /**
     * Static import columns — must stay in sync with
     * TemplateGenerator::synthesiseVolumeHeaders() (same keys, same order).
     *
     * @return array<ImportColumn>
     */
    protected static function getStaticColumns(): array
    {
        return [
            // ── Parent document (required FK) ──────────────────────────────
            ImportColumn::make('document_identifier')
                ->label('Document identifier')
                ->requiredMapping()
                ->guess(['document_identifier', 'Document identifier', 'Document ID', 'Identifier'])
                ->rules(['required', 'string', 'max:64'])
                ->fillRecordUsing(static function (Volume $record, ?string $state): void {
                    if ($state === null || trim($state) === '') {
                        // Will be caught in afterFill.
                        return;
                    }
                    $identifier = trim($state);
                    $repoId = CustomFieldResolver::activeRepositoryId();

                    // Resolve the parent Document scoped to the active repository.
                    // Using withoutGlobalScope so we can scope manually: the global
                    // RepositoryScope would apply the session repo anyway, but we
                    // need to do an explicit tenant check here.
                    $query = Document::query()
                        ->withoutGlobalScope(RepositoryScope::class)
                        ->where('identifier', $identifier);

                    if ($repoId !== null) {
                        $query->where('repository_id', $repoId);
                    }

                    $document = $query->first();

                    $key = spl_object_id($record);
                    if ($document === null) {
                        // Stash null so afterFill can fail the row with a clean message.
                        static::$rowDocumentIdStash[$key] = null;

                        return;
                    }

                    // Tenant safety: if no repo filter was applied (repoId is null, e.g.
                    // no active repo set), still accept the first matching document.
                    // But if a specific repo IS set, the query already filtered for it.
                    static::$rowDocumentIdStash[$key] = (int) $document->getKey();
                    $record->document_id = (int) $document->getKey();
                }),

            // ── Core volume fields ─────────────────────────────────────────
            ImportColumn::make('volume_number')
                ->label('Volume number')
                ->guess(['volume_number', 'Volume number', 'Volume', 'Number'])
                ->rules(['nullable', 'string', 'max:64']),

            ImportColumn::make('dates_start')
                ->label('Dates start')
                ->guess(['dates_start', 'Dates start', 'Start date', 'Date start'])
                ->castStateUsing(fn (mixed $state) => SpreadsheetParsers::parseDate($state))
                ->rules(['nullable', 'date']),

            ImportColumn::make('dates_end')
                ->label('Dates end')
                ->guess(['dates_end', 'Dates end', 'End date', 'Date end'])
                ->castStateUsing(fn (mixed $state) => SpreadsheetParsers::parseDate($state))
                ->rules(['nullable', 'date']),

            ImportColumn::make('notes')
                ->label('Notes')
                ->guess(['notes', 'Notes', 'Note'])
                ->rules(['nullable', 'string']),
        ];
    }

    /**
     * Dynamic custom-field columns for the 'volume' entity type.
     *
     * Mirrors DocumentImporter::getCustomFieldColumns(). Values stashed in
     * {@see $rowCustomFieldStash} and persisted after save with merge semantics.
     *
     * @return array<ImportColumn>
     */
    protected static function getCustomFieldColumns(): array
    {
        /** @var EloquentCollection<int, CustomFieldDefinition> $defs */
        $defs = CustomFieldResolver::definitionsFor('volume');
        if ($defs->isEmpty()) {
            return [];
        }

        $columns = [];
        foreach ($defs as $def) {
            $columns[] = ImportColumn::make('custom_field_' . $def->key)
                ->label($def->label . ' (custom field)')
                ->guess([$def->label, $def->key, 'cf_' . $def->key])
                ->rules(['nullable', 'string'])
                ->fillRecordUsing(static function (Volume $record, ?string $state) use ($def): void {
                    $key = spl_object_id($record);
                    static::$rowCustomFieldStash[$key][$def->key] = ($state !== null && trim($state) !== '')
                        ? trim($state)
                        : null;
                });
        }

        return $columns;
    }
}
