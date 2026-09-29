<?php

declare(strict_types=1);

use App\Filament\Imports\AccessionRowImporter;
use App\Filament\Imports\AuthorityImporter;
use App\Filament\Imports\BatchImporter;
use App\Filament\Imports\BoxImporter;
use App\Filament\Imports\DocumentImporter;
use App\Filament\Imports\DocumentTypeImporter;
use App\Filament\Imports\LocationImporter;
use App\Filament\Imports\SeriesImporter;
use App\Filament\Imports\VolumeImporter;
use App\Filament\Pages\ImportWizard;
use App\Support\BulkImport\TemplateGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Every importer column guessed its header on its own, and nobody arbitrated
 * when two of them reached for the same one. Both got it, and the sheet quietly
 * filled two fields from one column — or filled the wrong one.
 *
 * The worst instance had been documented and unfixed for months. DocumentImporter
 * has a field called `identifier`, and the client's legacy sheets have a column
 * headed "Identifier" that holds the AUTHORITY's R-code. The comment above that
 * column says so, and asks for "Identifier" to be kept out of its guess list —
 * but a field's own NAME is matched before any guess, so the mis-mapping
 * happened regardless: the creator's code was filed as the document's own
 * identifier and the creator was left unlinked. Exactly what the comment warned
 * about, written by someone who had already seen it happen.
 *
 * The rule now: the strongest claim wins the header. An exact match (name, label
 * or an explicit guess) beats the shared synonyms table, which beats Levenshtein
 * resemblance. A column that loses is left unmapped, which is honest — that
 * header belongs to another column, and the operator can still map it by hand.
 */
uses(RefreshDatabase::class);

/**
 * The header rows that have actually been uploaded to archivetool.eu, taken from
 * the import files on the server. Column NAMES only — no notary data.
 *
 * Fixtures from the real thing on purpose: a guesser is only as good as the
 * sheets it meets, and these are the sheets it meets.
 *
 * @return array<string, list<string>>
 */
function cga_realHeaderRows(): array
{
    return [
        'authority (current, 676 rows, 2026-09-28)' => [
            'NAM Authority Reference Code', 'Citing Reference Code', 'Alternate Reference Code',
            'Past Reference Code', 'Type of Entity', 'Private Practice Dates Active',
            'NTG Dates Active', 'Name Suffix', 'Maiden Surname', 'Creator Surname', 'Creator Name',
            'Authorised form of name', 'Functions, occupations and activities', 'Level of detail',
            'Status', 'Rules and/or conventions', 'Date of creation', 'Creator of record', 'Note',
        ],
        'authority (pre-rename)' => [
            'Identifier', 'Alternative Identifier', 'Type of Entity', 'Private Practice Dates Active',
            'NTG Dates Active', 'Name Suffix', 'Maiden Surname', 'Creator Surname', 'Creator Name',
        ],
        'box (current, 4886 rows, 2026-09-28)' => [
            'box_type', 'box_number', 'batch_number', 'parent_box_number', 'barcode', 'barcode_status',
            'is_legacy', 'Provenance Unknown', 'notes', 'Tracking Note', 'Seal Number', 'Destroyed',
            'Current Box Type',
        ],
        'box (with the old Location column)' => [
            'box_type', 'box_number', 'batch_number', 'parent_box_number', 'barcode', 'barcode_status',
            'disinfestation_date', 'is_legacy', 'note', 'Seal Number', 'Location',
        ],
        'batch' => ['batch_number', 'description', 'type', 'is_active', 'repository_code'],
        'location' => ['name', 'type', 'parent_name', 'repository_code', 'code', 'notes', 'sort_order', 'is_active'],
        'series' => ['Identifier', 'Standard title in English (Plural)', 'Level of description', 'Date of creation', 'Name of Inputter', 'Repository'],
        'document type' => ['Identifier', 'Name', 'Description', 'Is active'],
    ];
}

/** field => header, blanks dropped. */
function cga_map(string $importer, array $headers): array
{
    return array_filter(
        ImportWizard::guessColumnMap($importer, $headers),
        static fn (?string $h): bool => $h !== null && $h !== '',
    );
}

it('gives a header to one column only, never to two', function (): void {
    // The property the whole change is about. Two fields fed by one column is
    // either a duplicated value or a wrong one, and it is silent either way.
    $clashes = [];

    foreach (cga_realHeaderRows() as $sheet => $headers) {
        foreach ([
            'authority' => AuthorityImporter::class,
            'series' => SeriesImporter::class,
            'batch' => BatchImporter::class,
            'box' => BoxImporter::class,
            'location' => LocationImporter::class,
            'documentType' => DocumentTypeImporter::class,
            'document' => DocumentImporter::class,
            'volume' => VolumeImporter::class,
            'accession' => AccessionRowImporter::class,
        ] as $entity => $importer) {
            $byHeader = [];
            foreach (cga_map($importer, $headers) as $field => $header) {
                $byHeader[$header][] = $field;
            }
            foreach (array_filter($byHeader, static fn (array $f): bool => count($f) > 1) as $header => $fields) {
                $clashes[] = "{$sheet} / {$entity}: \"{$header}\" -> " . implode(' + ', $fields);
            }
        }
    }

    expect($clashes)->toBe([]);
});

it('gives a header to one column only on every generated template too', function (): void {
    $clashes = [];

    foreach ([
        'authority' => AuthorityImporter::class,
        'series' => SeriesImporter::class,
        'batch' => BatchImporter::class,
        'box' => BoxImporter::class,
        'location' => LocationImporter::class,
        'documentType' => DocumentTypeImporter::class,
        'document' => DocumentImporter::class,
        'volume' => VolumeImporter::class,
        'accession' => AccessionRowImporter::class,
    ] as $entity => $importer) {
        $byHeader = [];
        foreach (cga_map($importer, TemplateGenerator::headersFor($entity)) as $field => $header) {
            $byHeader[$header][] = $field;
        }
        foreach (array_filter($byHeader, static fn (array $f): bool => count($f) > 1) as $header => $fields) {
            $clashes[] = "{$entity}: \"{$header}\" -> " . implode(' + ', $fields);
        }
    }

    expect($clashes)->toBe([]);
});

it('files the legacy Identifier column under the creator, not the document (F-004)', function (): void {
    // The client's Batch_List sheets carry "Identifier" holding the authority's
    // R-code. Before the arbitration both `identifier` and `authority_identifier`
    // claimed it, and `identifier` is what the document was saved with.
    $headers = ['Identifier', 'Catalogue Identifier', 'Subseries', 'Document Type'];
    $map = cga_map(DocumentImporter::class, $headers);

    expect($map['authority_identifier'] ?? null)->toBe('Identifier')
        ->and($map['identifier'] ?? null)->not->toBe('Identifier');

    // And the document's own identifier still answers to its own columns.
    $own = cga_map(DocumentImporter::class, ['Document Identifier', 'Identifier']);
    expect($own['identifier'] ?? null)->toBe('Document Identifier')
        ->and($own['authority_identifier'] ?? null)->toBe('Identifier');
});

it('does not let the document identifier take the Authority Identifier column', function (): void {
    // Same defect by the other route: the shared synonyms table maps
    // "authority identifier" onto the field name `identifier`, which
    // DocumentImporter also has.
    $map = cga_map(DocumentImporter::class, ['Authority Identifier', 'Catalogue Identifier']);

    expect($map['authority_identifier'] ?? null)->toBe('Authority Identifier')
        ->and($map['identifier'] ?? null)->not->toBe('Authority Identifier');
});

it('keeps the accession date off the accession title', function (): void {
    // accession_date reached "Accession Title" through Levenshtein — the slugs
    // are three edits apart, and the threshold is three.
    $map = cga_map(AccessionRowImporter::class, ['Accession Number', 'Accession Title', 'Repository']);

    expect($map['accession_title'] ?? null)->toBe('Accession Title')
        ->and($map['accession_date'] ?? null)->toBeNull();
});

it('keeps the box type off the box status column', function (): void {
    $map = cga_map(AccessionRowImporter::class, ['Box No', 'Box Barcode', 'Box Status']);

    expect($map['box_barcode_status'] ?? null)->toBe('Box Status')
        ->and($map['box_type'] ?? null)->not->toBe('Box Status');
});

it('reads Current Box as the container type, which is what the sheets hold', function (): void {
    // The client's "Current Box" column holds "RAS" — a container type, not a
    // box number. Both columns had it in their guess list.
    $map = cga_map(DocumentImporter::class, ['Catalogue Identifier', 'Current Box']);

    expect($map['current_box_type'] ?? null)->toBe('Current Box')
        ->and($map['current_box_number'] ?? null)->toBeNull();
});

it('tells the creator name apart from the creator surname', function (): void {
    // "authority name" listed both surname and given_names as targets, so one
    // sheet column filled two fields.
    $map = cga_map(AuthorityImporter::class, ['Authority Name', 'Authority Surname']);

    expect($map['given_names'] ?? null)->toBe('Authority Name')
        ->and($map['surname'] ?? null)->toBe('Authority Surname');
});

it('does not duplicate one barcode column into the repeat-reading fields', function (): void {
    // barcode_in_2 / status_1_alt and friends exist to read the SECOND physical
    // occurrence of a repeated header. Given a sheet with only one, they used to
    // claim it as well, writing the same value into two fields.
    $map = cga_map(DocumentImporter::class, ['Catalogue Identifier', 'Barcode (IN)', 'Status 1']);

    expect($map['barcode_in'] ?? null)->toBe('Barcode (IN)')
        ->and($map['barcode_in_2'] ?? null)->toBeNull()
        ->and($map['status_1'] ?? null)->toBe('Status 1')
        ->and($map['status_1_alt'] ?? null)->toBeNull();
});

it('leaves every real uploaded sheet mapping exactly as it did', function (): void {
    // The guard on the whole change: these are the sheets already imported into
    // production. Whatever else moves, what they map to must not.
    $expected = [
        'authority (current, 676 rows, 2026-09-28)' => [
            'identifier' => 'NAM Authority Reference Code',
            'alternative_identifier' => 'Citing Reference Code',
            'alternative_identifier_warrant' => 'Alternate Reference Code',
            'previous_temporary_identifiers' => 'Past Reference Code',
            'surname' => 'Creator Surname',
            'given_names' => 'Creator Name',
        ],
        'box (current, 4886 rows, 2026-09-28)' => [
            'box_type' => 'box_type',
            'box_number' => 'box_number',
            'batch_number' => 'batch_number',
            'parent_barcode' => 'parent_box_number',
            'barcode' => 'barcode',
            'barcode_status' => 'barcode_status',
            'current_box_type' => 'Current Box Type',
        ],
        'batch' => [
            'batch_number' => 'batch_number',
            'description' => 'description',
            'type' => 'type',
            'repository_code' => 'repository_code',
        ],
        'location' => [
            'name' => 'name',
            'type' => 'type',
            'parent_name' => 'parent_name',
            'code' => 'code',
        ],
        'series' => [
            'code' => 'Identifier',
            'title' => 'Standard title in English (Plural)',
            'repository_code' => 'Repository',
        ],
    ];

    $importerFor = [
        'authority (current, 676 rows, 2026-09-28)' => AuthorityImporter::class,
        'box (current, 4886 rows, 2026-09-28)' => BoxImporter::class,
        'batch' => BatchImporter::class,
        'location' => LocationImporter::class,
        'series' => SeriesImporter::class,
    ];

    $rows = cga_realHeaderRows();

    foreach ($expected as $sheet => $pairs) {
        $map = cga_map($importerFor[$sheet], $rows[$sheet]);
        foreach ($pairs as $field => $header) {
            expect($map[$field] ?? null)->toBe($header, "{$sheet}: {$field}");
        }
    }
});

it('still maps every required column of the sheets already uploaded', function (): void {
    // A required column losing its header is an import that stops working. This
    // walks the real header rows against the importer each was actually for.
    $pairs = [
        'authority (current, 676 rows, 2026-09-28)' => AuthorityImporter::class,
        'authority (pre-rename)' => AuthorityImporter::class,
        'box (current, 4886 rows, 2026-09-28)' => BoxImporter::class,
        'box (with the old Location column)' => BoxImporter::class,
        'batch' => BatchImporter::class,
        'location' => LocationImporter::class,
        'series' => SeriesImporter::class,
        'document type' => DocumentTypeImporter::class,
    ];

    $rows = cga_realHeaderRows();
    $broken = [];

    foreach ($pairs as $sheet => $importer) {
        $missing = ImportWizard::findMissingRequiredColumns(
            $importer,
            ImportWizard::guessColumnMap($importer, $rows[$sheet]),
        );
        foreach ($missing as $column) {
            $broken[] = "{$sheet}: {$column}";
        }
    }

    expect($broken)->toBe([]);
});

// ── Columns the importer silently ignores ────────────────────────────────────

it('names the client sheet column that would have been dropped without a word', function (): void {
    // Charlene, 2026-09-29: a RAS box whose current barcode is IN but which
    // previously carried another RAS barcode that went PERM OUT. Her sheet
    // headed the past barcode simply "Barcode"; the importer wants
    // "Barcode RAS 1". The column maps to nothing, so the barcode history
    // would have been lost on an import that reported complete success.
    $headers = [
        'RAS Batch 1', 'RAS Box 1', 'RAS Batch 2', 'RAS Box 2',
        'In Situ Box 1', 'In Situ Box 2', 'Box 2 Destroyed',
        'In Situ Box 3 Destroyed', 'Barcode (IN)', 'Barcode', 'Status 1',
    ];

    $map = ImportWizard::guessColumnMap(DocumentImporter::class, $headers);

    expect(ImportWizard::unrecognisedHeaders($headers, $map))
        ->toContain('Barcode');

    // Renaming it to the header the template ships is the whole fix.
    $fixed = $headers;
    $fixed[array_search('Barcode', $fixed, true)] = 'Barcode RAS 1';
    $fixedMap = ImportWizard::guessColumnMap(DocumentImporter::class, $fixed);

    expect($fixedMap['barcode_ras_1'])->toBe('Barcode RAS 1')
        ->and($fixedMap['barcode_in'])->toBe('Barcode (IN)')
        ->and($fixedMap['status_1'])->toBe('Status 1')
        ->and(ImportWizard::unrecognisedHeaders($fixed, $fixedMap))->not->toContain('Barcode RAS 1');
});

it('does not call a blank trailing column an ignored column', function (): void {
    // A sheet with an empty column at the end is not an operator mistake, and
    // listing it on screen would train them to ignore the warning.
    $headers = ['Code', 'Title', '', null, '   '];
    $map = ['code' => 'Code', 'title' => 'Title'];

    expect(ImportWizard::unrecognisedHeaders($headers, $map))->toBe([]);
});

it('says nothing about the templates we ship ourselves', function (): void {
    // If one of our own templates carried a column its own importer ignores,
    // every operator would see the warning on every import and stop reading it.
    $ignored = [];

    foreach ([
        'authority' => AuthorityImporter::class,
        'series' => SeriesImporter::class,
        'batch' => BatchImporter::class,
        'box' => BoxImporter::class,
        'location' => LocationImporter::class,
        'documentType' => DocumentTypeImporter::class,
        'document' => DocumentImporter::class,
        'volume' => VolumeImporter::class,
        'accession' => AccessionRowImporter::class,
    ] as $entity => $importer) {
        $headers = TemplateGenerator::headersFor($entity);
        foreach (ImportWizard::unrecognisedHeaders($headers, cga_map($importer, $headers)) as $header) {
            $ignored[] = "{$entity}: \"{$header}\"";
        }
    }

    expect($ignored)->toBe([]);
});

it('renders the warning the operator actually reads, naming the dropped column', function (): void {
    $headers = ['RAS Batch 1', 'RAS Box 1', 'Barcode (IN)', 'Barcode', 'Status 1'];
    $map = ImportWizard::guessColumnMap(DocumentImporter::class, $headers);

    $html = ImportWizard::renderIgnoredColumns($headers, $map);

    expect($html)->toContain('not')
        ->and($html)->toContain('being imported')
        ->and($html)->toContain('Barcode')
        ->and($html)->not->toContain('Barcode (IN)');
});

it('renders nothing at all when every column is consumed', function (): void {
    $headers = TemplateGenerator::headersFor('location');
    $map = ImportWizard::guessColumnMap(LocationImporter::class, $headers);

    expect(ImportWizard::renderIgnoredColumns($headers, $map))->toBe('');
});
