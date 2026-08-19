<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\LogsImportRows;
use App\Filament\Imports\Concerns\SkipsExistingRows;
use App\Models\DocumentType;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

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
    use SkipsExistingRows;

    protected static ?string $model = DocumentType::class;

    /**
     * @return array<ImportColumn>
     */
    public static function getColumns(): array
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
}
