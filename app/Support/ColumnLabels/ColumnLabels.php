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
