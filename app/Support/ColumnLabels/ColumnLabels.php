<?php

declare(strict_types=1);

namespace App\Support\ColumnLabels;

use App\Models\ColumnLabelOverride;
use App\Models\CustomFieldDefinition;
use App\Support\CustomFields\CustomFieldResolver;

/**
 * The name a built-in column goes by.
 *
 * Client request 2026-09-24: the cataloguer had renamed Authority columns three
 * times in nine days, each one a code change and a deploy. Adding columns was
 * already hers to do through custom fields; renaming a built-in one was not.
 *
 * This resolves a field key to the label the operator sees — in the downloadable
 * template, on the form, in the record view, in the table, and in the importer's
 * column guess — falling back to the factory name in DEFAULTS when no override
 * exists.
 *
 * Only labels are configurable. The field key, its type and its validation stay
 * in code, so a rename cannot change what a column means or which rules it
 * obeys. That is the whole reason it is safe to hand over.
 */
final class ColumnLabels
{
    /**
     * The factory names, per entity, in template order.
     *
     * This is the single source of truth for what a renameable column is
     * called before anyone renames it, and for which fields may be renamed at
     * all: a key absent from here cannot be overridden, by design — the
     * cataloguer should not be able to rename something the code reads back by
     * label, only the columns that are genuinely independent.
     *
     * @var array<string, array<string, string>>
     */
    public const array DEFAULTS = [
        'authority' => [
            'identifier' => 'NAM Authority Reference Code',
            'alternative_identifier' => 'Citing Reference Code',
            'alternative_identifier_warrant' => 'Alternate Reference Code',
            'previous_temporary_identifiers' => 'Past Reference Code',
            'entity_type' => 'Type of Entity',
            'practice_dates_active' => 'Private Practice Dates Active',
            'ntg_dates_active' => 'NTG Dates Active',
            'name_suffix' => 'Name Suffix',
            'maiden_surname' => 'Maiden Surname',
            'surname' => 'Creator Surname',
            'given_names' => 'Creator Name',
            'authorised_form_of_name' => 'Authorised form of name',
            'functions_occupations_activities' => 'Functions, occupations and activities',
            'level_of_detail' => 'Level of detail',
            'status' => 'Status',
            'rules_and_conventions' => 'Rules and/or conventions',
            'date_of_creation' => 'Date of creation',
            'creator_of_record' => 'Creator of record',
            'notes' => 'Notes',
        ],
        'series' => [
            'code' => 'Identifier',
            'title' => 'Standard title in English (Plural)',
            'level_of_description' => 'Level of description',
            'parent_code' => 'Parent',
            'date_of_creation' => 'Date of creation',
            'name_of_inputter' => 'Name of Inputter',
            'repository_code' => 'Repository',
        ],
        'batch' => [
            'batch_number' => 'batch_number',
            'description' => 'description',
            'type' => 'type',
            'is_active' => 'is_active',
            'repository_code' => 'repository_code',
        ],
        'box' => [
            'box_type' => 'box_type',
            'box_number' => 'box_number',
            'batch_number' => 'batch_number',
            'parent_barcode' => 'parent_box_number',
            'barcode' => 'barcode',
            'barcode_status' => 'barcode_status',
            'is_legacy' => 'is_legacy',
            'provenance_unknown' => 'Provenance Unknown',
            'notes' => 'notes',
            'tracking_note' => 'Tracking Note',
            'seal_number' => 'Seal Number',
            'destroyed' => 'Destroyed',
            'current_box_type' => 'Current Box Type',
        ],
        'location' => [
            'name' => 'name',
            'type' => 'type',
            'parent_name' => 'parent_name',
            'repository_code' => 'repository_code',
            'code' => 'code',
            'notes' => 'notes',
            'sort_order' => 'sort_order',
            'is_active' => 'is_active',
        ],
        'documentType' => [
            'identifier' => 'Identifier',
            'name' => 'Name',
            'description' => 'Description',
            'is_active' => 'Is active',
        ],
        'document' => [
            'batch_number' => 'RAS Batch 1',
            'current_box_number' => 'RAS Box 1',
            'ras_batch_2' => 'RAS Batch 2',
            'ras_box_2' => 'RAS Box 2',
            'in_situ_box_1' => 'In Situ Box 1',
            'in_situ_box_2' => 'In Situ Box 2',
            'in_situ_box_3' => 'In Situ Box 3',
            'barcode_ras_3' => 'Barcode RAS 3',
            'status_3' => 'Status 3',
            'barcode_ras_4' => 'Barcode RAS 4',
            'status_4' => 'Status 4',
            'catalogue_identifier' => 'Catalogue Identifier',
            'location' => 'NRA Location',
            'museum_location' => 'Museum Location',
            'practice' => 'Practice',
            'volume_number' => 'Volume',
            'creator_legacy_text' => 'Creator',
            'dates' => 'Dates',
            'deeds' => 'Deeds',
            'document_type' => 'Document Type',
            'series' => 'Subseries',
            'notes' => 'Note',
            'digitised' => 'Digitised',
            'torre' => 'Torre',
            'accession_code_legacy' => 'Accession',
            'object_reference_number' => 'Conservation Object Reference Number',
            'tracking' => 'Tracking',
            'museum_reference' => 'Museum Reference',
            'temporary_identifier' => 'Temporary Identifier',
            'citation_reference' => 'Citation Reference',
            'prev_attributed_identifier' => 'Prev Attributed Identifier',
            'prev_attributed_volume' => 'Prev Attributed Volume',
            'part_number' => 'Part Number',
        ],
        'volume' => [
            'document_identifier' => 'document_identifier',
            'volume_number' => 'volume_number',
            'dates_start' => 'dates_start',
            'dates_end' => 'dates_end',
            'notes' => 'notes',
        ],
        'accession' => [
            'authority_identifier' => 'Authority Identifier',
            'authority_name' => 'Authority Name',
            'authority_surname' => 'Authority Surname',
            'accession_number' => 'Accession Number',
            'accession_type' => 'Accession Type',
            'repository' => 'Repository',
            'batch_number' => 'Batch Number',
            'box_number' => 'Box No',
            'box_barcode' => 'Box Barcode',
            'current_box_type' => 'Current Box Type',
            'document_identifier' => 'Document Identifier',
            'document_type' => 'Document Type',
            'series' => 'Subseries',
            'volume_number' => 'Volume No',
            'part_number' => 'Part Number',
            'practice' => 'Practice',
            'dates' => 'Dates',
            'deeds' => 'Deeds',
            'number_of_acts' => 'No of Acts',
            'pages_folios' => 'Pages/Folios',
            'notes' => 'Note',
        ],
    ];

    /**
     * What each entity is called in the interface.
     *
     * Not cosmetic: ucfirst() on the key gives "Series" and "DocumentType",
     * while the archivist's sidebar says "Subseries" and "Document Type". Asking
     * her to rename a column on "Series" when nothing else in the app uses that
     * word is how a working feature goes unused.
     *
     * @var array<string, string>
     */
    public const array ENTITY_LABELS = [
        'authority' => 'Authority',
        'series' => 'Subseries',
        'batch' => 'Batch',
        'box' => 'Box',
        'location' => 'Location',
        'documentType' => 'Document Type',
        'document' => 'Document',
        'volume' => 'Volume',
        'accession' => 'Notary Accession',
    ];

    /**
     * Request-level memo, keyed "{repoId}:{entityType}". Cleared by
     * {@see flushMemo()} in tests and between queue jobs.
     *
     * @var array<string, array<string, string>>
     */
    private static array $memo = [];

    /** Entities whose built-in columns can be renamed from the admin. */
    public static function renameableEntities(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /** What one entity is called in the interface. */
    public static function entityLabel(string $entityType): string
    {
        return self::ENTITY_LABELS[$entityType] ?? ucfirst($entityType);
    }

    /**
     * Renameable entities as a Select's options: key => interface name.
     *
     * @return array<string, string>
     */
    public static function renameableEntityOptions(): array
    {
        $options = [];
        foreach (self::renameableEntities() as $entity) {
            $options[$entity] = self::entityLabel($entity);
        }

        return $options;
    }

    /**
     * Every label for an entity, field key => label, in template order.
     *
     * @return array<string, string>
     */
    public static function all(string $entityType): array
    {
        $defaults = self::DEFAULTS[$entityType] ?? [];
        if ($defaults === []) {
            return [];
        }

        $repoId = CustomFieldResolver::activeRepositoryId();
        $memoKey = ($repoId ?? 'none') . ':' . $entityType;

        if (array_key_exists($memoKey, self::$memo)) {
            return self::$memo[$memoKey];
        }

        $labels = $defaults;

        if ($repoId !== null) {
            $overrides = ColumnLabelOverride::query()
                ->where('repository_id', $repoId)
                ->where('entity_type', $entityType)
                ->pluck('label', 'field_key')
                ->all();

            foreach ($overrides as $key => $label) {
                // An override for a key that is no longer renameable is
                // ignored rather than appended: the field may have been
                // removed from DEFAULTS since, and a stray row must not
                // introduce a column nothing reads.
                if (array_key_exists($key, $labels) && filled($label)) {
                    $labels[$key] = (string) $label;
                }
            }
        }

        return self::$memo[$memoKey] = $labels;
    }

    /**
     * The same headers, with every renamed column substituted in place.
     *
     * Used by the template for every entity EXCEPT authority, whose header list
     * is defined by DEFAULTS itself. The other templates cannot be regenerated
     * from the labels: the document template repeats headers on purpose ("Barcode
     * (IN)" twice, "Disinfestation Date" three times) because the cataloguers
     * read that legacy layout by column POSITION, and rebuilding the row from a
     * key => label map would collapse the repeats and shift every column after
     * them. So the factory header is replaced where it sits, and the row keeps
     * its shape.
     *
     * A header that is not unique in the row is never substituted, even if the
     * field is listed in DEFAULTS: renaming one of three identical cells would
     * leave a row that claims two different things about the same column. Those
     * fields are kept out of DEFAULTS for that reason, and this is the guard
     * that holds if one is ever added back by mistake.
     *
     * @param list<string> $headers
     * @return list<string>
     */
    public static function applyToHeaders(string $entityType, array $headers): array
    {
        $defaults = self::DEFAULTS[$entityType] ?? [];
        if ($defaults === []) {
            return $headers;
        }

        $labels = self::all($entityType);
        $counts = array_count_values($headers);

        foreach ($defaults as $fieldKey => $factoryHeader) {
            $current = $labels[$fieldKey] ?? $factoryHeader;
            if ($current === $factoryHeader) {
                continue;
            }
            if (($counts[$factoryHeader] ?? 0) !== 1) {
                continue;
            }
            $position = array_search($factoryHeader, $headers, true);
            if ($position !== false) {
                $headers[$position] = $current;
            }
        }

        return $headers;
    }

    /** The label for one field, or the key itself if it is not renameable. */
    public static function get(string $entityType, string $fieldKey): string
    {
        return self::all($entityType)[$fieldKey] ?? $fieldKey;
    }

    /**
     * The labels in template order, as a plain list of header strings.
     *
     * @return list<string>
     */
    public static function headers(string $entityType): array
    {
        return array_values(self::all($entityType));
    }

    /**
     * Would calling $fieldKey "$label" collide with another column's header?
     *
     * Two columns sharing a header is the one failure the cataloguer cannot
     * see: the template still opens, every column is still there, and the
     * importer keeps one of the two — so a filled-in column is discarded, or
     * worse, lands on the wrong field. Cheaper to refuse the name.
     *
     * Compared case-insensitively and ignoring surrounding space, because
     * "Status" and "status " are the same column to anyone reading the sheet.
     *
     * @param int|null $repositoryId the repository the rename belongs to, which is
     *                               not necessarily the active one
     */
    public static function clashesWith(string $entityType, string $fieldKey, string $label, ?int $repositoryId): bool
    {
        $normalise = static fn (string $v): string => mb_strtolower(trim(preg_replace('/\s+/', ' ', $v) ?? $v));
        $wanted = $normalise($label);

        if ($wanted === '') {
            return false;
        }

        // Every other built-in column, under the name it currently carries in
        // THIS repository — not the active one, which may be a different tenant.
        $overrides = $repositoryId === null ? [] : ColumnLabelOverride::query()
            ->where('repository_id', $repositoryId)
            ->where('entity_type', $entityType)
            ->pluck('label', 'field_key')
            ->all();

        foreach (self::DEFAULTS[$entityType] ?? [] as $key => $factoryLabel) {
            if ($key === $fieldKey) {
                continue;
            }
            $current = filled($overrides[$key] ?? null) ? (string) $overrides[$key] : $factoryLabel;
            if ($normalise($current) === $wanted) {
                return true;
            }
        }

        // And the columns she added herself, which share the header row.
        if ($repositoryId !== null) {
            $added = CustomFieldDefinition::query()
                ->where('repository_id', $repositoryId)
                ->where('entity_type', $entityType)
                ->where('is_active', true)
                ->pluck('label')
                ->all();

            foreach ($added as $addedLabel) {
                if ($normalise((string) $addedLabel) === $wanted) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Drop the memo. Tests change overrides mid-run; queue workers are long-lived. */
    public static function flushMemo(): void
    {
        self::$memo = [];
    }
}
