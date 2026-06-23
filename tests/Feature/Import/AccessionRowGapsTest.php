<?php

declare(strict_types=1);

use App\Filament\Imports\AccessionRowImporter;
use App\Models\Accession;
use App\Models\Authority;
use App\Models\Box;
use App\Models\Document;
use App\Models\Lookup\BatchType;
use App\Models\Repository;
use App\Models\Scopes\RepositoryScope;
use App\Models\Scopes\ThroughBatchRepositoryScope;
use App\Models\Series;
use App\Models\User;
use App\Support\BulkImport\EntityResolver;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

/**
 * NAF New-Accessions importer — the four gaps closed after the Sam Abela
 * accession sample. Each row of that sheet is a single document carrying its
 * full ancestor chain (Authority → Accession → Batch → Box). We drive the
 * AccessionRowImporter the same way production does and assert that:
 *
 *   A. the bare "Name" header feeds the Authority name;
 *   B. the "Status" header sets the Box's IN/OUT custody status
 *      (not box_type);
 *   D. the "Accession Date" header lands on the Accession;
 *   F. the bare "Identifier" header is the Authority R-code, not the
 *      document identifier.
 */
uses(RefreshDatabase::class);

function ar_seedRoles(): void
{
    foreach (['super_admin', 'admin', 'editor', 'viewer'] as $r) {
        Role::firstOrCreate(['name' => $r, 'guard_name' => 'web']);
    }
}

function ar_admin(int $repoId): User
{
    ar_seedRoles();
    /** @var User $u */
    $u = User::factory()->create([
        'email' => 'ar-admin+' . uniqid() . '@test.local',
        'is_active' => true,
        'default_repository_id' => $repoId,
    ]);
    $u->assignRole('super_admin');

    return $u;
}

/**
 * Drive the importer with an inline row keyed by ImportColumn names (the
 * identity column-map a confirmed wizard mapping produces).
 *
 * @param array<string, mixed> $data
 */
function ar_run(array $data, int $userId): void
{
    EntityResolver::flushMemo();
    /** @var Import $row */
    $row = Import::query()->create([
        'completed_at' => null,
        'file_name' => 'sam_abela.xlsx',
        'file_path' => '/tmp/sam_abela.xlsx',
        'importer' => AccessionRowImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => $userId,
    ]);

    $columnMap = array_combine(array_keys($data), array_keys($data));
    /** @var Importer $importer */
    $importer = new AccessionRowImporter($row, $columnMap, []);
    $importer($data);
}

test('Sam Abela accession row closes all four import gaps', function () {
    $repo = Repository::factory()->create(['code' => 'NAF']);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Register', 'is_active' => true]);
    // NOTARY_ACCESSION is seeded into batch_types by the lookup migration and is
    // one of the two values the batches.type enum accepts under sqlite.
    BatchType::query()->firstOrCreate(['code' => 'NOTARY_ACCESSION'], ['label' => 'Notary Accession', 'is_active' => true]);
    $admin = ar_admin($repo->id);
    $this->actingAs($admin);

    // One row from the "Batch list format" sheet, keyed by importer columns.
    ar_run([
        'authority_identifier' => 'R0042',          // gap F: bare "Identifier"
        'authority_name' => 'Sammut',               // gap A: bare "Name"
        'authority_surname' => 'Abela',
        'accession_number' => 'ACC-2024-001',
        'accession_title' => 'Sam Abela Notarial Acts',
        'accession_date' => '15/03/2024',           // gap D: European DD/MM/YYYY
        'batch_number' => '777',
        'accession_type' => 'NOTARY_ACCESSION',
        'repository' => 'NAF',
        'box_number' => '9',
        'box_barcode' => 'BC-SAMABELA-009',
        'box_barcode_status' => 'OUT',              // gap B: "Status" → box custody
        'box_type' => 'RAS',
        'document_type' => 'Will',
        'series' => 'REG',
    ], $admin->id);

    // The document persisted with its ancestor chain wired up.
    $doc = Document::withoutGlobalScope(RepositoryScope::class)->latest('id')->first();
    expect($doc)->not->toBeNull()
        ->and($doc->accession_id)->not->toBeNull()
        ->and($doc->current_box_id)->not->toBeNull();

    // Gap F — authority created from the bare "Identifier" R-code + name.
    $authority = Authority::withoutGlobalScope(RepositoryScope::class)
        ->where('identifier', 'R0042')->first();
    expect($authority)->not->toBeNull()
        // Gap A — the bare "Name" header fed the authority given name.
        ->and($authority->given_names)->toBe('Sammut')
        ->and($authority->surname)->toBe('Abela');

    // Gap D — the accession carries the parsed European date.
    $accession = Accession::withoutGlobalScope(RepositoryScope::class)
        ->find($doc->accession_id);
    expect($accession)->not->toBeNull()
        ->and((string) $accession->accession_date)->toContain('2024-03-15');

    // Gap B — the box's custody status is set from "Status", and box_type is
    // NOT polluted by that value.
    $box = Box::withoutGlobalScope(ThroughBatchRepositoryScope::class)
        ->find($doc->current_box_id);
    expect($box)->not->toBeNull()
        ->and($box->barcode_status)->toBe('OUT');
});

test('real Sam Abela row: Excel float R-number and datetime are normalised', function () {
    $repo = Repository::factory()->create(['code' => 'NRA']);
    Series::firstOrCreate(['code' => 'REG'], ['title' => 'Register', 'is_active' => true]);
    BatchType::query()->firstOrCreate(['code' => 'NOTARY_ACCESSION'], ['label' => 'Notary Accession', 'is_active' => true]);
    $admin = ar_admin($repo->id);
    $this->actingAs($admin);

    // Verbatim cell values from the "Batch list format" sheet (Excel numeric
    // cells surface as float artefacts; Accession Date is a full datetime).
    ar_run([
        'authority_identifier' => '642.0',          // R-number as Excel float
        'authority_name' => 'Vincenzo',
        'authority_surname' => 'Caruana',
        'accession_number' => '2026-001',
        'accession_title' => 'Notary Sam Abela Accession',
        'accession_date' => '2026-04-06 00:00:00',  // datetime, not DD/MM/YYYY
        'batch_number' => '46.0',
        'accession_type' => 'NOTARY_ACCESSION',
        'repository' => 'NRA',
        'box_number' => '1.0',
        'box_barcode' => 'AC54609',
        'box_barcode_status' => 'IN',
        'box_type' => 'RAS',
        'document_type' => 'Register Volume',
        'series' => 'REG',
    ], $admin->id);

    // R-number stored as '642', not '642.0'.
    expect(Authority::withoutGlobalScope(RepositoryScope::class)->where('identifier', '642')->exists())->toBeTrue()
        ->and(Authority::withoutGlobalScope(RepositoryScope::class)->where('identifier', '642.0')->exists())->toBeFalse();

    $doc = Document::withoutGlobalScope(RepositoryScope::class)->latest('id')->first();
    $accession = Accession::withoutGlobalScope(RepositoryScope::class)->find($doc->accession_id);
    expect((string) $accession->accession_date)->toContain('2026-04-06');
});
