<?php

declare(strict_types=1);

namespace App\Support\Export;

use App\Filament\Pages\ImportWizard;
use App\Models\Authority;
use App\Models\Box;
use App\Models\CustomFieldDefinition;
use App\Models\Document;
use App\Models\DocumentIdentifierHistory;
use App\Models\Repository;
use App\Support\BulkImport\SpreadsheetHeaders;
use App\Support\BulkImport\TemplateGenerator;
use App\Support\CustomFields\CustomFieldCsv;
use App\Support\CustomFields\CustomFieldResolver;
use App\Support\FieldPermissions;
use App\Support\Reports\ReportRenderer;
use Closure;
use DateTimeInterface;
use Filament\Actions\Imports\ImportColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One export per record type, shaped exactly like that type's import template.
 *
 * The header row IS the template's header row — TemplateGenerator::headersFor(),
 * so a repository's renamed columns and added columns come with it — and each
 * column is read back to the importer field it belongs to with the same
 * ImportWizard::guessColumnMap() the import uses. Export, edit, re-import: the
 * file maps onto the same fields with no remapping and nothing is lost.
 *
 * Before this, the four list exports had hand-written header lists of 8 to 11
 * columns: a box export carried 8 of the template's 13 columns, ignored the
 * repository's renames, and dropped the added columns whenever "All
 * repositories" was selected. Re-importing one silently lost the rest.
 *
 * Most columns are a plain attribute of the model. The few that are not —
 * a parent read through a relation, a year range split over two columns, a
 * value the importer merges into another field — are listed in derived().
 */
final class EntityExport
{
    /**
     * Rows read per query while streaming. The export never holds the whole
     * result: 26,000 documents stream through in pages of this size.
     */
    private const int CHUNK = 500;

    /** @var array<string, list<string>> table => its columns, read once per request */
    private static array $tableColumns = [];

    /** @var array<int, string|null> repository id => code, read once per request */
    private static array $repositoryCodes = [];

    /**
     * Template entity (TemplateGenerator key) => importer class.
     *
     * @return array<string, class-string>
     */
    public static function importers(): array
    {
        $importers = [];
        foreach (ImportWizard::TEMPLATE_KEYS as $importKey => $entity) {
            $importers[$entity] = ImportWizard::IMPORTERS[$importKey];
        }

        return $importers;
    }

    /**
     * The header row, in order, exactly as the import template writes it, plus
     * the record key columns the template leaves out.
     *
     * @return list<string>
     */
    public static function headers(string $entity): array
    {
        $headers = TemplateGenerator::headersFor($entity);

        // The documents template has no column for the document's own
        // identifier: on a NEW sheet it is optional and auto-created. An export
        // without it would re-import as a fresh copy of every document instead
        // of updating them, so it goes first.
        if ($entity === 'document') {
            array_unshift($headers, 'Document Identifier');
        }

        // Fields the importer reads that the template has no column for, but
        // that are real data on the record ("No of Acts", a box's location, a
        // subseries' description). Left out, editing an export and importing it
        // back would be the only way to lose them without being told.
        foreach (self::extraHeaders($entity, $headers) as $header) {
            $headers[] = $header;
        }

        // In "All repositories" no repository's added columns are active, so the
        // template has none — but the records still carry their values. Append
        // every added column defined for this type, once per key.
        if (CustomFieldResolver::activeRepositoryId() === null) {
            $headers = array_merge($headers, TemplateGenerator::customFieldHeaders($entity, self::allRepositoryDefinitions($entity), $headers));
        }

        return array_values($headers);
    }

    /**
     * Header position => importer field, read back through the importer's own
     * guesser on the de-duplicated header row (the documents sheet repeats
     * "Barcode (IN)" and friends; position decides which field each one is).
     *
     * @param list<string> $headers
     * @return list<string|null>
     */
    public static function fieldsFor(string $entity, array $headers): array
    {
        $importer = self::importers()[$entity];
        $deduped = SpreadsheetHeaders::dedupe($headers);
        $map = ImportWizard::guessColumnMap($importer, $deduped);

        $byHeader = [];
        foreach ($map as $field => $header) {
            if ($header !== null) {
                $byHeader[$header] = $field;
            }
        }

        $fields = [];
        foreach ($deduped as $header) {
            $fields[] = $byHeader[$header] ?? self::customFieldFor($entity, (string) $header);
        }

        return $fields;
    }

    /**
     * Stream the records of $query as a CSV shaped like the import template.
     *
     * @param Builder<covariant Model> $query the records to export, already filtered
     */
    public static function stream(string $entity, Builder $query, string $filenameStem): StreamedResponse
    {
        [$headers, $fields] = self::columns($entity);
        $definitions = self::definitionsByKey($entity);
        // A code renamed since the last export must not come back stale.
        self::$repositoryCodes = [];

        $with = array_merge(self::relationsFor($entity), $definitions === [] ? [] : ['customFieldValues.definition']);
        $query->with($with);

        $filename = sprintf('%s_%s.csv', Str::slug($filenameStem, '_'), now()->format('Ymd_His'));

        return response()->streamDownload(function () use ($query, $headers, $fields, $entity, $definitions): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            // UTF-8 BOM so Excel opens accented names (Maltese ħ, ż, ċ, għ) correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, escape: '\\');

            $query->chunkById(self::CHUNK, function (EloquentCollection $records) use ($out, $fields, $entity, $definitions): void {
                foreach ($records as $record) {
                    $row = [];
                    foreach ($fields as $field) {
                        $row[] = ReportRenderer::sanitizeCsvCell(self::value($entity, $record, $field, $definitions));
                    }
                    fputcsv($out, $row, escape: '\\');
                }
            }, $query->getModel()->getQualifiedKeyName(), $query->getModel()->getKeyName());

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * The header row and the field behind each column, minus every column the
     * current user may not read (RFQ §3.1.4). A hidden field leaves the file
     * entirely — header included — exactly as it is absent from the screen.
     *
     * @return array{0: list<string>, 1: list<string|null>}
     */
    public static function columns(string $entity): array
    {
        $headers = self::headers($entity);
        $fields = self::fieldsFor($entity, $headers);

        $keptHeaders = [];
        $keptFields = [];
        foreach ($headers as $i => $header) {
            if (self::readable($entity, $fields[$i] ?? null)) {
                $keptHeaders[] = $header;
                $keptFields[] = $fields[$i] ?? null;
            }
        }

        return [$keptHeaders, $keptFields];
    }

    /**
     * One cell, formatted the way the importer reads it back.
     *
     * @param array<string, CustomFieldDefinition> $definitions
     */
    public static function value(string $entity, Model $record, ?string $field, array $definitions = []): string
    {
        if ($field === null) {
            return '';
        }

        if (str_starts_with($field, 'custom_field_')) {
            return self::customValue($record, substr($field, strlen('custom_field_')), $definitions);
        }

        $derived = self::derived()[$entity][$field] ?? null;
        $value = $derived instanceof Closure ? $derived($record) : $record->getAttribute($field);

        return self::format($value);
    }

    /**
     * One header per importer field the template does not cover and whose
     * value is stored on the record itself. Each header is one the importer
     * maps back to that same field — checked, not assumed — so the extra
     * columns survive a round trip exactly like the template's own.
     *
     * Fields with no storage of their own (a lookup such as "Current box
     * barcode", or the accession's box type) are left out: there is nothing on
     * the record to write in them.
     *
     * @param list<string> $headers
     * @return list<string>
     */
    private static function extraHeaders(string $entity, array $headers): array
    {
        $importer = self::importers()[$entity];
        $model = $importer::getModel();
        $table = (new $model)->getTable();
        self::$tableColumns[$table] ??= Schema::getColumnListing($table);

        $covered = array_filter(self::fieldsFor($entity, $headers));
        $extra = [];
        foreach ($importer::getColumns() as $column) {
            $field = $column->getName();
            $storage = self::storageColumn()[$entity][$field] ?? $field;
            if (in_array($field, $covered, true)
                || str_starts_with($field, 'custom_field_')
                || ! in_array($storage, self::$tableColumns[$table], true)) {
                continue;
            }

            $header = self::headerFor($importer, $column, $field, [...$headers, ...$extra]);
            if ($header !== null) {
                $extra[] = $header;
            }
        }

        return $extra;
    }

    /**
     * The first of the field's own guesses — then its label — that the
     * importer, given the rest of the header row, hands back to that field.
     *
     * @param class-string $importer
     * @param list<string> $headers
     */
    private static function headerFor(string $importer, ImportColumn $column, string $field, array $headers): ?string
    {
        /** @var list<string> $guesses */
        $guesses = (fn (): array => (array) $this->evaluate($this->guesses))->call($column);
        $taken = array_map('mb_strtolower', $headers);

        foreach ([...$guesses, $column->getLabel()] as $candidate) {
            if ($candidate === '' || in_array(mb_strtolower($candidate), $taken, true)) {
                continue;
            }
            $map = ImportWizard::guessColumnMap($importer, SpreadsheetHeaders::dedupe([...$headers, $candidate]));
            if (($map[$field] ?? null) === $candidate) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * A column is readable when the user may read both the importer field and
     * the model column the value actually lives in: a derived column ("RAS
     * Batch 1" → batch_id, "Creator" → extra) is checked against its storage,
     * so a permission set on the column cannot be bypassed through its export.
     */
    private static function readable(string $entity, ?string $field): bool
    {
        if ($field === null) {
            return true;
        }

        $resource = self::permissionResource($entity);
        if (str_starts_with($field, 'custom_field_')) {
            return FieldPermissions::canRead($resource, 'custom_fields');
        }

        $storage = self::storageColumn()[$entity][$field] ?? null;

        return FieldPermissions::canRead($resource, $field)
            && ($storage === null || FieldPermissions::canRead($resource, $storage));
    }

    private static function permissionResource(string $entity): string
    {
        return $entity === 'accession' ? 'document' : Str::snake($entity);
    }

    /**
     * Derived column => the model column its value is stored in.
     *
     * @return array<string, array<string, string>>
     */
    private static function storageColumn(): array
    {
        return [
            'authority' => ['practice_dates_active' => 'practice_dates_start', 'ntg_dates_active' => 'ntg_dates_start', 'name_suffix' => 'given_names', 'maiden_surname' => 'notes'],
            'series' => ['parent_code' => 'parent_id', 'repository_code' => 'repository_id'],
            'batch' => ['repository_code' => 'repository_id'],
            'location' => ['parent_name' => 'parent_id', 'repository_code' => 'repository_id'],
            'box' => ['batch_number' => 'batch_id', 'parent_barcode' => 'parent_box_id', 'destroyed' => 'destroyed_at', 'location' => 'location_id'],
            'volume' => ['document_identifier' => 'document_id'],
            'document' => [
                'batch_number' => 'batch_id', 'current_box_number' => 'current_box_id', 'location' => 'location_id',
                'series' => 'series_id', 'authority_identifier' => 'authorities', 'creator_legacy_text' => 'extra',
                'prev_attributed_identifier' => 'identifier', 'prev_attributed_volume' => 'volume_number',
                'ras_box_1_destroyed' => 'ras_1_box_destroyed', 'ras_box_2_destroyed' => 'ras_2_box_destroyed',
            ],
            'accession' => [
                'authority_identifier' => 'authorities', 'authority_name' => 'authorities', 'authority_surname' => 'authorities',
                'accession_number' => 'accession_id', 'accession_title' => 'accession_id', 'accession_type' => 'batch_id',
                'repository' => 'repository_id', 'batch_number' => 'batch_id', 'box_number' => 'current_box_id',
                'box_barcode' => 'current_box_id', 'box_barcode_status' => 'current_box_id', 'document_identifier' => 'identifier',
                'series' => 'series_id',
            ],
        ];
    }

    /**
     * The columns that are not a plain attribute of the model, and how each is
     * read back. Everything else is $record->getAttribute($field).
     *
     * @return array<string, array<string, Closure(Model): mixed>>
     */
    private static function derived(): array
    {
        $yearRange = static fn (mixed $start, mixed $end): ?string => match (true) {
            $start === null && $end === null => null,
            $end === null || $end === $start => (string) $start,
            $start === null => (string) $end,
            default => $start . '-' . $end,
        };

        return [
            'authority' => [
                'practice_dates_active' => static fn (Model $r): ?string => $yearRange($r->getAttribute('practice_dates_start'), $r->getAttribute('practice_dates_end')),
                'ntg_dates_active' => static fn (Model $r): ?string => $yearRange($r->getAttribute('ntg_dates_start'), $r->getAttribute('ntg_dates_end')),
                // The importer APPENDS these to given_names and notes. Their
                // values are already in those exported columns; writing them
                // here too would append them a second time on every re-import.
                'name_suffix' => static fn (): ?string => null,
                'maiden_surname' => static fn (): ?string => null,
            ],
            'series' => [
                'parent_code' => static fn (Model $r): mixed => $r->getRelationValue('parent')?->getAttribute('code'),
                'repository_code' => static fn (Model $r): mixed => self::repositoryCode($r),
            ],
            'batch' => [
                'repository_code' => static fn (Model $r): mixed => self::repositoryCode($r),
            ],
            'location' => [
                'parent_name' => static fn (Model $r): mixed => $r->getRelationValue('parent')?->getAttribute('name'),
                'repository_code' => static fn (Model $r): mixed => self::repositoryCode($r),
            ],
            'box' => [
                'batch_number' => static fn (Model $r): mixed => $r->getRelationValue('batch')?->getAttribute('batch_number'),
                // The importer resolves the parent by barcode first, then by number.
                'parent_barcode' => static function (Model $r): mixed {
                    $parent = $r->getRelationValue('parent');

                    return $parent === null ? null : ($parent->getAttribute('barcode') ?: $parent->getAttribute('box_number'));
                },
                'destroyed' => static fn (Model $r): string => $r->getAttribute('destroyed_at') !== null ? 'Yes' : 'No',
                'location' => static fn (Model $r): mixed => $r->getRelationValue('location')?->getAttribute('code'),
            ],
            'volume' => [
                'document_identifier' => static fn (Model $r): mixed => $r->getRelationValue('document')?->getAttribute('identifier'),
            ],
            'document' => [
                'batch_number' => static fn (Model $r): mixed => $r->getRelationValue('batch')?->getAttribute('batch_number'),
                'current_box_number' => static fn (Model $r): mixed => $r->getRelationValue('currentBox')?->getAttribute('box_number'),
                'location' => static fn (Model $r): mixed => $r->getRelationValue('location')?->getAttribute('code'),
                'series' => static fn (Model $r): mixed => $r->getRelationValue('series')?->getAttribute('code'),
                'authority_identifier' => static fn (Model $r): ?string => self::joinList(
                    $r->getRelationValue('authorities')?->map(fn (Authority $a): mixed => $a->getAttribute('identifier'))->all() ?? [],
                ),
                // The free-text Creator cell as it arrived, kept by the importer.
                'creator_legacy_text' => static fn (Model $r): mixed => $r instanceof Document ? $r->extra->get('legacy_creator_text') : null,
                'prev_attributed_identifier' => static fn (Model $r): mixed => self::previousAttribution($r)?->getAttribute('previous_identifier'),
                'prev_attributed_volume' => static fn (Model $r): mixed => self::previousAttribution($r)?->getAttribute('previous_volume'),
                // The sheet's "RAS 1/2 Box Destroyed" cells, as the document keeps them.
                'ras_box_1_destroyed' => static fn (Model $r): mixed => $r->getAttribute('ras_1_box_destroyed'),
                'ras_box_2_destroyed' => static fn (Model $r): mixed => $r->getAttribute('ras_2_box_destroyed'),
            ],
            // The accession sheet is one row per DOCUMENT: the record here is a
            // Document, and the accession, batch and box columns are read from
            // its relations. Authorities travel as ";"-separated lists paired
            // by position, which is how the importer splits them back.
            'accession' => [
                'authority_identifier' => static fn (Model $r): ?string => self::authorityList($r, 'identifier'),
                'authority_name' => static fn (Model $r): ?string => self::authorityList($r, 'given_names'),
                'authority_surname' => static fn (Model $r): ?string => self::authorityList($r, 'surname'),
                'accession_number' => static fn (Model $r): mixed => $r->getRelationValue('accession')?->getAttribute('accession_number'),
                // The importer stores the title as the accession's code.
                'accession_title' => static fn (Model $r): mixed => $r->getRelationValue('accession')?->getAttribute('code'),
                'accession_type' => static fn (Model $r): mixed => $r->getRelationValue('batch')?->getAttribute('type'),
                'repository' => static fn (Model $r): mixed => self::repositoryCode($r),
                'batch_number' => static fn (Model $r): mixed => $r->getRelationValue('batch')?->getAttribute('batch_number'),
                'box_number' => static fn (Model $r): mixed => $r->getRelationValue('currentBox')?->getAttribute('box_number'),
                'box_barcode' => static fn (Model $r): mixed => $r->getRelationValue('currentBox')?->getAttribute('barcode'),
                'box_barcode_status' => static fn (Model $r): mixed => $r->getRelationValue('currentBox')?->getAttribute('barcode_status'),
                'document_identifier' => static fn (Model $r): mixed => $r->getAttribute('identifier'),
                'series' => static fn (Model $r): mixed => $r->getRelationValue('series')?->getAttribute('code'),
            ],
        ];
    }

    /**
     * Relations the derived columns read, eager-loaded so a 26,000-row export
     * does not issue one query per cell.
     *
     * @return list<string>
     */
    private static function relationsFor(string $entity): array
    {
        return match ($entity) {
            'series' => ['parent', 'repository'],
            'batch' => ['repository'],
            'location' => ['parent', 'repository'],
            'box' => ['batch', 'parent', 'location'],
            'volume' => ['document'],
            'document' => ['batch', 'currentBox', 'location', 'series', 'authorities', 'identifierHistory'],
            'accession' => ['accession', 'batch', 'currentBox', 'series', 'authorities'],
            default => [],
        };
    }

    private static function format(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'Yes' : 'No',
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            $value instanceof \BackedEnum => (string) $value->value,
            is_array($value) => (string) json_encode($value),
            default => (string) $value,
        };
    }

    /**
     * Read once per repository, not once per row: a handful of repositories
     * against tens of thousands of exported records.
     */
    private static function repositoryCode(Model $record): mixed
    {
        $repositoryId = $record->getAttribute('repository_id');
        if ($repositoryId === null) {
            return null;
        }

        if (! array_key_exists((int) $repositoryId, self::$repositoryCodes)) {
            self::$repositoryCodes[(int) $repositoryId] = Repository::query()->whereKey($repositoryId)->value('code');
        }

        return self::$repositoryCodes[(int) $repositoryId];
    }

    /**
     * @param array<int, mixed> $values
     */
    private static function joinList(array $values): ?string
    {
        $values = array_values(array_filter($values, static fn ($v): bool => $v !== null && $v !== ''));

        return $values === [] ? null : implode('; ', $values);
    }

    /**
     * One attribute of every authority of a document, ";"-separated, always in
     * the same order so identifiers, names and surnames stay paired.
     */
    private static function authorityList(Model $record, string $attribute): ?string
    {
        /** @var EloquentCollection<int, Authority>|null $authorities */
        $authorities = $record->getRelationValue('authorities');
        if ($authorities === null || $authorities->isEmpty()) {
            return null;
        }

        return implode('; ', $authorities->sortBy('id')->map(fn (Authority $a): string => (string) $a->getAttribute($attribute))->all());
    }

    private static function previousAttribution(Model $record): ?DocumentIdentifierHistory
    {
        /** @var EloquentCollection<int, DocumentIdentifierHistory>|null $history */
        $history = $record->getRelationValue('identifierHistory');

        return $history?->firstWhere('reason', 'Imported — previously attributed');
    }

    /**
     * @param array<string, CustomFieldDefinition> $definitions
     */
    private static function customValue(Model $record, string $key, array $definitions): string
    {
        $definition = $definitions[$key] ?? null;
        if ($definition === null || ! $record->relationLoaded('customFieldValues')) {
            return '';
        }

        // The record's OWN definition for this key: in "All repositories" two
        // repositories can define the same key, and each record answers to the
        // one of its repository.
        $value = $record->getRelationValue('customFieldValues')
            ->first(fn (Model $v): bool => $v->getRelationValue('definition')?->getAttribute('key') === $key);

        if ($value === null) {
            return '';
        }

        /** @var CustomFieldDefinition $own */
        $own = $value->getRelationValue('definition');

        return CustomFieldCsv::format($own, $value->getAttribute('typed_value') ?? $value->getAttribute('value'));
    }

    /**
     * @return array<string, CustomFieldDefinition>
     */
    private static function definitionsByKey(string $entity): array
    {
        $type = $entity === 'accession' ? 'document' : $entity;
        $definitions = CustomFieldResolver::activeRepositoryId() === null
            ? self::allRepositoryDefinitions($entity)
            : CustomFieldResolver::definitionsFor($type)->all();

        $byKey = [];
        foreach ($definitions as $definition) {
            $byKey[$definition->key] ??= $definition;
        }

        return $byKey;
    }

    /**
     * @return list<CustomFieldDefinition>
     */
    private static function allRepositoryDefinitions(string $entity): array
    {
        $type = $entity === 'accession' ? 'document' : $entity;

        return CustomFieldDefinition::query()
            ->where('entity_type', $type)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->unique('key')
            ->values()
            ->all();
    }

    private static function customFieldFor(string $entity, string $header): ?string
    {
        foreach (self::definitionsByKey($entity) as $key => $definition) {
            if ($definition->label === $header || 'cf_' . $key === $header) {
                return 'custom_field_' . $key;
            }
        }

        return null;
    }
}
