<?php

declare(strict_types=1);

use App\Filament\Imports\DocumentImporter;
use App\Filament\Pages\ImportWizard;
use App\Models\Accession;
use App\Models\Authority;
use App\Models\Batch;
use App\Models\Box;
use App\Models\BoxBarcodeHistory;
use App\Models\BoxMovement;
use App\Models\CustomFieldDefinition;
use App\Models\Document;
use App\Models\DocumentIdentifierHistory;
use App\Models\DocumentType;
use App\Models\Location;
use App\Models\Practice;
use App\Models\Repository;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use App\Support\BulkImport\SpreadsheetHeaders;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * Wide behavioural coverage for `nra:import-batch-list` AFTER it was aligned to
 * delegate every row through {@see DocumentImporter}. Every test drives the REAL
 * command entry point ($this->artisan('nra:import-batch-list', ...)) against a
 * focused CSV, then asserts the wizard side-effect (or alignment behaviour) it
 * targets. No reflection, no synthetic internals — the production path only.
 */
uses(RefreshDatabase::class);

/**
 * The COMPLETE importer header set — every column the importer knows, all unique
 * names (block-2's chain uses the already-deduped "* (2)" header spellings).
 *
 * Passing the full set makes each importer field Tier-1 EXACT-match its own
 * header (exactly like the existing bch_row helper) and avoids guessColumnMap's
 * Levenshtein fuzzy matcher stealing a neighbouring header when a field's own
 * column is absent — e.g. `in_situ_box_2` fuzzy-matching "In Situ Box 1", which
 * would fabricate extra movements. The real NAF file always carries every
 * column, so production never hits that fuzzy path; the tests mirror that.
 *
 * @var list<string>
 */
const IBLA_HEADER = [
    'Series', 'Document Identifier', 'Catalogue Identifier',
    'RAS Batch 1', 'RAS Box 1', 'RAS Batch 2', 'RAS Box 2',
    'In Situ Box 1', 'In Situ Box 2', 'In Situ Box 3',
    // block-1 barcode chain
    'Barcode (IN)', 'Barcode RAS 1', 'Status 1', 'Barcode RAS 2', 'Status 2',
    'Barcode RAS 3', 'Status 3', 'Barcode RAS 4', 'Status 4',
    // block-2 barcode chain (the importer guesses the already-deduped spellings)
    'Barcode (IN) (2)', 'Barcode RAS 1 (2)', 'Status 1 (2)', 'Barcode RAS 2 (2)', 'Status 2 (2)',
    'Authority Identifier', 'Accession', 'Document Type', 'Type', 'Practice',
    'Location', 'Prev Attributed Identifier', 'Prev Attributed Volume',
    'Seal Number', 'Current Box', 'Torre', 'Volume', 'Temporary Identifier',
    'Citation Reference', 'Tracking Note', 'Notes',
];

/** @return array{0: Repository, 1: User} */
function ibla_seed(): array
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
    $repo = Repository::firstOrCreate(['code' => 'NRA'], ['name' => 'National Records Archive']);
    /** @var User $admin */
    $admin = User::factory()->create([
        'email' => 'ibla-admin@test.local',
        'is_active' => true,
        'default_repository_id' => $repo->id,
    ]);
    $admin->assignRole('super_admin');

    return [$repo, $admin];
}

/**
 * Write a CSV to a temp file and return its path.
 *
 * @param list<string> $header
 * @param list<list<string|int|null>> $rows
 */
function ibla_write(array $header, array $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'ibla_') . '.csv';
    $fh = fopen($path, 'w');
    fputcsv($fh, $header, escape: '');
    foreach ($rows as $row) {
        fputcsv($fh, $row, escape: '');
    }
    fclose($fh);

    return $path;
}

/**
 * Build a CSV from the standard header + associative rows (keyed by header name,
 * missing keys are blank), returning the file path.
 *
 * @param list<array<string, string|int|null>> $assocRows
 */
function ibla_std(array $assocRows): string
{
    $rows = [];
    foreach ($assocRows as $assoc) {
        $r = array_fill_keys(IBLA_HEADER, '');
        foreach ($assoc as $k => $v) {
            $r[$k] = $v;
        }
        $rows[] = array_values($r);
    }

    return ibla_write(IBLA_HEADER, $rows);
}

/** Run the real command, returning the exit code (asserted 0 by default). */
function ibla_run(string $file, User $admin, array $opts = []): void
{
    $params = array_merge([
        '--file' => $file,
        '--user' => $admin->email,
        '--repo' => 'NRA',
        '--no-interaction' => true,
    ], $opts);

    test()->artisan('nra:import-batch-list', $params)->assertExitCode(0);
}

function ibla_doc(string $identifier): ?Document
{
    return Document::withoutGlobalScopes()->where('identifier', $identifier)->first();
}

function ibla_box(int $batchId, string $number): Box
{
    return Box::withoutGlobalScopes()->where('batch_id', $batchId)->where('box_number', $number)->firstOrFail();
}

// ════════════════════════════════════════════════════════════════════════
//  Movements
// ════════════════════════════════════════════════════════════════════════

test('command builds the full box-movement chain with correct from/to sequence', function () {
    [$repo, $admin] = ibla_seed();
    $file = ibla_std([[
        'Series' => 'REG', 'Document Identifier' => 'M-1',
        'RAS Batch 1' => '1', 'RAS Box 1' => '1',
        'RAS Batch 2' => '2', 'RAS Box 2' => '5',
        'In Situ Box 1' => 'NRA 3',
    ]]);
    ibla_run($file, $admin);

    $b1 = Batch::withoutGlobalScopes()->where('batch_number', 1)->firstOrFail();
    $b2 = Batch::withoutGlobalScopes()->where('batch_number', 2)->firstOrFail();
    $box1 = ibla_box($b1->id, '1');
    $box5 = ibla_box($b2->id, '5');
    $inSitu = Box::withoutGlobalScopes()->where('box_type', 'NRA')->where('box_number', '3')->firstOrFail();

    $moves = BoxMovement::withoutGlobalScopes()
        ->where('document_id', ibla_doc('M-1')->id)->orderBy('sequence')->get();

    expect($moves)->toHaveCount(3)
        ->and($moves[0]->only(['from_box_id', 'to_box_id', 'sequence']))
        ->toBe(['from_box_id' => null, 'to_box_id' => $box1->id, 'sequence' => 1])
        ->and($moves[1]->only(['from_box_id', 'to_box_id', 'sequence']))
        ->toBe(['from_box_id' => $box1->id, 'to_box_id' => $box5->id, 'sequence' => 2])
        ->and($moves[2]->only(['from_box_id', 'to_box_id', 'sequence']))
        ->toBe(['from_box_id' => $box5->id, 'to_box_id' => $inSitu->id, 'sequence' => 3]);
});

test('command repoints current_box_id to the NEWEST box while batch_id stays the archival RAS batch', function () {
    [$repo, $admin] = ibla_seed();
    $file = ibla_std([[
        'Series' => 'REG', 'Document Identifier' => 'M-2',
        'RAS Batch 1' => '1', 'RAS Box 1' => '1',
        'RAS Batch 2' => '2', 'RAS Box 2' => '5',
    ]]);
    ibla_run($file, $admin);

    $b1 = Batch::withoutGlobalScopes()->where('batch_number', 1)->firstOrFail();
    $b2 = Batch::withoutGlobalScopes()->where('batch_number', 2)->firstOrFail();
    $box5 = ibla_box($b2->id, '5');

    $doc = ibla_doc('M-2');
    expect($doc->current_box_id)->toBe($box5->id)   // newest = RAS Box 2
        ->and($doc->batch_id)->toBe($b1->id);         // archival RAS batch stays batch 1
});

test('command leaves a lone RAS Box 1 row with ZERO movements and current_box_id on that box', function () {
    [$repo, $admin] = ibla_seed();
    $file = ibla_std([[
        'Series' => 'REG', 'Document Identifier' => 'M-3',
        'RAS Batch 1' => '1', 'RAS Box 1' => '1',
    ]]);
    ibla_run($file, $admin);

    $b1 = Batch::withoutGlobalScopes()->where('batch_number', 1)->firstOrFail();
    $box1 = ibla_box($b1->id, '1');
    $doc = ibla_doc('M-3');

    expect(BoxMovement::withoutGlobalScopes()->where('document_id', $doc->id)->count())->toBe(0)
        ->and($doc->current_box_id)->toBe($box1->id);
});

// ════════════════════════════════════════════════════════════════════════
//  Barcode history
// ════════════════════════════════════════════════════════════════════════

test('command builds block-1 barcode history as n-1 transitions oldest→newest, IN last', function () {
    [$repo, $admin] = ibla_seed();
    $file = ibla_std([[
        'Series' => 'REG', 'Document Identifier' => 'BC-1',
        'RAS Batch 1' => '1', 'RAS Box 1' => '1',
        'Barcode RAS 1' => 'AA1', 'Status 1' => 'Perm Out',
        'Barcode RAS 2' => 'AA2', 'Status 2' => 'Perm Out',
        'Barcode (IN)' => 'AA3',
    ]]);
    ibla_run($file, $admin);

    $b1 = Batch::withoutGlobalScopes()->where('batch_number', 1)->firstOrFail();
    $box1 = ibla_box($b1->id, '1');
    $rows = BoxBarcodeHistory::withoutGlobalScopes()
        ->where('box_id', $box1->id)->where('source', BoxBarcodeHistory::SOURCE_LEGACY)
        ->orderBy('id')->get();

    // chain [AA1/PERM_OUT, AA2/PERM_OUT, AA3/IN] → 2 transitions.
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->previous_barcode)->toBe('AA1')
        ->and($rows[0]->new_barcode)->toBe('AA2')
        ->and($rows[1]->previous_barcode)->toBe('AA2')
        ->and($rows[1]->new_barcode)->toBe('AA3')
        ->and($rows[1]->new_status)->toBe('IN');
});

test('command captures the block-2 "Barcode RAS 1 (2)" + "Barcode RAS 2 (2)" chain (regression #212)', function () {
    [$repo, $admin] = ibla_seed();
    // Dedup-producing header: repeat the block-1 barcode columns so dedupe emits
    // "* (2)" keys the importer maps as block 2 (RAS Box 2's chain).
    $header = [
        'Series', 'Document Identifier', 'RAS Batch 1', 'RAS Box 1', 'RAS Batch 2', 'RAS Box 2',
        'Barcode (IN)', 'Barcode RAS 1', 'Status 1', 'Barcode RAS 2', 'Status 2',
        // block-2 duplicates → "Barcode (IN) (2)", "Barcode RAS 1 (2)", "Status 1 (2)", "Barcode RAS 2 (2)", "Status 2 (2)"
        'Barcode (IN)', 'Barcode RAS 1', 'Status 1', 'Barcode RAS 2', 'Status 2',
    ];
    // block1 empty; block2 chain: BB1 (Barcode RAS 1 (2)) → BB2 (Barcode RAS 2 (2)) → BB3 (Barcode (IN) (2), IN)
    $row = ['REG', 'BC-2', '1', '1', '2', '7', '', '', '', '', '', 'BB3', 'BB1', 'Perm Out', 'BB2', 'Perm Out'];
    $file = ibla_write($header, [$row]);
    ibla_run($file, $admin);

    $b2 = Batch::withoutGlobalScopes()->where('batch_number', 2)->firstOrFail();
    $box7 = ibla_box($b2->id, '7'); // RAS Box 2 resolved from RAS Batch 2 + RAS Box 2
    $rows = BoxBarcodeHistory::withoutGlobalScopes()
        ->where('box_id', $box7->id)->where('source', BoxBarcodeHistory::SOURCE_LEGACY)
        ->orderBy('id')->get();

    $barcodes = $rows->flatMap(fn ($r) => [$r->previous_barcode, $r->new_barcode])->unique()->values()->all();
    expect($rows)->toHaveCount(2)
        ->and($barcodes)->toContain('BB1')   // the block-2 "Barcode RAS 1 (2)" value — the #212 regression
        ->and($barcodes)->toContain('BB2')
        ->and($barcodes)->toContain('BB3')
        ->and($box7->fresh()->barcode_status)->toBe('IN');
});

test('command sets a PERM_OUT box terminal to PERM_OUT and an IN box terminal to IN (not IN-by-default)', function () {
    [$repo, $admin] = ibla_seed();
    $file = ibla_std([
        // PERM_OUT box: last past barcode PERM_OUT, no Barcode (IN).
        ['Series' => 'REG', 'Document Identifier' => 'T-PO', 'RAS Batch 1' => '1', 'RAS Box 1' => '10',
            'Barcode RAS 1' => 'PO1', 'Status 1' => 'Perm Out'],
        // IN box: carries a Barcode (IN).
        ['Series' => 'REG', 'Document Identifier' => 'T-IN', 'RAS Batch 1' => '1', 'RAS Box 1' => '11',
            'Barcode RAS 1' => 'IN0', 'Status 1' => 'In', 'Barcode (IN)' => 'IN1'],
    ]);
    ibla_run($file, $admin);

    $b1 = Batch::withoutGlobalScopes()->where('batch_number', 1)->firstOrFail();
    expect(ibla_box($b1->id, '10')->barcode_status)->toBe('PERM_OUT')
        ->and(ibla_box($b1->id, '11')->barcode_status)->toBe('IN');
});

test('command writes NO barcode history for an In-Situ box', function () {
    [$repo, $admin] = ibla_seed();
    $file = ibla_std([[
        'Series' => 'REG', 'Document Identifier' => 'IS-1',
        'RAS Batch 1' => '1', 'RAS Box 1' => '1', 'In Situ Box 1' => 'NRA 3',
    ]]);
    ibla_run($file, $admin);

    $inSitu = Box::withoutGlobalScopes()->where('box_type', 'NRA')->where('box_number', '3')->firstOrFail();
    expect(BoxBarcodeHistory::withoutGlobalScopes()->where('box_id', $inSitu->id)->count())->toBe(0);
});

// ════════════════════════════════════════════════════════════════════════
//  Pivots + identifier history
// ════════════════════════════════════════════════════════════════════════

test('command writes the accession_batch pivot when a row names both', function () {
    [$repo, $admin] = ibla_seed();
    $file = ibla_std([[
        'Series' => 'REG', 'Document Identifier' => 'AB-1',
        'RAS Batch 1' => '1', 'Accession' => 'Grima Accession',
    ]]);
    ibla_run($file, $admin);

    $b1 = Batch::withoutGlobalScopes()->where('batch_number', 1)->firstOrFail();
    $acc = Accession::withoutGlobalScopes()->where('code', 'Grima Accession')->firstOrFail();
    expect($acc->batches()->where('batches.id', $b1->id)->exists())->toBeTrue();
});

test('command links a single authority by R-code (document_authority, primary)', function () {
    [$repo, $admin] = ibla_seed();
    $a = Authority::create(['identifier' => 'R500', 'surname' => 'Abela', 'entity_type' => 'PERSON']);
    $file = ibla_std([['Series' => 'REG', 'Document Identifier' => 'AU-1', 'Authority Identifier' => 'R500']]);
    ibla_run($file, $admin);

    expect(ibla_doc('AU-1')->authorities()->where('authorities.id', $a->id)->wherePivot('is_primary', true)->exists())->toBeTrue();
});

test('command links MULTIPLE authorities from a ";"-separated cell (first primary)', function () {
    [$repo, $admin] = ibla_seed();
    $a1 = Authority::create(['identifier' => 'R501', 'surname' => 'Borg', 'entity_type' => 'PERSON']);
    $a2 = Authority::create(['identifier' => 'R502', 'surname' => 'Cauchi', 'entity_type' => 'PERSON']);
    $file = ibla_std([['Series' => 'REG', 'Document Identifier' => 'AU-2', 'Authority Identifier' => 'R501; R502']]);
    ibla_run($file, $admin);

    $doc = ibla_doc('AU-2');
    expect($doc->authorities()->where('authorities.id', $a1->id)->wherePivot('is_primary', true)->exists())->toBeTrue()
        ->and($doc->authorities()->where('authorities.id', $a2->id)->wherePivot('is_primary', false)->exists())->toBeTrue();
});

test('command resolves an authority by its ALTERNATE key', function () {
    [$repo, $admin] = ibla_seed();
    $a = Authority::create(['identifier' => 'R999', 'alternative_identifier' => '997', 'surname' => 'Xuereb', 'entity_type' => 'PERSON']);
    $file = ibla_std([['Series' => 'REG', 'Document Identifier' => 'AU-3', 'Authority Identifier' => '997']]);
    ibla_run($file, $admin);

    expect(ibla_doc('AU-3')->authorities()->where('authorities.id', $a->id)->exists())->toBeTrue();
});

test('command records document_identifier_history from the Prev Attributed columns', function () {
    [$repo, $admin] = ibla_seed();
    $file = ibla_std([[
        'Series' => 'REG', 'Document Identifier' => 'PA-1',
        'Prev Attributed Identifier' => 'OLD-9', 'Prev Attributed Volume' => 'V7',
    ]]);
    ibla_run($file, $admin);

    $h = DocumentIdentifierHistory::withoutGlobalScopes()
        ->where('document_id', ibla_doc('PA-1')->id)->where('previous_identifier', 'OLD-9')->first();
    expect($h)->not->toBeNull()->and($h->previous_volume)->toBe('V7');
});

// ════════════════════════════════════════════════════════════════════════
//  Soft FKs, location, temp id, free-text, custom fields
// ════════════════════════════════════════════════════════════════════════

test('command soft-links document_type_id when the Type resolves, keeping the text otherwise', function () {
    [$repo, $admin] = ibla_seed();
    $dt = DocumentType::create(['identifier' => 'DEED', 'name' => 'Deed of Sale', 'is_active' => true]);
    $file = ibla_std([
        ['Series' => 'REG', 'Document Identifier' => 'DT-1', 'Document Type' => 'Deed of Sale'],
        ['Series' => 'REG', 'Document Identifier' => 'DT-2', 'Document Type' => 'Totally Unknown Type'],
    ]);
    ibla_run($file, $admin);

    expect(ibla_doc('DT-1')->document_type_id)->toBe($dt->id)
        ->and(ibla_doc('DT-2')->document_type_id)->toBeNull()
        ->and(ibla_doc('DT-2')->document_type)->toBe('Totally Unknown Type');
});

test('command soft-links practice_id when the Practice resolves', function () {
    [$repo, $admin] = ibla_seed();
    $p = Practice::create(['name' => 'Private Practice', 'identifier' => 'PP-1', 'repository_id' => $repo->id, 'is_active' => true]);
    $file = ibla_std([['Series' => 'REG', 'Document Identifier' => 'PR-1', 'Practice' => 'Private Practice']]);
    ibla_run($file, $admin);

    expect(ibla_doc('PR-1')->practice_id)->toBe($p->id);
});

test('command resolves a document Location by its code', function () {
    [$repo, $admin] = ibla_seed();
    $loc = Location::create(['name' => 'Shelf A3', 'code' => 'SHELF-A3', 'type' => 'shelf', 'repository_id' => $repo->id, 'is_active' => true]);
    $file = ibla_std([['Series' => 'REG', 'Document Identifier' => 'LO-1', 'Location' => 'SHELF-A3']]);
    ibla_run($file, $admin);

    expect(ibla_doc('LO-1')->location_id)->toBe($loc->id);
});

test('command enforces Temporary Identifier uniqueness — a duplicate fails, others still import', function () {
    [$repo, $admin] = ibla_seed();
    $file = ibla_std([
        ['Series' => 'REG', 'Document Identifier' => 'TI-1', 'Temporary Identifier' => 'DUP'],
        ['Series' => 'REG', 'Document Identifier' => 'TI-2', 'Temporary Identifier' => 'DUP'],   // clash → fails
        ['Series' => 'REG', 'Document Identifier' => 'TI-3', 'Temporary Identifier' => 'UNIQUE'], // fine
    ]);
    ibla_run($file, $admin);

    expect(ibla_doc('TI-1'))->not->toBeNull()
        ->and(ibla_doc('TI-2'))->toBeNull()       // the clashing row failed
        ->and(ibla_doc('TI-3'))->not->toBeNull(); // an unrelated row still imported
});

test('command stores Citation Reference and Tracking Note verbatim', function () {
    [$repo, $admin] = ibla_seed();
    $file = ibla_std([[
        'Series' => 'REG', 'Document Identifier' => 'CT-1',
        'Citation Reference' => 'See vol 3 p.12', 'Tracking Note' => 'Moved 2024',
    ]]);
    ibla_run($file, $admin);

    $doc = ibla_doc('CT-1');
    expect($doc->citation_reference)->toBe('See vol 3 p.12')
        ->and($doc->tracking)->toBe('Moved 2024');
});

test('command writes a custom-field value when a definition exists for the repository', function () {
    [$repo, $admin] = ibla_seed();
    $def = CustomFieldDefinition::create([
        'repository_id' => $repo->id, 'entity_type' => 'document',
        'key' => 'shelf_ref', 'label' => 'Shelf Ref', 'type' => 'text',
        'is_required' => false, 'is_active' => true, 'sort_order' => 0,
    ]);
    $header = [...IBLA_HEADER, 'Shelf Ref'];
    $r = array_fill_keys($header, '');
    $r['Series'] = 'REG';
    $r['Document Identifier'] = 'CF-1';
    $r['Shelf Ref'] = 'S-42';
    $file = ibla_write($header, [array_values($r)]);
    ibla_run($file, $admin);

    $doc = ibla_doc('CF-1');
    expect($doc)->not->toBeNull();
    $stored = $doc->customFieldValues()->where('custom_field_definition_id', $def->id)->first();
    expect($stored)->not->toBeNull()->and($stored->value)->toBe('S-42');
});

// ════════════════════════════════════════════════════════════════════════
//  Alignment-specific behaviours
// ════════════════════════════════════════════════════════════════════════

test('command: torre filled → true, blank → false (ignoreBlankState, no null/failure)', function () {
    [$repo, $admin] = ibla_seed();
    $file = ibla_std([
        ['Series' => 'REG', 'Document Identifier' => 'TO-1', 'Torre' => 'Torre'],
        ['Series' => 'REG', 'Document Identifier' => 'TO-2', 'Torre' => ''],
    ]);
    ibla_run($file, $admin);

    expect((bool) ibla_doc('TO-1')->torre)->toBeTrue()
        ->and((bool) ibla_doc('TO-2')->torre)->toBeFalse();
});

test('command DEGRADES a reserved batch (34) — imports with null batch_id + note, not failed', function () {
    [$repo, $admin] = ibla_seed();
    $file = ibla_std([['Series' => 'REG', 'Document Identifier' => 'RB-1', 'RAS Batch 1' => '34']]);
    ibla_run($file, $admin);

    $doc = ibla_doc('RB-1');
    expect($doc)->not->toBeNull()
        ->and($doc->batch_id)->toBeNull()
        ->and($doc->ras_batch_1)->toBe('34')
        ->and($doc->extra['batch_import_note'] ?? null)->toBe('reserved batch 34 — not linked')
        ->and(Batch::withoutGlobalScopes()->where('batch_number', 34)->exists())->toBeFalse();
});

test('command bootstraps Series — the literal "Unknown" and a brand-new code are firstOrCreate\'d', function () {
    [$repo, $admin] = ibla_seed();
    expect(Series::where('code', 'Unknown')->exists())->toBeFalse()
        ->and(Series::where('code', 'BRANDNEW')->exists())->toBeFalse();

    $file = ibla_std([
        ['Series' => 'Unknown', 'Document Identifier' => 'SB-1'],
        ['Series' => 'BRANDNEW', 'Document Identifier' => 'SB-2'],
    ]);
    ibla_run($file, $admin);

    $unknown = Series::where('code', 'Unknown')->first();
    $brand = Series::where('code', 'BRANDNEW')->first();
    expect($unknown)->not->toBeNull()->and($brand)->not->toBeNull()
        ->and(ibla_doc('SB-1')->series_id)->toBe($unknown->id)
        ->and(ibla_doc('SB-2')->series_id)->toBe($brand->id);
});

test('command normalises Excel float artefacts ("607.0" → "607") on every cell', function () {
    [$repo, $admin] = ibla_seed();
    $file = ibla_std([[
        'Series' => 'REG', 'Document Identifier' => 'FL-1',
        'Volume' => '607.0', 'RAS Batch 1' => '1', 'RAS Box 1' => '9.0',
    ]]);
    ibla_run($file, $admin);

    $b1 = Batch::withoutGlobalScopes()->where('batch_number', 1)->firstOrFail();
    expect(ibla_doc('FL-1')->volume_number)->toBe('607')
        ->and(Box::withoutGlobalScopes()->where('batch_id', $b1->id)->where('box_number', '9')->exists())->toBeTrue();
});

test('command applies Seal Number to the box only when the box had none', function () {
    [$repo, $admin] = ibla_seed();
    // Pre-existing box already carrying a seal — must NOT be overwritten.
    $b1 = Batch::withoutGlobalScopes()->create(['batch_number' => 1, 'type' => 'MAIN_COLLECTION', 'repository_id' => $repo->id, 'is_active' => true]);
    Box::withoutGlobalScopes()->create(['box_type' => 'RAS', 'box_number' => '20', 'batch_id' => $b1->id, 'seal_number' => 'KEEP-ME']);

    $file = ibla_std([
        ['Series' => 'REG', 'Document Identifier' => 'SN-NEW', 'RAS Batch 1' => '1', 'RAS Box 1' => '21', 'Seal Number' => 'SEAL-NEW'],
        ['Series' => 'REG', 'Document Identifier' => 'SN-KEEP', 'RAS Batch 1' => '1', 'RAS Box 1' => '20', 'Seal Number' => 'SEAL-IGNORED'],
    ]);
    ibla_run($file, $admin);

    expect(ibla_box($b1->id, '21')->seal_number)->toBe('SEAL-NEW')   // fresh box → set
        ->and(ibla_box($b1->id, '20')->seal_number)->toBe('KEEP-ME'); // existing seal → untouched
});

test('command maps "Current Box" label → current_box_type and "Type" → extra.accession_type', function () {
    [$repo, $admin] = ibla_seed();
    $file = ibla_std([[
        'Series' => 'REG', 'Document Identifier' => 'NC-1',
        'Current Box' => 'RAS', 'Type' => 'Notary',
    ]]);
    ibla_run($file, $admin);

    $doc = ibla_doc('NC-1');
    expect($doc->current_box_type)->toBe('RAS Box')                  // 'RAS' normalised to the canonical label
        ->and($doc->extra['accession_type'] ?? null)->toBe('Notary'); // NOT mapped to document_type
});

test('command stamps repository_id from the --user default / --repo with no HTTP session', function () {
    [$repo, $admin] = ibla_seed();
    $file = ibla_std([['Series' => 'REG', 'Document Identifier' => 'RP-1']]);
    ibla_run($file, $admin);

    expect(ibla_doc('RP-1')->repository_id)->toBe($repo->id)
        ->and(auth()->check())->toBeFalse(); // guards forgotten after the run
});

// ════════════════════════════════════════════════════════════════════════
//  Command-level behaviours (truncate, idempotency, failure, dry-run, limit)
// ════════════════════════════════════════════════════════════════════════

test('command --truncate-data (via --force) empties documents/boxes but LEAVES authorities + series intact', function () {
    [$repo, $admin] = ibla_seed();

    // Seed data that a truncate run must wipe (a stray document/box) AND master
    // data it must preserve (an authority + a series).
    $keepAuth = Authority::create(['identifier' => 'R-KEEP', 'surname' => 'Keep', 'entity_type' => 'PERSON']);
    $keepSeries = Series::firstOrCreate(['code' => 'KEEP'], ['title' => 'Keep', 'is_active' => true, 'repository_id' => $repo->id]);
    $strayBatch = Batch::withoutGlobalScopes()->create(['batch_number' => 90, 'type' => 'NOTARY_ACCESSION', 'repository_id' => $repo->id, 'is_active' => true]);
    $strayBox = Box::withoutGlobalScopes()->create(['box_type' => 'RAS', 'box_number' => 'STRAY', 'batch_id' => $strayBatch->id]);

    $file = ibla_std([['Series' => 'REG', 'Document Identifier' => 'TR-1', 'RAS Batch 1' => '1', 'RAS Box 1' => '1']]);
    ibla_run($file, $admin, ['--truncate-data' => true, '--force' => true]);

    expect(Authority::where('identifier', 'R-KEEP')->exists())->toBeTrue()
        ->and(Series::where('code', 'KEEP')->exists())->toBeTrue()
        ->and(Box::withoutGlobalScopes()->where('box_number', 'STRAY')->exists())->toBeFalse() // wiped
        ->and(ibla_doc('TR-1'))->not->toBeNull();                                              // fresh import present
});

test('command is idempotent — running twice on the same file yields identical counts', function () {
    [$repo, $admin] = ibla_seed();
    $file = ibla_std([
        ['Series' => 'REG', 'Document Identifier' => 'ID-1', 'RAS Batch 1' => '1', 'RAS Box 1' => '1',
            'RAS Batch 2' => '2', 'RAS Box 2' => '5', 'Barcode RAS 1' => 'AA1', 'Status 1' => 'Perm Out', 'Barcode (IN)' => 'AA2'],
        ['Series' => 'REG', 'RAS Batch 1' => '1', 'RAS Box 1' => '3', 'Notes' => 'blank-id row'], // blank identifier
    ]);
    ibla_run($file, $admin);
    $docs = Document::withoutGlobalScopes()->count();
    $moves = BoxMovement::withoutGlobalScopes()->count();
    $hist = BoxBarcodeHistory::withoutGlobalScopes()->count();

    ibla_run($file, $admin); // delete-and-rebuild must converge

    expect(Document::withoutGlobalScopes()->count())->toBe($docs)
        ->and(BoxMovement::withoutGlobalScopes()->count())->toBe($moves)
        ->and(BoxBarcodeHistory::withoutGlobalScopes()->count())->toBe($hist);
});

test('command isolates a failed row (unknown Location) — others import, tx level restored', function () {
    [$repo, $admin] = ibla_seed();
    $baseTx = DB::transactionLevel();
    $file = ibla_std([
        ['Series' => 'REG', 'Document Identifier' => 'FR-1'],                              // fine
        ['Series' => 'REG', 'Document Identifier' => 'FR-2', 'Location' => 'NO-SUCH-CODE'], // unknown location → fails
        ['Series' => 'REG', 'Document Identifier' => 'FR-3'],                              // still imports
    ]);
    ibla_run($file, $admin);

    expect(ibla_doc('FR-1'))->not->toBeNull()
        ->and(ibla_doc('FR-2'))->toBeNull()          // the broken row failed
        ->and(ibla_doc('FR-3'))->not->toBeNull()      // a later row still imported
        ->and(DB::transactionLevel())->toBe($baseTx); // no leaked savepoint
});

test('command --dry-run persists nothing', function () {
    [$repo, $admin] = ibla_seed();
    $file = ibla_std([
        ['Series' => 'REG', 'Document Identifier' => 'DR-1'],
        ['Series' => 'REG', 'Document Identifier' => 'DR-2'],
    ]);
    ibla_run($file, $admin, ['--dry-run' => true, '--limit' => 5]);

    expect(Document::withoutGlobalScopes()->count())->toBe(0);
});

test('command --limit N imports exactly N data rows', function () {
    [$repo, $admin] = ibla_seed();
    $file = ibla_std([
        ['Series' => 'REG', 'Document Identifier' => 'LM-1'],
        ['Series' => 'REG', 'Document Identifier' => 'LM-2'],
        ['Series' => 'REG', 'Document Identifier' => 'LM-3'],
        ['Series' => 'REG', 'Document Identifier' => 'LM-4'],
    ]);
    ibla_run($file, $admin, ['--limit' => 2]);

    expect(Document::withoutGlobalScopes()->count())->toBe(2)
        ->and(ibla_doc('LM-1'))->not->toBeNull()
        ->and(ibla_doc('LM-3'))->toBeNull();
});

// ════════════════════════════════════════════════════════════════════════
//  Parity — the command path == the wizard (direct DocumentImporter) path
// ════════════════════════════════════════════════════════════════════════

test('parity: the command produces the SAME documents/movements/barcode-history as a direct DocumentImporter chunk', function () {
    [$repo, $admin] = ibla_seed();
    $this->actingAs($admin);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'REG', 'is_active' => true, 'repository_id' => $repo->id]);

    $assoc = [
        ['Series' => 'REG', 'Document Identifier' => 'PY-1', 'RAS Batch 1' => '1', 'RAS Box 1' => '1',
            'RAS Batch 2' => '2', 'RAS Box 2' => '5', 'Barcode RAS 1' => 'AA1', 'Status 1' => 'Perm Out', 'Barcode (IN)' => 'AA2'],
        ['Series' => 'REG', 'Document Identifier' => 'PY-2', 'RAS Batch 1' => '1', 'RAS Box 1' => '2', 'In Situ Box 1' => 'NRA 3'],
    ];

    // Normalised snapshot (by natural keys, not ids, so it survives a wipe).
    $snapshot = function () {
        $docs = Document::withoutGlobalScopes()->orderBy('identifier')->get()
            ->map(fn ($d) => [
                'identifier' => $d->identifier,
                'batch' => optional(Batch::withoutGlobalScopes()->find($d->batch_id))->batch_number,
                'box' => optional(Box::withoutGlobalScopes()->find($d->current_box_id))?->only(['box_type', 'box_number']),
            ])->values()->all();
        $moves = BoxMovement::withoutGlobalScopes()->get()
            ->map(function ($m) {
                $from = $m->from_box_id ? Box::withoutGlobalScopes()->find($m->from_box_id) : null;
                $to = Box::withoutGlobalScopes()->find($m->to_box_id);

                return [
                    'from' => $from?->only(['box_type', 'box_number']),
                    'to' => $to?->only(['box_type', 'box_number']),
                    'seq' => $m->sequence,
                ];
            })->sortBy(fn ($r) => json_encode($r))->values()->all();
        $hist = BoxBarcodeHistory::withoutGlobalScopes()->get()
            ->map(function ($h) {
                $box = Box::withoutGlobalScopes()->find($h->box_id);

                return [
                    'box' => $box?->only(['box_type', 'box_number']),
                    'prev' => $h->previous_barcode, 'new' => $h->new_barcode, 'status' => $h->new_status,
                ];
            })->sortBy(fn ($r) => json_encode($r))->values()->all();

        return ['docs' => $docs, 'moves' => $moves, 'hist' => $hist];
    };

    // (1) COMMAND path.
    $file = ibla_std($assoc);
    ibla_run($file, $admin);
    $viaCommand = $snapshot();

    // Wipe the data tables (leave master data) and replay via the DIRECT importer.
    foreach (['box_barcode_history', 'box_movements', 'box_seal_number_history', 'document_identifier_history', 'documents', 'boxes', 'batches', 'accessions'] as $t) {
        DB::table($t)->delete();
    }
    EntityResolver::flushMemo();
    // The command ran auth()->forgetGuards() in its finally — re-authenticate so
    // the direct replay resolves the repository the same way the wizard does.
    $this->actingAs($admin);

    // (2) DIRECT DocumentImporter path (the wizard's per-row invocation), with the
    // SAME deduped rows + absolute source-row key the wizard threads.
    $header = SpreadsheetHeaders::dedupe(IBLA_HEADER);
    $import = Import::query()->create([
        'file_name' => 'py.csv', 'file_path' => '/tmp/py.csv', 'importer' => DocumentImporter::class,
        'processed_rows' => 0, 'total_rows' => count($assoc), 'successful_rows' => 0, 'user_id' => $admin->id,
    ]);
    $columnMap = array_filter(ImportWizard::guessColumnMap(DocumentImporter::class, $header));
    foreach ($assoc as $i => $a) {
        $rowVals = array_fill_keys(IBLA_HEADER, '');
        foreach ($a as $k => $v) {
            $rowVals[$k] = $v;
        }
        $data = array_combine($header, array_values($rowVals));
        $data[SpreadsheetHeaders::SOURCE_ROW_KEY] = (string) ($i + 2);
        (new DocumentImporter($import, $columnMap, ['skip_duplicates' => false]))($data);
    }
    $viaImporter = $snapshot();

    expect($viaImporter)->toEqual($viaCommand);
});
