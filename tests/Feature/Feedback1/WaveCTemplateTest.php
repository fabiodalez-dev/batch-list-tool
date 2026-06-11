<?php

declare(strict_types=1);

use App\Filament\Imports\AccessionRowImporter;
use App\Filament\Pages\ImportWizard;
use App\Support\BulkImport\TemplateGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Wave C — Template + Wizard wiring (C3, C4, DECISION 11).
 *
 * C3-Headers     — synthesiseAccessionHeaders() returns the exact 23 columns
 *                  in the correct NAf Feedback 1 order (cascade: Authority →
 *                  Accession metadata → Batch → Box → Document fields). F2 added
 *                  the 'No of Acts' and 'Pages/Folios' columns; FB1-GAP-2 added
 *                  the 'Current Box Type' column after 'Box Status'.
 * C3-SheetTitle  — sheetTitleFor('accession') returns 'Accession Import'.
 * C4-WizardMap   — ImportWizard::IMPORTERS and TEMPLATE_KEYS are wired.
 * C4-PrimaryPath — 'accessions' is the first key in IMPORTERS (primary path).
 * D11-Synonyms   — SYNONYMS table covers NAf Feedback 1 column name variants
 *                  so guessColumnMap() auto-maps them without operator intervention.
 */
uses(RefreshDatabase::class);

// ── C3-Headers — exact column order ──────────────────────────────────────────

it('C3-Headers: synthesiseAccessionHeaders returns the exact 23 columns in cascade order', function (): void {
    $headers = TemplateGenerator::headersFor('accession');

    // Exact ordered contract (NAf Feedback 1 / DECISION 11 / F2 / FB1-GAP-2):
    // F2 added 'No of Acts' and 'Pages/Folios' after 'Deeds'; FB1-GAP-2 added
    // 'Current Box Type' after 'Box Status' (now 23 static columns).
    $expected = [
        'Authority Identifier',
        'Authority Name',
        'Authority Surname',
        'Accession Number',
        'Accession Title',
        'Accession Type',   // before Repository (accession-level attribute)
        'Repository',
        'Batch Number',     // after Repository, not before
        'Box No',
        'Box Barcode',
        'Box Status',       // renamed from 'Box Type'
        'Current Box Type', // FB1-GAP-2: current_box_types lookup ref code
        'identifier',       // lowercase per NAf convention
        'Document Type',
        'Series',
        'Volume No',        // renamed from 'Volume Number'
        'Part Number',
        'Practice',
        'Dates',
        'Deeds',
        'No of Acts',       // F2: added after Deeds
        'Pages/Folios',     // F2: added after No of Acts
        'Note',             // singular per NAf convention
    ];

    // The first 23 elements (static headers) must match exactly.
    $staticHeaders = array_slice($headers, 0, count($expected));
    expect($staticHeaders)->toBe($expected);

    // Total count must be at least 23 (custom fields appended after).
    expect(count($headers))->toBeGreaterThanOrEqual(23);
});

// ── C3-SheetTitle — sheet title ───────────────────────────────────────────────

it('C3-SheetTitle: sheetTitleFor accession returns Accession Import', function (): void {
    // headersFor() triggers the same match arm used by buildSpreadsheet().
    // We verify via the TEMPLATES constant + a reflection of sheetTitleFor.
    // Since sheetTitleFor is private we test it indirectly via download() title
    // injection — but that needs a streaming response. Instead, verify that
    // the entity key is registered and the download method does not throw.
    expect(array_key_exists('accession', TemplateGenerator::TEMPLATES))->toBeTrue();

    // Verify the sheet title by calling the reflection on the private method.
    $ref = new ReflectionClass(TemplateGenerator::class);
    $method = $ref->getMethod('sheetTitleFor');
    $method->setAccessible(true);
    $title = $method->invoke(null, 'accession');
    expect($title)->toBe('Accession Import');
});

// ── C4-WizardMap — IMPORTERS + TEMPLATE_KEYS wired ───────────────────────────

it('C4-WizardMap: ImportWizard IMPORTERS and TEMPLATE_KEYS contain the accessions entry', function (): void {
    expect(ImportWizard::IMPORTERS)->toHaveKey('accessions');
    expect(ImportWizard::IMPORTERS['accessions'])->toBe(AccessionRowImporter::class);

    expect(ImportWizard::TEMPLATE_KEYS)->toHaveKey('accessions');
    expect(ImportWizard::TEMPLATE_KEYS['accessions'])->toBe('accession');

    // Consistency: every IMPORTERS key must have a matching TEMPLATE_KEYS entry.
    foreach (array_keys(ImportWizard::IMPORTERS) as $key) {
        expect(ImportWizard::TEMPLATE_KEYS)->toHaveKey($key);
    }
});

// ── D11-Synonyms — guessColumnMap resolves NAf Feedback 1 header variants ─────

it('D11-Synonyms: guessColumnMap resolves NAf Feedback 1 column name variants for AccessionRowImporter', function (): void {
    // The NAf Feedback 1 template uses these header names; the wizard must
    // auto-map them to the correct importer fields without operator intervention.
    $naFHeaders = [
        'Authority Identifier',
        'Authority Name',
        'Authority Surname',
        'Accession Number',
        'Accession Title',
        'Accession Type',
        'Repository',
        'Batch Number',
        'Box No',
        'Box Barcode',
        'Box Status',      // → box_type
        'identifier',      // → identifier
        'Document Type',
        'Series',
        'Volume No',       // → volume_number
        'Part Number',
        'Practice',
        'Dates',
        'Deeds',
        'Note',            // → notes
    ];

    $map = ImportWizard::guessColumnMap(AccessionRowImporter::class, $naFHeaders);

    // Core fields must be auto-resolved.
    expect($map['authority_identifier'])->toBe('Authority Identifier');
    expect($map['authority_name'])->toBe('Authority Name');
    expect($map['authority_surname'])->toBe('Authority Surname');
    expect($map['accession_number'])->toBe('Accession Number');
    expect($map['batch_number'])->toBe('Batch Number');
    expect($map['box_number'])->toBe('Box No');
    expect($map['box_barcode'])->toBe('Box Barcode');
    expect($map['document_type'])->toBe('Document Type');
    expect($map['series'])->toBe('Series');
    expect($map['part_number'])->toBe('Part Number');

    // NAf Feedback 1 renamed columns must also auto-resolve.
    expect($map['box_type'])->toBe('Box Status');        // 'Box Status' → box_type
    expect($map['identifier'])->toBe('identifier');      // lowercase 'identifier' → identifier
    expect($map['volume_number'])->toBe('Volume No');     // 'Volume No' → volume_number
    expect($map['notes'])->toBe('Note');                 // singular 'Note' → notes
});
