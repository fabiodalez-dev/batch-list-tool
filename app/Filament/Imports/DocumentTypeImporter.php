<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\LogsImportRows;
use App\Filament\Imports\Concerns\RenamesColumns;
use App\Filament\Imports\Concerns\SkipsExistingRows;
use App\Models\CustomFieldDefinition;
use App\Models\DocumentType;
use App\Support\CustomFields\CustomFieldResolver;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Collection;

/**
 * RFQ §3.1.11 — bulk import for the {@see DocumentType} controlled
 * vocabulary. Client 2026-08-18 (#17): "Document Type — this is going to be
 * a mass import as well … it needs to be done BEFORE Document Importation as
 * the documents link to it." So this importer seeds the lookup that the
 * DocumentImporter resolves `document_type_id` against (soft FK, #17/#12).
 *
 * DocumentType is a GLOBAL reference table (no repository scope) with a
 * machine-readable `identifier` (used as the resolution key on the document
 * sheet) and a human `name`. Matching is idempotent: a re-import updates the
 * existing row by identifier (or by name when no identifier is supplied)
 * instead of creating a duplicate, and un-deletes a soft-deleted match.
 */
class DocumentTypeImporter extends Importer
{
    use LogsImportRows;
    use RenamesColumns;
    use SkipsExistingRows;

    protected static ?string $model = DocumentType::class;

    /**
     * Per-row stash for custom-field key => value data, keyed by
     * spl_object_id of the record. Persisted in {@see afterSave()}.
     *
     * @var array<int, array<string, string|null>>
     */
    protected static array $rowCustomFieldStash = [];

    /**
     * @return array<ImportColumn>
     */
    public static function getColumns(): array
    {
        return static::applyRenameableLabels(
            'documentType',
            array_merge(static::getStaticColumns(), static::getCustomFieldColumns()),
        );
    }

    /**
     * Idempotent matching: by `identifier` first (the stable resolution key),
     * falling back to `name`. Re-running the same sheet updates the existing
     * row instead of inserting a duplicate (both `identifier` and `name` are
     * unique in the schema). DocumentType is NOT soft-deletable, so there is
     * no trashed-row handling here (unlike Series/Batch).
     */
    public function resolveRecord(): ?DocumentType
    {
        $identifier = isset($this->data['identifier']) ? trim((string) $this->data['identifier']) : '';
        $name = isset($this->data['name']) ? trim((string) $this->data['name']) : '';

        $query = DocumentType::query();
        if ($identifier !== '') {
            $query->whereRaw('LOWER(identifier) = ?', [mb_strtolower($identifier)]);
        } elseif ($name !== '') {
            $query->whereRaw('LOWER(name) = ?', [mb_strtolower($name)]);
        } else {
            return new DocumentType;
        }

        $record = $query->first();
        if ($record === null) {
            return new DocumentType;
        }

        $this->skipIfDuplicate($record);

        return $record;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Document Type import completed: ' . number_format($import->successful_rows) . ' row(s) imported.';

        $failed = $import->getFailedRowsCount();
        if ($failed > 0) {
            $body .= ' ' . number_format($failed) . ' row(s) failed.';
        }

        return $body;
    }

    /**
     * Persist the added columns once the row itself has been saved.
     *
     * Merge semantics (replaceMissing = false): a sheet that maps only some of
     * the added columns must not wipe the ones it does not mention.
     */
    public function afterSave(): void
    {
        /** @var DocumentType $record */
        $record = $this->record;
        $key = spl_object_id($record);

        $customData = static::$rowCustomFieldStash[$key] ?? null;
        unset(static::$rowCustomFieldStash[$key]);

        if ($customData !== null && method_exists($record, 'setCustomFieldData')) {
            // No try/catch: a malformed cell cannot throw (the trait casts with
            // a total (string) cast), so the only realistic exception is a
            // persistence error, which MUST fail the row rather than commit the
            // record with its added columns missing.
            $record->setCustomFieldData($customData, false);
        }
    }

    /**
     * The columns as they ship, before this repository's own column names and
     * its own added columns are applied.
     *
     * @return array<ImportColumn>
     */
    protected static function getStaticColumns(): array
    {
        return [
            // The resolution key the document sheet references
            // (documents."Document Type" → document_type_id). Optional: a
            // vocabulary can be identified by name alone.
            ImportColumn::make('identifier')
                ->label('Identifier (code — used to link documents)')
                ->guess(['Identifier', 'Code', 'identifier', 'code', 'Document Type Code'])
                ->castStateUsing(function (?string $state): ?string {
                    $candidate = $state !== null ? trim($state) : '';

                    return $candidate === '' ? null : mb_substr($candidate, 0, 64);
                })
                ->rules(['nullable', 'string', 'max:64']),

            ImportColumn::make('name')
                ->label('Name')
                ->requiredMappingForNewRecordsOnly()
                ->guess(['Name', 'name', 'Document Type', 'Type', 'Title'])
                ->rules(['required', 'string', 'max:100']),

            ImportColumn::make('description')
                ->label('Description')
                ->guess(['Description', 'description', 'Notes'])
                ->rules(['nullable', 'string', 'max:65535']),

            ImportColumn::make('is_active')
                ->label('Is active?')
                ->guess(['Is active', 'Active', 'is_active'])
                ->boolean()
                ->rules(['nullable', 'boolean']),
        ];
    }

    /**
     * The columns this repository added itself, for the 'documentType' entity type.
     *
     * Client 2026-09-25: she asked for standalone columns on the remaining
     * entities too. Guessed by the definition label, the bare key and the
     * cf_{key} form, so either the readable name or the internal key works as
     * a header.
     *
     * A bad cell must never fail the row: the value is stored as given and the
     * trait casts it on read.
     *
     * @return array<ImportColumn>
     */
    protected static function getCustomFieldColumns(): array
    {
        /** @var Collection<int, CustomFieldDefinition> $defs */
        $defs = CustomFieldResolver::definitionsFor('documentType');

        if ($defs->isEmpty()) {
            return [];
        }

        $columns = [];
        foreach ($defs as $def) {
            $columns[] = ImportColumn::make('custom_field_' . $def->key)
                ->label($def->label . ' (custom field)')
                ->guess([$def->label, $def->key, 'cf_' . $def->key])
                ->rules(['nullable', 'string'])
                ->fillRecordUsing(static function (DocumentType $record, ?string $state) use ($def): void {
                    $key = spl_object_id($record);
                    // Always stash: null/empty means "clear this field". A column
                    // that was never mapped does not reach this closure at all,
                    // and is therefore left untouched.
                    static::$rowCustomFieldStash[$key][$def->key] = ($state !== null && trim($state) !== '')
                        ? trim($state)
                        : null;
                });
        }

        return $columns;
    }
}
