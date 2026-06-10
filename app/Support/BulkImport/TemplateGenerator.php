<?php

declare(strict_types=1);

namespace App\Support\BulkImport;

use App\Filament\Imports\BatchImporter;
use App\Filament\Imports\BoxImporter;
use App\Support\CustomFields\CustomFieldResolver;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RFQ §3.1.3 — Generate blank .xlsx templates whose **column headers match
 * the legacy sample files** byte-for-byte (strings, ordering, and duplicates).
 *
 * Why this lives next to {@see EntityResolver}:
 *
 * The operator's brief was unambiguous — the spreadsheet column headers in
 * the official samples (`Authorities_Sample.xlsx`, `Series_Sample.xlsx`,
 * `Batch_List_Sample.xlsx`) are the contract. Operators have spent years
 * copy/pasting into those exact columns; the template MUST preserve them
 * verbatim so existing muscle memory keeps working.
 *
 * Two awkward edge cases the legacy schema imposes on us:
 *
 *   1. `Series_Sample.xlsx` has 26 columns in its header row but only the
 *      first 6 are populated — the rest are stray NULL cells dragged in by
 *      whoever first saved the file in Excel. We trim trailing nulls so the
 *      generated template doesn't ship 20 empty column slots.
 *
 *   2. `Batch_List_Sample.xlsx` has 57 columns of which 49 are populated,
 *      and several header strings are **legitimately duplicated** at the
 *      same byte position — multi-step provenance tracking:
 *
 *          [13] "Barcode (IN)"        [22] "Barcode (IN)"
 *          [15] "Status 1"            [24] "Status 1"
 *          [16] "Barcode RAS 2"       [23] "Barcode RAS 2"   [25] "Barcode RAS 2"
 *          [17] "Status 2"            [26] "Status 2"
 *          [28] "Disinfestation Date" [29] "Disinfestation Date" [30] "Disinfestation Date"
 *
 *      Those duplicates are part of the legacy schema — they encode
 *      "first barcode at acquisition" vs. "barcode after first RAS move" vs.
 *      "barcode after second move", and the operator's eye reads them by
 *      column position, not by name. The template MUST preserve them at the
 *      same positions. PhpSpreadsheet has no problem writing duplicate
 *      header strings; we just write them straight through.
 *
 * Batch and Box have no dedicated legacy sample (those concepts were buried
 * inside Batch_List_Sample as repeated columns). For those two we
 * synthesise a header row that matches the corresponding Filament Importer
 * 1:1 — so an operator can download → fill → re-upload without any column
 * remapping in the import modal.
 */
final class TemplateGenerator
{
    /**
     * Legacy sample header rows, captured verbatim from the official sample
     * files and embedded here so template generation has NO runtime dependency
     * on any external/absolute path (works identically on dev, CI and on-prem).
     * Only the column NAMES are stored — no notary data — so this is also
     * privacy-safe. Duplicates and positions are preserved exactly because the
     * operators read the legacy layout by column position (see class docblock).
     *
     * @var array<int, string>
     */
    public const AUTHORITY_HEADERS = [
        'Identifier', 'Alternative Identifier', 'Type of Entity',
        'Private Practice Dates Active', 'NTG Dates Active', 'Name Suffix',
        'Maiden Surname', 'Creator Surname', 'Creator Name',
    ];

    /** @var array<int, string> */
    public const SERIES_HEADERS = [
        '', 'Identifier', 'Standard title in English (Plural)',
        'Level of description', 'Date of creation', 'Name of Inputter',
    ];

    /** @var array<int, string> */
    public const DOCUMENT_HEADERS = [
        'RAS Batch 1', 'RAS Box 1', 'RAS Batch 2', 'RAS Box 2',
        'In Situ Box 1', 'In Situ Box 2', 'In Situ Box 3',
        'RAS 1 Box Destroyed', 'RAS 2 Box Destroyed', 'In Situ Box 1 Destroyed',
        'In Situ Box 2 Destroyed', 'In Situ Box 3 Destroyed', 'Barcode (IN)',
        'Barcode RAS 1', 'Status 1', 'Barcode RAS 2', 'Status 2',
        'Barcode RAS 3', 'Status 3', 'Barcode RAS 4', 'Status 4',
        'Barcode (IN)', 'Barcode RAS 2', 'Status 1', 'Barcode RAS 2', 'Status 2',
        // Seal Number is a BOX field (box_seal_number_history), not a document
        // field — intentionally NOT emitted in the document template.
        'Disinfestation Date', 'Disinfestation Date',
        'Disinfestation Date', 'Catalogue Identifier', 'NRA Location',
        'Museum Location', 'Identifier', 'Practice', 'Volume', 'Creator',
        'Dates', 'Deeds', 'Document Type', 'Series', 'Current Box', 'Note',
        'Digitised', 'Torre', 'Accession', 'Object Reference Number',
        'Tracking', 'Museum Reference',
    ];

    /**
     * Generator version — embedded in the hidden metadata sheet so that
     * if we ever break the contract (rename columns, reorder, etc.) we
     * can detect a stale template at re-upload time and warn the operator.
     * Bump on any change to the header contract.
     */
    public const GENERATOR_VERSION = '1.1.0';

    /**
     * Supported template entities. Headers come from the in-repo constants
     * ({@see AUTHORITY_HEADERS}, {@see SERIES_HEADERS}, {@see DOCUMENT_HEADERS})
     * or the `synthesise*Headers()` methods — never from an external file.
     *
     * Kept public so the wizard page and tests can read the same key set
     * without duplicating literals.
     *
     * @var array<string, array{}>
     */
    public const TEMPLATES = [
        'authority' => [],
        'series' => [],
        'batch' => [],
        'box' => [],
        'location' => [],
        'document' => [],
        'volume' => [],
        'accession' => [],
    ];

    /**
     * Entry point — used by Filament Action `->action(fn () => TemplateGenerator::download('authority'))`.
     *
     * Streams the binary xlsx back to the browser with the correct
     * Content-Type and Content-Disposition. Never buffers in memory beyond
     * the single workbook (≤50 KB at the largest — the Document template
     * with 49 header cells is well under 20 KB).
     *
     * @throws \InvalidArgumentException when $entity is not one of the keys in {@see TEMPLATES}.
     */
    public static function download(string $entity): StreamedResponse
    {
        if (! array_key_exists($entity, self::TEMPLATES)) {
            throw new \InvalidArgumentException("Unknown template entity: {$entity}");
        }

        $headers = self::headersFor($entity);
        $spreadsheet = self::buildSpreadsheet($entity, $headers);

        $filename = sprintf('%s_template_%s.xlsx', $entity, now()->format('Y-m-d'));

        return new StreamedResponse(
            function () use ($spreadsheet): void {
                // Stream the .xlsx straight to PHP's output buffer so even
                // a 50k-row template (rare for headers-only, but possible
                // if a future caller pre-fills sample rows) is memory-safe.
                $writer = new XlsxWriter($spreadsheet);
                $writer->save('php://output');
            },
            200,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    /**
     * Return the ordered header list for an entity. Public so tests and the
     * wizard can inspect without round-tripping through xlsx.
     *
     * **Repo-dependent after spec §2**: for entities that support custom fields
     * (document, batch, box, volume) the static base headers are returned
     * FIRST (byte-for-byte contract preserved), then the active custom-field
     * labels for the resolved repository are APPENDED in sort_order order.
     *
     * - "Static" headers = the legacy constants / synthesise*Headers() output.
     *   Their position, spelling, and duplicate behaviour are frozen contracts.
     * - "Dynamic" headers = the label of each active CustomFieldDefinition
     *   for the entity in the active repository (via CustomFieldResolver).
     *   Tests must seed definitions + set the active repo to assert appended columns.
     *
     * Authority and series are reference tables and carry no custom fields —
     * they always return the pure static array.
     *
     * @return array<int, string>
     */
    public static function headersFor(string $entity): array
    {
        if (! array_key_exists($entity, self::TEMPLATES)) {
            throw new \InvalidArgumentException("Unknown template entity: {$entity}");
        }

        $staticHeaders = match ($entity) {
            'authority' => self::AUTHORITY_HEADERS,
            'series' => self::SERIES_HEADERS,
            'document' => self::DOCUMENT_HEADERS,
            'batch' => self::synthesiseBatchHeaders(),
            'box' => self::synthesiseBoxHeaders(),
            'location' => self::synthesiseLocationHeaders(),
            'volume' => self::synthesiseVolumeHeaders(),
            'accession' => self::synthesiseAccessionHeaders(),
        };
        // Note: the `array_key_exists` guard above narrows $entity to the
        // exact key set covered by the match arms, so PHPStan correctly
        // flags any `default` here as unreachable.

        // Authority, series, and location have no custom fields — skip the
        // resolver call entirely (avoids an unnecessary DB query on every
        // template download for these entities).
        if (in_array($entity, ['authority', 'series', 'location'], strict: true)) {
            return $staticHeaders;
        }

        // Accession template appends custom fields for the 'document' entity
        // type because one row = one Document at the bottom of the cascade.
        if ($entity === 'accession') {
            $customLabels = CustomFieldResolver::definitionsFor('document')
                ->pluck('label')
                ->all();

            return array_merge($staticHeaders, $customLabels);
        }

        // Append active custom-field labels for the resolved repository,
        // ordered by sort_order (resolver handles repo resolution + memo).
        $customLabels = CustomFieldResolver::definitionsFor($entity)
            ->pluck('label')
            ->all();

        return array_merge($staticHeaders, $customLabels);
    }

    /**
     * Synthetic Batch headers — mirrors {@see BatchImporter::getColumns()}
     * exactly, including order. The names match the labels operators see
     * inside the Filament Import modal (column-mapping dropdown), so a
     * "download → fill → re-upload" cycle requires zero remapping.
     *
     * Reflects RFQ Appendix-2 §4 (Batch is the root tenancy unit).
     *
     * @return array<int, string>
     */
    private static function synthesiseBatchHeaders(): array
    {
        return [
            'batch_number',
            'description',
            'type',           // MAIN_COLLECTION | NOTARY_ACCESSION
            'is_active',
            'repository_code',
        ];
    }

    /**
     * Synthetic Box headers — mirrors {@see BoxImporter::getColumns()}
     * exactly. Order is significant: `batch_number` precedes `parent_box_number`
     * so an operator filling top-to-bottom understands the parent must
     * already exist in the destination batch.
     *
     * @return array<int, string>
     */
    private static function synthesiseBoxHeaders(): array
    {
        return [
            'box_type',          // RAS | IN_SITU | NRA | MAV | STVC
            'box_number',
            'batch_number',
            'parent_box_number', // barcode of the parent RAS box (IN_SITU/NRA require it — RFQ #3)
            'barcode',
            'barcode_status',    // IN | OUT | PERM_OUT  (PERM_OUT requires disinfestation_date)
            'disinfestation_date',
            'is_legacy',
            'notes',
            // F05 — Seal Number and Location added per client request.
            'Seal Number',       // optional physical seal id on the box
            'Location',          // location code / identifier (e.g. "SHELF-A3")
        ];
    }

    /**
     * Location template — driven by LocationImporter columns + Location::TYPES.
     * Operators describe their tree top-down: roots first (parent_name blank),
     * then children. Re-runs are safe; missing-parent rows fail but the next
     * run picks them up once the parent exists.
     *
     * @return array<int, string>
     */
    private static function synthesiseLocationHeaders(): array
    {
        return [
            'name',
            'type',             // repository | room | work_area | shelf | museum | showcase | conservation | temp_holding | other
            'parent_name',      // blank for root rows
            'repository_code',  // e.g. NRA
            'code',
            'notes',
            'sort_order',
            'is_active',
        ];
    }

    /**
     * Synthetic Accession headers — the bottom-up accession sheet (Wave C,
     * DECISIONS 2–5, 10, 11). Column order mirrors AccessionRowImporter::getColumns()
     * so a "download template → fill → re-upload" round-trip requires no
     * remapping. Every ancestor is auto-created from this single sheet.
     *
     * Column order (NAf Feedback 1, DECISION 11):
     *   Authority → Accession metadata → Batch → Box → Document fields.
     * Accession Type and Repository come immediately after Accession Title
     * (before Batch Number) because they are accession-level attributes.
     *
     * Column name contract (must stay in sync with AccessionRowImporter guesses):
     *   'Box Status'   → box_type field (RAS | IN_SITU | NRA)
     *   'identifier'   → document identifier (lowercase per NAf convention)
     *   'Volume No'    → volume_label / volume_number field
     *   'Note'         → notes field (singular per NAf convention)
     *   'No of Acts'   → number_of_acts field (F2)
     *   'Pages/Folios' → pages_folios field (F2)
     *
     * CONTRACT: if AccessionRowImporter static columns change, update BOTH
     * this method AND the importer, then bump GENERATOR_VERSION.
     *
     * @return array<int, string>
     */
    private static function synthesiseAccessionHeaders(): array
    {
        return [
            // Authority (DECISION 3: multi via ;)
            'Authority Identifier',   // required; ;-delimited R-codes
            'Authority Name',         // optional; validate only
            'Authority Surname',      // optional; validate only
            // Accession
            'Accession Number',       // required; unique accession code/number
            'Accession Title',        // optional; human-readable title
            'Accession Type',         // optional; NOTARY_ACCESSION | MAIN_COLLECTION
            'Repository',             // optional; repo code (defaults to user's default)
            // Batch (N:N, DECISION 1)
            'Batch Number',           // required; integer
            // Box
            'Box No',                 // required; unique within batch
            'Box Barcode',            // required for RAS; globally unique
            'Box Status',             // optional; RAS | IN_SITU | NRA (default: RAS)
            // Document (DECISIONS 4, 5, 7, 10)
            'identifier',             // optional; auto-generated when blank (DECISION 4)
            'Document Type',          // required
            'Series',                 // required; code or "CODE: Title"
            'Volume No',              // optional; renamed from volume_label (DECISION 7)
            'Part Number',            // optional; DECISION 5
            'Practice',               // optional
            'Dates',                  // optional; free-text range
            'Deeds',                  // optional
            // F2: two new document fields added after 'Deeds' (DECISION F2).
            'No of Acts',             // optional; number/count of acts
            'Pages/Folios',           // optional; page or folio count
            'Note',                   // optional
        ];
    }

    /**
     * Synthetic Volume headers — mirrors the VolumeImporter static columns
     * exactly (import phase §4 must keep these names in sync):
     *
     *   document_identifier  — required; resolves the parent Document by its
     *                          identifier, scoped to the active repository.
     *   volume_number        — the Volume.volume_number column.
     *   dates_start          — Volume.dates_start (Y-m-d or human-readable).
     *   dates_end            — Volume.dates_end   (Y-m-d or human-readable).
     *   notes                — Volume.notes (free text).
     *
     * CONTRACT: the Import phase (spec §4, VolumeImporter::getColumns()) MUST
     * declare exactly these column keys in the same order so that a
     * "download template → fill → re-upload" round-trip requires zero remapping.
     * If the VolumeImporter static columns ever change, update BOTH this method
     * AND the importer, then bump GENERATOR_VERSION.
     *
     * @return array<int, string>
     */
    private static function synthesiseVolumeHeaders(): array
    {
        return [
            'document_identifier', // FK: resolves Volume.document_id via Document.identifier
            'volume_number',
            'dates_start',
            'dates_end',
            'notes',
        ];
    }

    /**
     * Build the Spreadsheet with a single data sheet (headers in row 1,
     * everything below blank) plus a hidden metadata sheet that lets us
     * detect tampering / staleness at re-upload time.
     *
     * The `_template_meta` sheet carries a `custom_field_keys` row listing
     * the `cf_{key}` column identifiers for any dynamic custom-field columns
     * appended after the static base headers. An empty string means no custom
     * fields were present when the template was generated (stale-template
     * detection: if the operator's active repo has custom fields but the
     * template has none listed, warn and suggest re-downloading).
     *
     * @param array<int, string> $headers
     */
    private static function buildSpreadsheet(string $entity, array $headers): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;

        // Sheet 0: the actual template the operator fills in.
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::sheetTitleFor($entity));

        foreach ($headers as $i => $header) {
            // i is 0-based; xlsx columns are 1-based (A=1).
            $colLetter = Coordinate::stringFromColumnIndex($i + 1);
            $cell = $sheet->getCell($colLetter . '1');
            $cell->setValueExplicit(
                $header,
                DataType::TYPE_STRING
            );
        }

        // Light styling — bold header, freeze top row, autosize.
        if (count($headers) > 0) {
            $lastColLetter = Coordinate::stringFromColumnIndex(count($headers));
            $headerRange = "A1:{$lastColLetter}1";
            $sheet->getStyle($headerRange)->getFont()->setBold(true);
            $sheet->getStyle($headerRange)
                ->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $sheet->freezePane('A2');

            foreach (range(1, count($headers)) as $colIdx) {
                $letter = Coordinate::stringFromColumnIndex($colIdx);
                $sheet->getColumnDimension($letter)->setAutoSize(true);
            }
        }

        // Collect the cf_{key} identifiers for any custom fields appended to
        // this template, so the _template_meta sheet can list them explicitly.
        // Authority, series, and location carry no custom fields — skip.
        $customFieldKeys = [];
        if (! in_array($entity, ['authority', 'series', 'location'], strict: true)) {
            $customFieldKeys = CustomFieldResolver::definitionsFor($entity)
                ->map(fn ($def) => 'cf_' . $def->key)
                ->all();
        }

        // Sheet 1: hidden metadata. The operator never sees this; we use it
        // to detect tampered or stale templates at upload time.
        $meta = $spreadsheet->createSheet();
        $meta->setTitle('_template_meta');
        $meta->fromArray(
            [
                ['key', 'value'],
                ['generated_at', now()->toIso8601String()],
                ['entity', $entity],
                ['expected_column_count', (string) count($headers)],
                ['generator_version', self::GENERATOR_VERSION],
                // Comma-separated cf_{key} list; empty string = no custom fields.
                ['custom_field_keys', implode(',', $customFieldKeys)],
            ],
            null,
            'A1'
        );
        $meta->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        // Restore active sheet to index 0 (the data sheet) — otherwise the
        // hidden meta would be the default tab in Excel.
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    private static function sheetTitleFor(string $entity): string
    {
        // Excel sheet titles cap at 31 chars and forbid `:\/?*[]`. Our
        // entity keys are clean ASCII; just title-case for the user.
        return match ($entity) {
            'authority' => 'Authorities',
            'series' => 'Series',
            'batch' => 'Batches',
            'box' => 'Boxes',
            'location' => 'Locations',
            'document' => 'Documents',
            'volume' => 'Volumes',
            'accession' => 'Accession Import',
            default => 'Template',
        };
    }
}
